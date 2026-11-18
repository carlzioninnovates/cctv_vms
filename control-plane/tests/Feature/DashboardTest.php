<?php

namespace Tests\Feature;

use App\Enums\OfflineCause;
use App\Models\OfflineIncident;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $platform;
    private Organization $reseller;
    private Organization $client;
    private Site $site;
    private User $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->platform = Organization::create(['type' => 'platform', 'name' => 'VMS', 'slug' => 'vms']);
        $this->reseller = Organization::create(['type' => 'reseller', 'parent_id' => $this->platform->id, 'name' => 'CCTV Pro', 'slug' => 'pro']);
        $this->client = Organization::create(['type' => 'client', 'parent_id' => $this->reseller->id, 'name' => 'Reyes Hardware', 'slug' => 'reyes']);
        $this->site = $this->client->sites()->create(['name' => 'Main branch']);
        $this->installer = User::factory()->create(['organization_id' => $this->reseller->id, 'role' => 'installer',
            'email' => 'installer@example.ph', 'password' => 'correct-horse']);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/sites')->assertRedirect('/login');
        $this->getJson('/api/sites')->assertUnauthorized();
    }

    public function test_login_logout_and_throttle(): void
    {
        $this->post('/login', ['email' => 'installer@example.ph', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'installer@example.ph', 'password' => 'correct-horse'])->assertRedirect('/sites');
        $this->assertAuthenticatedAs($this->installer);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'installer@example.ph', 'password' => 'nope']);
        }
        $this->post('/login', ['email' => 'installer@example.ph', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_session_cookie_authenticates_api(): void
    {
        $this->actingAs($this->installer);   // web session, like the browser
        $this->getJson('/api/sites')->assertOk()->assertJsonPath('sites.0.name', 'Main branch');
    }

    public function test_real_browser_login_then_api_with_session_cookie(): void
    {
        // Like the browser: real login, then same-origin API calls (Referer = app URL)
        config(['sanctum.stateful' => ['localhost:8000']]);
        $this->post('/login', ['email' => 'installer@example.ph', 'password' => 'correct-horse']);
        $this->withHeader('Referer', 'http://localhost:8000/sites')
            ->getJson('/api/sites')->assertOk()->assertJsonPath('sites.0.name', 'Main branch');
    }

    public function test_pages_render_and_are_tenant_scoped(): void
    {
        $nvr = $this->site->nvrs()->create(['name' => 'Store NVR', 'brand' => 'dahua', 'lan_host' => '192.168.1.108']);
        $this->actingAs($this->installer);
        $this->get('/sites')->assertOk()->assertSee('x-data="sitesPage"', false);
        $this->get("/sites/{$this->site->id}")->assertOk()->assertSee('Main branch');
        $this->get("/nvrs/{$nvr->id}")->assertOk()->assertSee('Store NVR');

        $other = Organization::create(['type' => 'reseller', 'name' => 'Rival', 'slug' => 'rival']);
        $this->actingAs(User::factory()->create(['organization_id' => $other->id, 'role' => 'owner']));
        $this->get("/sites/{$this->site->id}")->assertForbidden();
        $this->get("/nvrs/{$nvr->id}")->assertForbidden();
    }

    public function test_organizations_visible_to_reseller_and_client(): void
    {
        $this->actingAs($this->installer);
        $names = collect($this->getJson('/api/organizations')->json('organizations'))->pluck('name')->all();
        $this->assertSame(['CCTV Pro', 'Reyes Hardware'], $names);                // not the platform, not rivals

        $clientUser = User::factory()->create(['organization_id' => $this->client->id, 'role' => 'owner']);
        $this->actingAs($clientUser);
        $names = collect($this->getJson('/api/organizations')->json('organizations'))->pluck('name')->all();
        $this->assertSame(['Reyes Hardware'], $names);                            // can't see its installer's other clients
    }

    public function test_reseller_adds_client_but_client_cannot(): void
    {
        $this->actingAs($this->installer);
        $this->postJson('/api/organizations', ['name' => 'Santos Bakery'])->assertCreated()->assertJsonPath('type', 'client');
        $this->assertDatabaseHas('organizations', ['name' => 'Santos Bakery', 'parent_id' => $this->reseller->id]);

        $this->actingAs(User::factory()->create(['organization_id' => $this->client->id, 'role' => 'owner']));
        $this->postJson('/api/organizations', ['name' => 'Sneaky'])->assertForbidden();
    }

    public function test_create_site_and_show_with_status(): void
    {
        $this->actingAs($this->installer);
        $id = $this->postJson("/api/organizations/{$this->client->id}/sites", ['name' => 'Branch 2', 'isp' => 'PLDT', 'has_ups' => true])
            ->assertCreated()->json('id');
        $site = Site::find($id);
        $site->edgeAgents()->create(['status' => 'offline']);
        OfflineIncident::create(['site_id' => $id, 'cause' => OfflineCause::PowerLoss, 'started_at' => now()->subHour()]);
        $site->nvrs()->create(['name' => 'A', 'brand' => 'hikvision', 'lan_host' => '10.0.0.2', 'status' => 'failed',
            'onboarding_error_code' => 'bad_credentials']);

        $this->getJson("/api/sites/{$id}")->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('has_ups', true)
            ->assertJsonPath('offline.cause', 'power_loss')
            ->assertJsonPath('agents.0.status', 'offline')
            ->assertJsonPath('nvrs.0.error_code', 'bad_credentials')
            ->assertJsonMissingPath('nvrs.0.credential_ref');

        $list = collect($this->getJson('/api/sites')->json('sites'))->firstWhere('id', $id);
        $this->assertSame('power_loss', $list['offline_cause']);
        $this->assertSame(1, $list['nvrs']['failed']);
    }

    public function test_viewer_sees_but_cannot_manage(): void
    {
        $viewer = User::factory()->create(['organization_id' => $this->client->id, 'role' => 'viewer']);
        $this->actingAs($viewer);
        $this->getJson("/api/sites/{$this->site->id}")->assertOk()->assertJsonPath('can_manage', false);
        $this->postJson("/api/organizations/{$this->client->id}/sites", ['name' => 'X'])->assertForbidden();
    }

    public function test_site_search(): void
    {
        $this->client->sites()->create(['name' => 'Warehouse Pasig']);
        $this->actingAs($this->installer);
        $this->getJson('/api/sites?q=pasig')->assertOk()->assertJsonCount(1, 'sites');
    }

    public function test_help_page_renders_guide_with_anchors_used_by_forms(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $this->actingAs($this->installer);
        $r = $this->get('/help')->assertOk();
        $r->assertSee('id="add-an-nvr"', false)                 // linked from the Add NVR form
          ->assertSee('id="if-adding-an-nvr-fails"', false)     // linked from the NVR error box
          ->assertSee(config('app.name').' user guide')
          ->assertDontSee('{app}');
        $this->assertStringContainsString('<table>', $r->getContent());   // GFM tables render
    }

    public function test_header_shows_brand_and_nav(): void
    {
        $this->actingAs($this->installer);
        $this->get('/sites')->assertOk()->assertSee('Zi-on')->assertSee('href="'.route('help').'"', false);
    }
}
