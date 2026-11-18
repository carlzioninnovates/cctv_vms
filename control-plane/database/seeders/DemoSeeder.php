<?php

namespace Database\Seeders;

use App\Enums\OfflineCause;
use App\Models\FirmwareAdvisory;
use App\Models\OfflineIncident;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEMO ONLY: php artisan db:seed --class=DemoSeeder
 * Login: demo@vms.test / demo-password-123
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->environment('production'), 403, 'DemoSeeder must not run in production');

        $platform = Organization::firstOrCreate(['slug' => 'platform'], ['type' => 'platform', 'name' => 'Zi-on VMS']);
        $reseller = Organization::firstOrCreate(['slug' => 'cctv-pro'], ['type' => 'reseller', 'parent_id' => $platform->id, 'name' => 'CCTV Pro Installers']);
        User::updateOrCreate(['email' => 'demo@vms.test'], ['name' => 'Juan dela Cruz', 'password' => 'demo-password-123',
            'organization_id' => $reseller->id, 'role' => 'installer']);

        // Client 1: one healthy site with a problem NVR, one site waiting for agent
        $reyes = Organization::firstOrCreate(['slug' => 'reyes-hardware'], ['type' => 'client', 'parent_id' => $reseller->id, 'name' => 'Reyes Hardware']);
        $main = $reyes->sites()->firstOrCreate(['name' => 'Main branch · Quezon City'], ['address' => 'Aurora Blvd, Cubao', 'isp' => 'PLDT', 'has_ups' => true]);
        $agent = $main->edgeAgents()->firstOrCreate(['name' => 'Site agent'], ['status' => 'online', 'enrolled_at' => now()->subDays(3),
            'hostname' => 'reyes-pi4', 'version' => '0.1.0', 'last_heartbeat_at' => now()->subSeconds(12)]);

        $adv = FirmwareAdvisory::updateOrCreate(['cve' => 'CVE-2021-33044'], ['brand' => 'dahua', 'severity' => 'critical',
            'fixed_in_build' => '2021-09-01', 'summary' => 'Authentication bypass during login.',
            'reference_url' => 'https://www.dahuasecurity.com/support/cybersecurity']);

        $nvr = $main->nvrs()->firstOrCreate(['lan_host' => '192.168.1.108'], [
            'edge_agent_id' => $agent->id, 'name' => 'Store floor NVR', 'brand' => 'dahua', 'status' => 'active',
            'model' => 'DHI-NVR4108HS-8P-4KS2', 'serial' => '7J0A1B2C3D4E5', 'firmware' => '4.001.0000000.3.R,build:2020-05-01',
            'clock_drift_seconds' => 2, 'probed_at' => now()->subHours(5), 'tunnel_ports' => ['80' => 40000, '554' => 40001],
            'capabilities' => ['onvif' => false, 'playback' => 'vendor_rtsp', 'event_stream' => 'vendor', 'hdd_health' => true],
            'probe_warnings' => ['ONVIF disabled or unreachable — PTZ via vendor API only',
                'main streams are H.265: fullscreen will use HLS unless transcoding is enabled'],
        ]);
        $nvr->advisories()->syncWithoutDetaching([$adv->id]);
        foreach ([[1, 'Entrance', 'h264'], [2, 'Cashier', 'h264'], [3, 'Stockroom', 'h264'], [4, 'Parking', 'none']] as [$n, $name, $sub]) {
            $nvr->channels()->updateOrCreate(['number' => $n], ['name' => $name, 'online' => $n !== 3, 'main_codec' => 'h265',
                'sub_codec' => $sub, 'default_view_mode' => $sub === 'none' ? 'snapshot' : 'sub', 'ptz' => $n === 4]);
        }
        $main->nvrs()->firstOrCreate(['lan_host' => '192.168.1.64'], [
            'edge_agent_id' => $agent->id, 'name' => 'Warehouse NVR', 'brand' => 'hikvision', 'status' => 'failed',
            'onboarding_error_code' => 'bad_credentials',
            'onboarding_error' => 'The NVR rejected the username or password. Fix them before retrying — most NVRs lock the account after a few wrong attempts.',
        ]);
        $main->nvrs()->firstOrCreate(['lan_host' => '192.168.1.70'], [
            'edge_agent_id' => $agent->id, 'name' => 'Office NVR', 'brand' => 'uniview', 'status' => 'onboarding',
        ]);

        $pasig = $reyes->sites()->firstOrCreate(['name' => 'Warehouse · Pasig'], ['isp' => 'Converge']);
        $pasig->edgeAgents()->firstOrCreate(['name' => 'Site agent'], ['status' => 'pending'])->issueEnrollmentCode();

        // Client 2: site down from a brownout
        $santos = Organization::firstOrCreate(['slug' => 'santos-bakery'], ['type' => 'client', 'parent_id' => $reseller->id, 'name' => 'Santos Bakery']);
        $bakery = $santos->sites()->firstOrCreate(['name' => 'Bakery · Marikina'], ['isp' => 'Globe', 'has_ups' => true]);
        $bakery->edgeAgents()->firstOrCreate(['name' => 'Site agent'], ['status' => 'offline', 'enrolled_at' => now()->subWeek(),
            'hostname' => 'santos-nuc', 'version' => '0.1.0', 'last_heartbeat_at' => now()->subMinutes(38)]);
        OfflineIncident::firstOrCreate(['site_id' => $bakery->id, 'ended_at' => null],
            ['cause' => OfflineCause::PowerLoss, 'started_at' => now()->subMinutes(38)]);
    }
}
