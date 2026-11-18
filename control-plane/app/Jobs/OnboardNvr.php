<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Nvr;
use App\Services\DataPlane\DataPlaneClient;
use App\Services\Onboarding\FirmwareMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Open tunnel -> probe -> save what the device can do. Only HTTP calls to the data plane run
 * here; all device I/O happens in Python. Runs once (tries = 1): retrying a probe with a wrong
 * password is how NVR accounts get locked.
 */
class OnboardNvr implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Nvr $nvr) {}

    public function uniqueId(): string
    {
        return (string) $this->nvr->id;
    }

    public function handle(DataPlaneClient $dp, FirmwareMatcher $matcher): void
    {
        $nvr = $this->nvr = $this->nvr->fresh(['edgeAgent', 'site']);   // one instance, or stage updates get lost
        $agent = $nvr->edgeAgent;
        if (! $agent || ! $agent->isOnline()) {
            $this->fail_('agent_offline', 'The site agent is offline. Check its power and internet, then retry.');

            return;
        }

        // 1. Tunnel
        $this->stage('tunnel');
        $ports = [['port' => $nvr->http_port, 'kind' => 'http'], ['port' => $nvr->rtsp_port, 'kind' => 'rtsp']];
        if ($nvr->onvif_port !== $nvr->http_port) {
            $ports[] = ['port' => $nvr->onvif_port, 'kind' => 'onvif'];
        }
        $exp = $dp->expose($agent->agent_uid, $nvr->lan_host, $ports);
        if ($exp->failed()) {
            $this->fail_('tunnel_failed', 'Could not open a tunnel to the NVR ('.$exp->status().'). Retry in a minute.');

            return;
        }
        $reach = $exp->json('reachable', []);
        if (! ($reach["{$nvr->lan_host}:{$nvr->http_port}"] ?? false)) {
            $this->fail_('lan_unreachable', "The agent can't reach {$nvr->lan_host}:{$nvr->http_port}. "
                .'Check the NVR IP address and that it is on the same network as the agent.');

            return;
        }
        $warnings = [];
        if (! ($reach["{$nvr->lan_host}:{$nvr->rtsp_port}"] ?? false)) {
            $warnings[] = "RTSP port {$nvr->rtsp_port} is closed on the NVR — live view won't work until RTSP is enabled.";
        }
        $nvr->forceFill(['tunnel_ports' => $exp->json('ports')])->save();

        // 2. Probe
        $this->stage('probe');
        $probe = $dp->probe($nvr->id, [
            'brand' => $nvr->brand->value,
            'credential_ref' => $nvr->credential_ref,
            'agent_id' => $agent->agent_uid,
            'lan_host' => $nvr->lan_host,
            'http_port' => $nvr->http_port,
            'rtsp_port' => $nvr->rtsp_port,
            'onvif_port' => $nvr->onvif_port,
            'device_tz' => $nvr->site->timezone,
        ]);
        if ($probe->status() === 401) {
            $this->fail_('bad_credentials', 'The NVR rejected the username or password. Fix them before retrying — '
                .'most NVRs lock the account after a few wrong attempts.');

            return;
        }
        if ($probe->failed()) {
            $this->fail_('probe_failed', 'The NVR did not answer as a '.ucfirst($nvr->brand->value)
                .' device ('.$probe->status().'). Check the brand and HTTP port.');

            return;
        }

        // 3. Save
        $this->stage('save');
        $caps = $probe->json();
        DB::transaction(function () use ($nvr, $caps, $warnings, $matcher) {
            $nvr->forceFill([
                'model' => $caps['model'] ?? null,
                'serial' => $caps['serial'] ?? null,
                'firmware' => $caps['firmware'] ?? null,
                'firmware_region' => $caps['firmware_region'] ?? 'unknown',
                'clock_drift_seconds' => isset($caps['clock_drift_seconds']) ? (int) round($caps['clock_drift_seconds']) : null,
                'capabilities' => $caps,
                'probe_warnings' => array_values(array_merge($warnings, $caps['warnings'] ?? [])),
                'probed_at' => now(),
                'status' => 'active',
                'onboarding_stage' => null,
                'onboarding_error' => null,
                'onboarding_error_code' => null,
                'last_seen_at' => now(),
            ])->save();

            $seen = [];
            foreach ($caps['channels'] ?? [] as $ch) {
                $seen[] = (int) $ch['number'];
                $sub = $ch['sub_codec'] ?? 'unknown';
                $nvr->channels()->updateOrCreate(['number' => (int) $ch['number']], [
                    'name' => $ch['name'] ?? null,
                    'online' => (bool) ($ch['online'] ?? true),
                    'main_codec' => $ch['main_codec'] ?? 'unknown',
                    'sub_codec' => $sub,
                    'ptz' => (bool) ($ch['ptz'] ?? false),
                    'onvif_profile_token' => $ch['onvif_profile_token'] ?? null,
                    // No usable substream: default the grid to snapshots so we don't pull main streams
                    'default_view_mode' => in_array($sub, ['h264', 'h265'], true) ? 'sub' : 'snapshot',
                ]);
            }
            // Channels the NVR no longer reports are disabled, not deleted (keeps event history)
            $nvr->channels()->whereNotIn('number', $seen)->update(['enabled' => false, 'online' => false]);

            $nvr->advisories()->syncWithoutDetaching($matcher->match($nvr));
        });

        AuditLog::record('nvr.onboarded', $nvr, ['channels' => count($caps['channels'] ?? [])]);
    }

    private function stage(string $stage): void
    {
        $this->nvr->forceFill(['onboarding_stage' => $stage])->save();
    }

    private function fail_(string $code, string $message): void
    {
        $this->nvr->forceFill([
            'status' => 'failed',
            'onboarding_stage' => null,
            'onboarding_error_code' => $code,
            'onboarding_error' => $message,
        ])->save();
        AuditLog::record('nvr.onboarding_failed', $this->nvr, ['code' => $code]);
    }

    public function failed(?\Throwable $e): void
    {
        $this->fail_('internal_error', 'Onboarding failed unexpectedly. Retry, or contact support if it repeats.');
    }
}
