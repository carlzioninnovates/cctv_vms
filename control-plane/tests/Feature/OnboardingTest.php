<?php

namespace Tests\Feature;

use App\Console\Commands\SyncAgentStatus;
use App\Enums\OfflineCause;
use App\Models\AuditLog;
use App\Models\EdgeAgent;
use App\Models\FirmwareAdvisory;
use App\Models\Nvr;
use App\Models\OfflineIncident;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const DP = 'http://dp.test';
    private const SECRET = 'test-secret';

    private Organization $reseller;
    private Site $site;
    private User $installer;

    /** Read at call time so a test can change the device's answer mid-test. */
    private int $probeStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.data_plane.url' => self::DP, 'services.data_plane.secret' => self::SECRET,
            'services.data_plane.agent_url' => 'https://agent.test']);

        $platform = Organization::create(['type' => 'platform', 'name' => 'VMS', 'slug' => 'vms']);
        $this->reseller = Organization::create(['type' => 'reseller', 'parent_id' => $platform->id, 'name' => 'CCTV Pro', 'slug' => 'pro']);
        $client = Organization::create(['type' => 'client', 'parent_id' => $this->reseller->id, 'name' => 'Sari-sari', 'slug' => 'ss']);
        $this->site = $client->sites()->create(['name' => 'Main store']);
        $this->installer = User::factory()->create(['organization_id' => $this->reseller->id, 'role' => 'installer']);
        Sanctum::actingAs($this->installer);
    }

    private function onlineAgent(): EdgeAgent
    {
        return $this->site->edgeAgents()->create(['status' => 'online', 'enrolled_at' => now()]);
    }

    private function caps(array $over = []): array
    {
        return array_merge([
            'model' => 'DHI-NVR4108HS-8P-4KS2', 'serial' => '7J0ABC', 'firmware' => '4.001.0000000.3.R,build:2020-05-01',
            'firmware_region' => 'unknown', 'clock_drift_seconds' => 2.4, 'vendor_api' => true, 'onvif' => false,
            'event_stream' => 'vendor', 'playback' => 'vendor_rtsp', 'hdd_health' => true,
            'channels' => [
                ['number' => 1, 'name' => 'Gate', 'online' => true, 'main_codec' => 'h265', 'sub_codec' => 'h264', 'ptz' => false],
                ['number' => 2, 'name' => 'Cashier', 'online' => true, 'main_codec' => 'h265', 'sub_codec' => 'none', 'ptz' => false],
            ],
            'warnings' => ['main streams are H.265: fullscreen will use HLS unless transcoding is enabled'],
        ], $over);
    }

    private function fakeDataPlane(array $exposeReach = [80 => true, 554 => true], int $probeStatus = 200, array $caps = []): void
    {
        $this->probeStatus = $probeStatus;
        Http::fake([
            self::DP.'/internal/credentials' => fn () => Http::response(['credential_ref' => 'ref-'.bin2hex(random_bytes(6))]),
            self::DP.'/internal/credentials/*' => Http::response(null, 204),
            self::DP.'/internal/agents/*/expose' => fn (Request $r) => Http::response([
                'reachable' => collect($exposeReach)->mapWithKeys(fn ($ok, $p) => [$r['lan_host'].":{$p}" => $ok])->all(),
                'ports' => ['80' => 40000, '554' => 40001],
            ]),
            self::DP.'/internal/nvrs/*/probe' => fn () => $this->probeStatus === 200
                ? Http::response($this->caps($caps)) : Http::response(['detail' => 'x'], $this->probeStatus),
        ]);
    }

    private function addNvr(EdgeAgent $agent, array $over = [])
    {
        return $this->postJson("/api/sites/{$this->site->id}/nvrs", array_merge([
            'edge_agent_id' => $agent->id, 'name' => 'Store NVR', 'brand' => 'dahua',
            'lan_host' => '192.168.1.108', 'username' => 'admin', 'password' => 'Sup3r$ecret!',
            'owner_consent' => true,
        ], $over));
    }

    // ---------- agents ----------

    public function test_installer_creates_agent_and_gets_one_time_code(): void
    {
        $r = $this->postJson("/api/sites/{$this->site->id}/agents", ['name' => 'Back office PC'])->assertCreated();
        $code = $r->json('enrollment_code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
        $this->assertSame("curl -fsSL ".url("/install-agent.sh")." | sudo bash -s -- {$code}", $r->json('install_command'));
        $this->assertDatabaseMissing('edge_agents', ['enrollment_code_hash' => $code]);     // only the hash
        $this->assertDatabaseHas('edge_agents', ['enrollment_code_hash' => EdgeAgent::hashCode($code)]);
    }

    public function test_other_reseller_and_viewer_cannot_create_agents(): void
    {
        $other = Organization::create(['type' => 'reseller', 'name' => 'Rival', 'slug' => 'rival']);
        Sanctum::actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => 'owner']));
        $this->postJson("/api/sites/{$this->site->id}/agents")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->reseller->id, 'role' => 'viewer']));
        $this->postJson("/api/sites/{$this->site->id}/agents")->assertForbidden();
    }

    public function test_enrollment_issues_cert_once(): void
    {
        $code = $this->postJson("/api/sites/{$this->site->id}/agents")->json('enrollment_code');
        Http::fake([self::DP.'/internal/agents/*/certificate' => Http::response([
            'cert_pem' => 'CERT', 'ca_pem' => 'CA', 'fingerprint' => str_repeat('ab', 32),
            'expires_at' => now()->addYear()->toIso8601String(),
            'frps' => ['host' => 'tunnel.test', 'port' => 7000, 'token' => 't', 'tunnel_secret' => 's'],
        ])]);
        $csr = "-----BEGIN CERTIFICATE REQUEST-----\nMIIB\n-----END CERTIFICATE REQUEST-----\n";

        $r = $this->postJson('/api/agent/enroll', ['code' => strtolower(str_replace('-', '', $code)), 'csr_pem' => $csr,
            'hostname' => 'store-pc', 'version' => '0.1.0'])->assertOk();
        $this->assertSame('https://agent.test', $r->json('agent_url'));
        $agent = EdgeAgent::first();
        $this->assertSame('enrolled', $agent->status);
        $this->assertSame(str_repeat('ab', 32), $agent->cert_fingerprint);
        $this->assertNull($agent->enrollment_code_hash);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), "/internal/agents/{$agent->agent_uid}/certificate"));

        // Code is single-use
        $this->postJson('/api/agent/enroll', ['code' => $code, 'csr_pem' => $csr])->assertStatus(422);
    }

    public function test_expired_code_rejected(): void
    {
        $code = $this->postJson("/api/sites/{$this->site->id}/agents")->json('enrollment_code');
        $this->travel(25)->hours();
        Http::fake();
        $this->postJson('/api/agent/enroll', ['code' => $code,
            'csr_pem' => "-----BEGIN CERTIFICATE REQUEST-----\nx\n-----END CERTIFICATE REQUEST-----"])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'expired'));
        Http::assertNothingSent();
    }

    public function test_discover_marks_already_added_devices(): void
    {
        $agent = $this->onlineAgent();
        $existing = $this->site->nvrs()->create(['name' => 'Old', 'brand' => 'hikvision', 'lan_host' => '192.168.1.64']);
        Http::fake([self::DP.'/internal/agents/*/discover' => Http::response(['devices' => [
            ['ip' => '192.168.1.64', 'brand_guess' => 'hikvision', 'hardware' => 'DS-7608NI'],
            ['ip' => '192.168.1.108', 'brand_guess' => 'dahua', 'hardware' => 'NVR4108HS'],
        ]])]);
        $r = $this->postJson("/api/agents/{$agent->id}/discover")->assertOk();
        $this->assertSame($existing->id, $r->json('devices.0.already_added_nvr_id'));
        $this->assertNull($r->json('devices.1.already_added_nvr_id'));
    }

    public function test_data_plane_down_gives_friendly_503(): void
    {
        $agent = $this->onlineAgent();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));
        $this->postJson("/api/agents/{$agent->id}/discover")
            ->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'temporarily unavailable'));
    }

    public function test_discover_offline_agent_409(): void
    {
        $agent = $this->site->edgeAgents()->create(['status' => 'offline']);
        Http::fake();
        $this->postJson("/api/agents/{$agent->id}/discover")->assertStatus(409);
        Http::assertNothingSent();
    }

    // ---------- NVR onboarding ----------

    public function test_add_nvr_happy_path(): void
    {
        FirmwareAdvisory::create(['brand' => 'dahua', 'cve' => 'CVE-2021-33044', 'severity' => 'critical',
            'fixed_in_build' => '2021-09-01', 'summary' => 'Auth bypass']);
        $this->fakeDataPlane();
        $agent = $this->onlineAgent();

        $r = $this->addNvr($agent)->assertStatus(202);   // queue is sync in tests
        $nvr = Nvr::findOrFail($r->json('id'));
        $this->assertSame('active', $nvr->status);
        $this->assertSame('DHI-NVR4108HS-8P-4KS2', $nvr->model);
        $this->assertSame(['80' => 40000, '554' => 40001], $nvr->tunnel_ports);
        $this->assertSame(2, $nvr->clock_drift_seconds);
        $this->assertNotNull($nvr->owner_consent_at);
        $this->assertSame($this->installer->id, $nvr->owner_consent_by);
        $this->assertNull($nvr->onboarding_stage);                           // cleared when done

        $show = $this->getJson("/api/nvrs/{$nvr->id}")->assertOk();
        $show->assertJsonPath('channels.0.name', 'Gate')
            ->assertJsonPath('channels.0.default_view_mode', 'sub')
            ->assertJsonPath('channels.1.default_view_mode', 'snapshot')   // no substream
            ->assertJsonPath('advisories.0.cve', 'CVE-2021-33044')         // 2020 build < 2021 fix
            ->assertJsonPath('features.playback', true)
            ->assertJsonMissingPath('credential_ref');

        // Password never touches the Laravel database
        foreach (['nvrs', 'channels', 'audit_logs', 'jobs'] as $table) {
            $this->assertStringNotContainsString('Sup3r$ecret!', json_encode(DB::table($table)->get()));
        }

        // Every data-plane call is HMAC-signed
        Http::assertSent(function (Request $req) {
            [$t, $v] = explode(',', $req->header('X-VMS-Signature')[0]);
            $ts = (int) substr($t, 2);

            return substr($v, 3) === hash_hmac('sha256', $ts.'.'.$req->body(), self::SECRET);
        });
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/expose')
            && $req['ports'] === [['port' => 80, 'kind' => 'http'], ['port' => 554, 'kind' => 'rtsp']]);
        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/probe')
            && $req['device_tz'] === 'Asia/Manila' && $req['brand'] === 'dahua');
        $this->assertTrue(AuditLog::where('action', 'nvr.onboarded')->exists());
    }

    public function test_lan_unreachable_explains_the_problem(): void
    {
        $this->fakeDataPlane([80 => false, 554 => false]);
        $r = $this->addNvr($this->onlineAgent(), ['lan_host' => '192.168.1.250']);
        $this->getJson('/api/nvrs/'.$r->json('id'))
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('stage', null)
            ->assertJsonPath('error.code', 'lan_unreachable')
            ->assertJsonPath('error.message', fn ($m) => str_contains($m, '192.168.1.250:80'));
        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/probe'));
    }

    public function test_rtsp_closed_is_a_warning_not_a_failure(): void
    {
        $this->fakeDataPlane([80 => true, 554 => false]);
        $r = $this->addNvr($this->onlineAgent());
        $this->getJson('/api/nvrs/'.$r->json('id'))
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'RTSP port 554'));
    }

    public function test_bad_credentials_block_blind_retry_until_password_changed(): void
    {
        $this->fakeDataPlane(probeStatus: 401);
        $nvr = Nvr::find($this->addNvr($this->onlineAgent())->json('id'));
        $this->assertSame('bad_credentials', $nvr->onboarding_error_code);
        $oldRef = $nvr->credential_ref;

        $this->postJson("/api/nvrs/{$nvr->id}/retry")->assertStatus(422);

        $this->probeStatus = 200;   // installer fixed the password
        $this->putJson("/api/nvrs/{$nvr->id}/credentials", ['username' => 'admin', 'password' => 'right'])->assertStatus(202);
        $nvr->refresh();
        $this->assertSame('active', $nvr->status);
        $this->assertNotSame($oldRef, $nvr->credential_ref);
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && str_ends_with($req->url(), "/credentials/{$oldRef}"));
    }

    public function test_agent_offline_fails_fast(): void
    {
        $this->fakeDataPlane();
        $agent = $this->site->edgeAgents()->create(['status' => 'offline']);
        $r = $this->addNvr($agent);
        $this->getJson('/api/nvrs/'.$r->json('id'))->assertJsonPath('error.code', 'agent_offline');
        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/expose'));
    }

    public function test_wrong_brand_or_port_gives_probe_failed(): void
    {
        $this->fakeDataPlane(probeStatus: 502);
        $r = $this->addNvr($this->onlineAgent(), ['brand' => 'hikvision']);
        $this->getJson('/api/nvrs/'.$r->json('id'))
            ->assertJsonPath('error.code', 'probe_failed')
            ->assertJsonPath('error.message', fn ($m) => str_contains($m, 'Hikvision'));
    }

    public function test_validation_and_duplicates(): void
    {
        $agent = $this->onlineAgent();
        $this->fakeDataPlane();
        $this->addNvr($agent, ['lan_host' => '1.2.3.4; reboot'])->assertStatus(422);
        $this->addNvr($agent, ['brand' => 'samsung'])->assertStatus(422);
        $this->addNvr($agent, ['owner_consent' => false])->assertStatus(422)
            ->assertJsonValidationErrors(['owner_consent' => 'owner has authorized']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/internal/credentials'));   // password never sent
        $otherSite = $this->site->organization->sites()->create(['name' => 'Other']);
        $foreign = $otherSite->edgeAgents()->create(['status' => 'online']);
        $this->addNvr($foreign)->assertStatus(422);                          // agent must belong to this site
        $this->addNvr($agent)->assertStatus(202);
        $this->addNvr($agent)->assertStatus(409);                            // same IP twice
    }

    public function test_reseller_child_site_visible_but_not_to_strangers(): void
    {
        $this->fakeDataPlane();
        $id = $this->addNvr($this->onlineAgent())->json('id');
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->site->organization_id, 'role' => 'viewer']));
        $this->getJson("/api/nvrs/{$id}")->assertOk();                       // client's own viewer
        $this->postJson("/api/nvrs/{$id}/retry")->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['organization_id' => null]));
        $this->getJson("/api/nvrs/{$id}")->assertForbidden();
    }

    // ---------- agent status sync ----------

    public function test_status_sync_opens_and_reclassifies_incidents(): void
    {
        $agent = $this->onlineAgent();
        $cmd = $this->app->make(SyncAgentStatus::class);
        $t0 = now()->subHours(2)->timestamp;

        $cmd->apply(['agent_id' => $agent->agent_uid, 'status' => 'offline', 'at' => $t0 + 45,
            'offline_since' => $t0, 'cause' => 'site_unreachable']);
        $this->assertSame('offline', $agent->fresh()->status);
        $inc = OfflineIncident::sole();
        $this->assertSame(OfflineCause::SiteUnreachable, $inc->cause);

        // duplicate offline message doesn't open a second incident
        $cmd->apply(['agent_id' => $agent->agent_uid, 'status' => 'offline', 'at' => $t0 + 50, 'cause' => 'site_unreachable']);
        $this->assertSame(1, OfflineIncident::count());

        $cmd->apply(['agent_id' => $agent->agent_uid, 'status' => 'online', 'at' => $t0 + 7200, 'cause' => 'power_loss']);
        $inc->refresh();
        $this->assertSame('online', $agent->fresh()->status);
        $this->assertSame(OfflineCause::PowerLoss, $inc->cause);
        $this->assertNotNull($inc->ended_at);
        $this->assertNotNull($inc->reclassified_at);
    }
}
