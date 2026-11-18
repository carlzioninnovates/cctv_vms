<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

/** LOCAL ONLY: the "Lab" site used by infra/dev/live_lab.py (simulated Hikvision NVR). */
class LabSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->environment('production'), 403);
        $reseller = Organization::where('slug', 'cctv-pro')->firstOrFail();
        $client = Organization::firstOrCreate(['slug' => 'lab'], ['type' => 'client', 'parent_id' => $reseller->id, 'name' => 'Lab']);
        $site = $client->sites()->firstOrCreate(['name' => 'Lab'], ['address' => 'localhost']);
        $agent = $site->edgeAgents()->firstOrCreate(['name' => 'Lab agent'], ['status' => 'online', 'agent_uid' => '00000000-0000-4000-8000-00000000a6e7',
            'hostname' => 'live_lab.py', 'version' => 'lab', 'enrolled_at' => now(), 'last_heartbeat_at' => now()]);
        $nvr = $site->nvrs()->firstOrCreate(['name' => 'Lab NVR simulator'], ['edge_agent_id' => $agent->id, 'brand' => 'hikvision',
            'lan_host' => '10.99.0.10', 'status' => 'active', 'model' => 'ffmpeg testsrc2', 'probed_at' => now(),
            'capabilities' => ['playback' => 'vendor_rtsp', 'event_stream' => 'none', 'hdd_health' => false, 'onvif' => false]]);
        $nvr->channels()->updateOrCreate(['number' => 1], ['name' => 'Test pattern', 'online' => true,
            'main_codec' => 'h265', 'sub_codec' => 'h264', 'default_view_mode' => 'sub']);
    }
}
