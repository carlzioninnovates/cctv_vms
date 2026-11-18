<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EdgeAgent;
use App\Models\Site;
use App\Services\DataPlane\DataPlaneClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeAgentController extends Controller
{
    /** Installer: create an agent for a site and get a one-time code to type on the device. */
    public function store(Request $request, Site $site): JsonResponse
    {
        abort_unless($request->user()->canManage($site->organization), 403);
        $data = $request->validate(['name' => 'nullable|string|max:100']);

        $agent = $site->edgeAgents()->create(['name' => $data['name'] ?? null, 'status' => 'pending']);
        $code = $agent->issueEnrollmentCode();
        AuditLog::record('agent.created', $agent);

        return response()->json([
            'id' => $agent->id,
            'agent_uid' => $agent->agent_uid,
            'enrollment_code' => $code,
            'expires_at' => $agent->enrollment_expires_at->toIso8601String(),
            'install_command' => self::installCommand($code),
        ], 201);
    }

    /** New code for an agent that wasn't enrolled in time, or is being replaced (old cert revoked). */
    public function reissueCode(Request $request, EdgeAgent $agent, DataPlaneClient $dp): JsonResponse
    {
        abort_unless($request->user()->canManage($agent->site->organization), 403);
        if ($agent->enrolled_at) {
            $dp->revokeCertificate($agent->agent_uid)->throw();
            $agent->forceFill(['cert_fingerprint' => null, 'revoked_at' => now(), 'status' => 'pending'])->save();
        }
        $code = $agent->issueEnrollmentCode();
        AuditLog::record('agent.code_reissued', $agent);

        return response()->json([
            'enrollment_code' => $code,
            'expires_at' => $agent->enrollment_expires_at->toIso8601String(),
            'install_command' => self::installCommand($code),
        ]);
    }

    public function show(Request $request, EdgeAgent $agent): JsonResponse
    {
        abort_unless($request->user()->canView($agent->site->organization), 403);

        return response()->json($agent->only([
            'id', 'agent_uid', 'name', 'status', 'version', 'hostname', 'arch', 'last_heartbeat_at',
            'enrolled_at', 'cert_expires_at', 'uplink_active', 'buffered_events',
        ]));
    }

    /** Scan the site LAN (WS-Discovery) and mark devices that are already added. */
    public function discover(Request $request, EdgeAgent $agent, DataPlaneClient $dp): JsonResponse
    {
        abort_unless($request->user()->canManage($agent->site->organization), 403);
        abort_unless($agent->isOnline(), 409, 'The agent is offline.');

        $r = $dp->discover($agent->agent_uid);
        abort_if($r->status() === 504, 504, 'The agent did not answer. It may have just gone offline.');
        $r->throw();

        $known = $agent->site->nvrs()->pluck('id', 'lan_host');
        $devices = collect($r->json('devices', []))->map(fn ($d) => [
            'ip' => $d['ip'] ?? null,
            'brand_guess' => $d['brand_guess'] ?? 'onvif',
            'hardware' => $d['hardware'] ?? null,
            'name' => $d['name'] ?? null,
            'already_added_nvr_id' => isset($d['ip']) ? ($known[$d['ip']] ?? null) : null,
        ])->values();

        return response()->json(['devices' => $devices]);
    }

    /** One line the installer pastes on the agent device: installs, enrolls and starts the service. */
    public static function installCommand(string $code): string
    {
        return 'curl -fsSL '.route('agent.installer').' | sudo bash -s -- '.$code;
    }
}
