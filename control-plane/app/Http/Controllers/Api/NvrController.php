<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceBrand;
use App\Http\Controllers\Controller;
use App\Jobs\OnboardNvr;
use App\Models\AuditLog;
use App\Models\Nvr;
use App\Models\Site;
use App\Services\DataPlane\DataPlaneClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NvrController extends Controller
{
    private const DEFAULT_PORTS = [
        // brand => [http, rtsp, onvif]. Dahua ONVIF shares port 80; UNV too.
        'hikvision' => [80, 554, 80],
        'dahua' => [80, 554, 80],
        'uniview' => [80, 554, 80],
        'onvif' => [80, 554, 80],
    ];

    /** Add an NVR. Returns 202; poll GET /api/nvrs/{id} until status is active or failed. */
    public function store(Request $request, Site $site, DataPlaneClient $dp): JsonResponse
    {
        abort_unless($request->user()->canManage($site->organization), 403);
        $data = $request->validate([
            'edge_agent_id' => ['required', Rule::exists('edge_agents', 'id')->where('site_id', $site->id)],
            'name' => 'required|string|max:100',
            'brand' => ['required', Rule::enum(DeviceBrand::class)],
            'lan_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:]+$/'],
            'http_port' => 'nullable|integer|between:1,65535',
            'rtsp_port' => 'nullable|integer|between:1,65535',
            'onvif_port' => 'nullable|integer|between:1,65535',
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:128',
            'owner_consent' => 'accepted',
        ], [
            'owner_consent.accepted' => 'Confirm that the owner has authorized cloud access to this NVR.',
        ]);
        abort_if($site->nvrs()->where('lan_host', $data['lan_host'])->exists(), 409,
            'An NVR with this IP is already added to this site.');

        [$http, $rtsp, $onvif] = self::DEFAULT_PORTS[$data['brand']];
        $ref = $dp->storeCredentials($data['username'], $data['password'])->throw()->json('credential_ref');

        $nvr = $site->nvrs()->create([
            'edge_agent_id' => $data['edge_agent_id'],
            'name' => $data['name'],
            'brand' => $data['brand'],
            'lan_host' => $data['lan_host'],
            'http_port' => $data['http_port'] ?? $http,
            'rtsp_port' => $data['rtsp_port'] ?? $rtsp,
            'onvif_port' => $data['onvif_port'] ?? $onvif,
            'credential_ref' => $ref,
            'owner_consent_at' => now(),
            'owner_consent_by' => $request->user()->id,
            'status' => 'onboarding',
        ]);
        AuditLog::record('nvr.created', $nvr, ['brand' => $data['brand'], 'lan_host' => $data['lan_host'], 'owner_consent' => true]);
        OnboardNvr::dispatch($nvr);

        return response()->json($this->present($nvr->fresh()), 202);
    }

    public function show(Request $request, Nvr $nvr): JsonResponse
    {
        abort_unless($request->user()->canView($nvr->site->organization), 403);

        return response()->json($this->present($nvr));
    }

    /** Retry after fixing the problem (IP, RTSP enabled, agent back online...). */
    public function retry(Request $request, Nvr $nvr): JsonResponse
    {
        abort_unless($request->user()->canManage($nvr->site->organization), 403);
        abort_if($nvr->status === 'onboarding', 409, 'Onboarding is already running.');
        abort_if($nvr->onboarding_error_code === 'bad_credentials', 422,
            'Update the username/password first; retrying the same password can lock the NVR account.');
        $nvr->forceFill(['status' => 'onboarding', 'onboarding_stage' => null, 'onboarding_error' => null, 'onboarding_error_code' => null])->save();
        OnboardNvr::dispatch($nvr);

        return response()->json($this->present($nvr->fresh()), 202);
    }

    public function updateCredentials(Request $request, Nvr $nvr, DataPlaneClient $dp): JsonResponse
    {
        abort_unless($request->user()->canManage($nvr->site->organization), 403);
        $data = $request->validate(['username' => 'required|string|max:64', 'password' => 'required|string|max:128']);

        $old = $nvr->credential_ref;
        $ref = $dp->storeCredentials($data['username'], $data['password'])->throw()->json('credential_ref');
        $nvr->forceFill(['credential_ref' => $ref, 'status' => 'onboarding', 'onboarding_stage' => null,
            'onboarding_error' => null, 'onboarding_error_code' => null])->save();
        if ($old) {
            $dp->deleteCredentials($old);   // best effort
        }
        AuditLog::record('nvr.credentials_changed', $nvr);
        OnboardNvr::dispatch($nvr);

        return response()->json($this->present($nvr->fresh()), 202);
    }

    private function present(Nvr $nvr): array
    {
        $nvr->loadMissing(['channels', 'advisories', 'site']);

        return [
            'id' => $nvr->id,
            'site_id' => $nvr->site_id,
            'site_name' => $nvr->site?->name,
            'edge_agent_id' => $nvr->edge_agent_id,
            'name' => $nvr->name,
            'brand' => $nvr->brand->value,
            'lan_host' => $nvr->lan_host,
            'ports' => ['http' => $nvr->http_port, 'rtsp' => $nvr->rtsp_port, 'onvif' => $nvr->onvif_port],
            'status' => $nvr->status,
            'stage' => $nvr->onboarding_stage,
            'owner_consent_at' => $nvr->owner_consent_at?->toIso8601String(),
            'error' => $nvr->onboarding_error ? ['code' => $nvr->onboarding_error_code, 'message' => $nvr->onboarding_error] : null,
            'model' => $nvr->model,
            'serial' => $nvr->serial,
            'firmware' => $nvr->firmware,
            'firmware_region' => $nvr->firmware_region,
            'clock_drift_seconds' => $nvr->clock_drift_seconds,
            'warnings' => $nvr->probe_warnings ?? [],
            'features' => $nvr->capabilities ? [
                'onvif' => $nvr->capabilities['onvif'] ?? false,
                'playback' => ($nvr->capabilities['playback'] ?? 'none') !== 'none',
                'events' => ($nvr->capabilities['event_stream'] ?? 'none') !== 'none',
                'hdd_health' => $nvr->capabilities['hdd_health'] ?? false,
            ] : null,
            'channels' => $nvr->channels->map(fn ($c) => $c->only(['id', 'number', 'name', 'online', 'enabled',
                'main_codec', 'sub_codec', 'ptz', 'default_view_mode']))->values(),
            'advisories' => $nvr->advisories->map(fn ($a) => $a->only(['cve', 'severity', 'summary', 'reference_url']))->values(),
            'probed_at' => $nvr->probed_at?->toIso8601String(),
        ];
    }
}
