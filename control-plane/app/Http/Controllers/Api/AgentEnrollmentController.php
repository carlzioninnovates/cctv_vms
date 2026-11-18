<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EdgeAgent;
use App\Services\DataPlane\DataPlaneClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public endpoint the agent calls once with the installer's code. Rate-limited in routes/api.php. */
class AgentEnrollmentController extends Controller
{
    public function __invoke(Request $request, DataPlaneClient $dp): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/'],
            'csr_pem' => 'required|string|max:8192|starts_with:-----BEGIN CERTIFICATE REQUEST-----',
            'hostname' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:32',
            'arch' => 'nullable|string|max:16',
        ]);

        // Lock the row so two devices can't redeem the same code at once
        return DB::transaction(function () use ($data, $dp) {
            $agent = EdgeAgent::where('enrollment_code_hash', EdgeAgent::hashCode($data['code']))
                ->lockForUpdate()->first();
            if (! $agent || $agent->enrollment_expires_at?->isPast()) {
                return response()->json(['message' => 'This enrollment code is invalid or has expired. '
                    .'Create a new one in the dashboard.'], 422);
            }

            $r = $dp->issueCertificate($agent->agent_uid, $data['csr_pem']);
            if ($r->status() === 422) {
                return response()->json(['message' => 'The agent sent an invalid certificate request.'], 422);
            }
            $r->throw();

            $agent->forceFill([
                'enrollment_code_hash' => null,          // one-time
                'enrollment_expires_at' => null,
                'enrolled_at' => now(),
                'revoked_at' => null,
                'cert_fingerprint' => $r->json('fingerprint'),
                'cert_expires_at' => $r->json('expires_at'),
                'hostname' => $data['hostname'] ?? null,
                'version' => $data['version'] ?? null,
                'arch' => $data['arch'] ?? null,
                'status' => 'enrolled',
            ])->save();
            AuditLog::record('agent.enrolled', $agent, ['hostname' => $data['hostname'] ?? null]);

            return response()->json([
                'agent_uid' => $agent->agent_uid,
                'cert_pem' => $r->json('cert_pem'),
                'ca_pem' => $r->json('ca_pem'),
                'agent_url' => config('services.data_plane.agent_url'),
                'frps' => $r->json('frps'),
            ]);
        });
    }
}
