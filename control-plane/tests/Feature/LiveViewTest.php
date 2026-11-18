<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Nvr;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Media\ViewerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveViewTest extends TestCase
{
    use RefreshDatabase;

    private const DP = 'http://dp.test';
    private Organization $client;
    private Site $site;
    private Nvr $nvr;
    private Channel $cam1;
    private Channel $ptzCam;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.data_plane.url' => self::DP, 'services.data_plane.secret' => 'test-secret',
            'services.data_plane.public_media_url' => 'https://media-api.test']);
        $reseller = Organization::create(['type' => 'reseller', 'name' => 'Pro', 'slug' => 'pro']);
        $this->client = Organization::create(['type' => 'client', 'parent_id' => $reseller->id, 'name' => 'Reyes', 'slug' => 'reyes']);
        $this->site = $this->client->sites()->create(['name' => 'Main']);
        $this->nvr = $this->site->nvrs()->create(['name' => 'NVR', 'brand' => 'dahua', 'lan_host' => '10.0.0.5', 'status' => 'active']);
        $this->cam1 = $this->nvr->channels()->create(['number' => 1, 'name' => 'Gate', 'main_codec' => 'h265', 'sub_codec' => 'h264']);
        $this->ptzCam = $this->nvr->channels()->create(['number' => 2, 'name' => 'Lot', 'ptz' => true, 'sub_codec' => 'none', 'main_codec' => 'h264']);
        $this->nvr->channels()->create(['number' => 3, 'name' => 'Removed', 'enabled' => false]);
        $this->operator = User::factory()->create(['organization_id' => $this->client->id, 'role' => 'operator']);
    }

    private function fakeLive(): void
    {
        Http::fake([self::DP.'/internal/nvrs/*/channels/*/live' => fn (Request $r) => Http::response([
            'path' => 'live/'.$this->nvr->id.'/'.explode('/', $r->url())[7].'/'.$r['stream'].'abc',
            'whep_url' => 'https://media.test/live/x/whep',
            'hls_url' => 'https://media.test/live/x/index.m3u8',
            'browser_playable_webrtc' => $r['stream'] === 'sub',
        ])]);
    }

    public function test_cameras_list_with_signed_snapshots(): void
    {
        $this->actingAs($this->operator);
        $r = $this->getJson("/api/sites/{$this->site->id}/cameras")->assertOk();
        $this->assertSame(['Gate', 'Lot'], array_column($r->json('cameras'), 'name'));     // disabled one hidden
        $this->assertTrue($r->json('can_ptz'));

        $url = $r->json('cameras.0.snapshot.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith("https://media-api.test/media/snapshot/{$this->nvr->id}/1?", $url);
        $this->assertSame(hash_hmac('sha256', "snap:{$this->nvr->id}:1:{$q['exp']}", 'test-secret'), $q['sig']);
        $this->assertGreaterThan(time() + 1500, (int) $q['exp']);
    }

    public function test_viewer_cannot_ptz_and_strangers_see_nothing(): void
    {
        $viewer = User::factory()->create(['organization_id' => $this->client->id, 'role' => 'viewer']);
        $this->actingAs($viewer);
        $this->getJson("/api/sites/{$this->site->id}/cameras")->assertOk()->assertJsonPath('can_ptz', false);
        Http::fake();
        $this->postJson("/api/channels/{$this->ptzCam->id}/ptz", ['action' => 'left'])->assertForbidden();
        Http::assertNothingSent();

        $rival = Organization::create(['type' => 'client', 'name' => 'Rival', 'slug' => 'rival']);
        $this->actingAs(User::factory()->create(['organization_id' => $rival->id, 'role' => 'owner']));
        $this->getJson("/api/sites/{$this->site->id}/cameras")->assertForbidden();
        $this->postJson("/api/channels/{$this->cam1->id}/stream")->assertForbidden();
        $this->get("/sites/{$this->site->id}/live")->assertForbidden();
    }

    public function test_stream_returns_tokenized_urls_and_audits(): void
    {
        $this->fakeLive();
        $this->actingAs($this->operator);
        $r = $this->postJson("/api/channels/{$this->cam1->id}/stream", ['quality' => 'sub'])->assertOk();
        $r->assertJsonPath('webrtc_ok', true)->assertJsonPath('quality', 'sub')->assertJsonPath('codec', 'h264');
        $token = $r->json('token');
        $this->assertSame("https://media.test/live/x/whep?vt={$token}", $r->json('whep_url'));
        $this->assertSame("https://media.test/live/x/index.m3u8?vt={$token}", $r->json('hls_url'));

        $path = "live/{$this->nvr->id}/1/subabc";
        $this->assertSame($this->operator->id, app(ViewerToken::class)->verify($token, $path));
        $this->assertNull(app(ViewerToken::class)->verify($token, "live/{$this->nvr->id}/2/subabc"));   // other camera
        $this->assertTrue(AuditLog::where('action', 'live.view')->where('subject_id', $this->cam1->id)->exists());

        $this->postJson("/api/channels/{$this->cam1->id}/stream", ['quality' => 'main'])
            ->assertJsonPath('webrtc_ok', false)->assertJsonPath('codec', 'h265');      // H.265 -> client uses HLS
    }

    public function test_camera_without_substream_falls_back_to_main(): void
    {
        $this->fakeLive();
        $this->actingAs($this->operator);
        $this->postJson("/api/channels/{$this->ptzCam->id}/stream", ['quality' => 'sub'])->assertJsonPath('quality', 'main');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/channels/2/live') && $r['stream'] === 'main');
    }

    public function test_stream_errors(): void
    {
        $this->actingAs($this->operator);
        Http::fake([self::DP.'/*' => Http::response(['detail' => 'agent offline'], 504)]);
        $this->postJson("/api/channels/{$this->cam1->id}/stream")->assertStatus(504);

        $disabled = $this->nvr->channels()->where('number', 3)->first();
        $this->postJson("/api/channels/{$disabled->id}/stream")->assertNotFound();

        $this->nvr->update(['status' => 'offline']);
        $this->postJson("/api/channels/{$this->cam1->id}/stream")->assertStatus(409);
        $this->postJson("/api/channels/{$this->cam1->id}/stream", ['quality' => '4k'])->assertStatus(409);
    }

    public function test_ptz(): void
    {
        Http::fake([self::DP.'/*' => Http::response(null, 204)]);
        $this->actingAs($this->operator);
        $this->postJson("/api/channels/{$this->ptzCam->id}/ptz", ['action' => 'zoom_in', 'speed' => 5])->assertNoContent();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/internal/nvrs/{$this->nvr->id}/channels/2/ptz")
            && $r['action'] === 'zoom_in' && $r['speed'] === 5);
        $this->postJson("/api/channels/{$this->cam1->id}/ptz", ['action' => 'left'])->assertStatus(422);       // not PTZ
        $this->postJson("/api/channels/{$this->ptzCam->id}/ptz", ['action' => 'self_destruct'])->assertStatus(422);
    }

    // ---------- MediaMTX auth hook ----------

    public function test_media_auth_hook(): void
    {
        $tokens = app(ViewerToken::class);
        $path = 'live/1/1/abc';
        $t = $tokens->issue(7, $path)['token'];
        $auth = fn (array $body) => $this->postJson('/api/internal/media/auth', $body + ['ip' => '1.2.3.4', 'protocol' => 'webrtc', 'id' => null]);

        $auth(['action' => 'read', 'path' => $path, 'query' => "vt={$t}"])->assertOk();
        $auth(['action' => 'read', 'path' => $path, 'query' => "foo=1&vt={$t}&bar=2"])->assertOk();
        $auth(['action' => 'read', 'path' => $path, 'token' => $t])->assertOk();               // Bearer, newer MediaMTX
        $auth(['action' => 'read', 'path' => 'live/1/2/abc', 'query' => "vt={$t}"])->assertUnauthorized();
        $auth(['action' => 'publish', 'path' => $path, 'query' => "vt={$t}"])->assertUnauthorized();
        $auth(['action' => 'read', 'path' => $path, 'query' => 'vt='.substr($t, 0, -2).'xx'])->assertUnauthorized();
        $auth(['action' => 'read', 'path' => $path])->assertUnauthorized();

        // Real MediaMTX RTSP payload: every field present, unused ones empty, token in the password
        $auth(['action' => 'read', 'path' => $path, 'user' => 'viewer', 'password' => $t, 'token' => '', 'query' => '',
            'userAgent' => 'VLC'])->assertOk();
        $auth(['action' => 'read', 'path' => $path, 'user' => '', 'password' => '', 'token' => '', 'query' => ''])->assertUnauthorized();

        $expired = $tokens->issue(7, $path, -1)['token'];
        $auth(['action' => 'read', 'path' => $path, 'query' => "vt={$expired}"])->assertUnauthorized();

        // Short enough for RTSP clients (ffmpeg caps "user:password" at 127 bytes)
        $this->assertLessThan(60, strlen($t));
        // Can't be reused for another user id by editing the visible part
        $auth(['action' => 'read', 'path' => $path, 'query' => 'vt=8'.substr($t, 1)])->assertUnauthorized();

        // A token minted with a different app key is useless
        $forged = (new ViewerToken('other-key'))->issue(7, $path)['token'];
        $auth(['action' => 'read', 'path' => $path, 'query' => "vt={$forged}"])->assertUnauthorized();
    }

    public function test_live_page_renders(): void
    {
        $this->actingAs($this->operator);
        $this->get("/sites/{$this->site->id}/live")->assertOk()->assertSee("livePage({$this->site->id})", false);
    }
}
