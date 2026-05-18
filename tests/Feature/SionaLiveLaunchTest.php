<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Siona\SionaConnectorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SionaLiveLaunchTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function adminUser(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    private function sionaLink(Organization $org, string $authMode = 'standalone', string $url = 'https://siona.example.test'): OrganizationModuleLink
    {
        return OrganizationModuleLink::factory()
            ->forModule('siona', 'SIONA')
            ->create([
                'organization_id' => $org->id,
                'auth_mode'       => $authMode,
                'external_url'    => $url,
                'status'          => 'active',
            ]);
    }

    // =========================================================================
    // SionaConnectorClient — container resolution
    // =========================================================================

    public function test_siona_connector_client_resolves_from_container(): void
    {
        $client = app(SionaConnectorClient::class);

        $this->assertInstanceOf(SionaConnectorClient::class, $client);
    }

    public function test_siona_connector_client_is_singleton(): void
    {
        $a = app(SionaConnectorClient::class);
        $b = app(SionaConnectorClient::class);

        $this->assertSame($a, $b);
    }

    // =========================================================================
    // SIONA launch registry — supported_auth_modes
    // =========================================================================

    public function test_siona_launch_registry_has_supported_auth_modes(): void
    {
        $module = config('glasshouse.launch_modules.siona');

        $this->assertNotNull($module);
        $this->assertArrayHasKey('supported_auth_modes', $module);
        $this->assertContains('standalone', $module['supported_auth_modes']);
        $this->assertContains('signed_launch', $module['supported_auth_modes']);
        $this->assertContains('backchannel_launch', $module['supported_auth_modes']);
    }

    // =========================================================================
    // Portal modules — SIONA card states
    // =========================================================================

    public function test_portal_siona_card_shows_not_linked_when_no_module_link(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSee('siona');
        $response->assertSeeText('not linked');
    }

    public function test_portal_siona_card_shows_active_and_launch_button_for_standalone(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'standalone', 'https://siona.example.test');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSee('siona');
        $response->assertSeeText('active');
        $response->assertSeeText('External launch');
    }

    public function test_portal_siona_card_shows_secure_launch_for_signed_launch(): void
    {
        config(['glasshouse_sso.signing_secret' => 'test-secret-at-least-32-chars-long-ok']);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'signed_launch', 'https://siona.example.test/launch');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Secure launch');
    }

    public function test_portal_siona_card_shows_setup_required_for_signed_launch_without_secret(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'signed_launch', 'https://siona.example.test/launch');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Setup required');
    }

    public function test_portal_siona_card_shows_secure_launch_for_backchannel(): void
    {
        config(['glasshouse_sso.backchannel.enabled' => true]);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'backchannel_launch', 'https://siona.example.test/launch');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Secure launch');
    }

    public function test_portal_siona_card_shows_setup_required_for_backchannel_when_disabled(): void
    {
        config(['glasshouse_sso.backchannel.enabled' => false]);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'backchannel_launch', 'https://siona.example.test/launch');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Setup required');
    }

    public function test_portal_siona_card_shows_backchannel_auth_label(): void
    {
        config(['glasshouse_sso.backchannel.enabled' => true]);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'backchannel_launch', 'https://siona.example.test/launch');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('back-channel launch');
    }

    // =========================================================================
    // Portal — token / code leakage guards
    // =========================================================================

    public function test_portal_siona_card_never_renders_api_token(): void
    {
        $secret = 'portal-siona-api-token-must-not-leak-phase19';
        config(['siona.api_token' => $secret]);

        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_portal_siona_card_never_renders_signing_secret(): void
    {
        $secret = 'portal-signing-secret-must-not-render-phase19';
        config(['glasshouse_sso.signing_secret' => $secret]);

        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // SIONA standalone launch — goes through existing audited launch flow
    // =========================================================================

    public function test_siona_standalone_launch_redirects_to_external_url(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'standalone', 'https://siona.example.test/app');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $response->assertRedirect('https://siona.example.test/app');
    }

    public function test_siona_standalone_launch_creates_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'standalone', 'https://siona.example.test/app');
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'module_key'      => 'siona',
            'event_type'      => 'allowed',
        ]);
    }

    public function test_siona_signed_launch_issues_token_and_renders_handoff(): void
    {
        config(['glasshouse_sso.signing_secret' => 'test-secret-long-enough-for-hmac-ok']);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'signed_launch', 'https://siona.example.test/sso');
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $response->assertStatus(200);
        // Renders the post-form handoff, not a raw token in a redirect
        $response->assertSee('siona.example.test/sso');
        $this->assertStringNotContainsString('Bearer', $response->getContent());
    }

    public function test_siona_signed_launch_token_not_in_url(): void
    {
        config(['glasshouse_sso.signing_secret' => 'test-secret-long-enough-for-hmac-ok']);

        $org  = Organization::factory()->create();
        $link = $this->sionaLink($org, 'signed_launch', 'https://siona.example.test/sso');
        $user = $this->customerUser($org);

        // The response should be a view (handoff form), not a redirect with token in URL
        $response = $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        // If it redirected, the redirect URL must not contain a token
        if ($response->isRedirect()) {
            $this->assertStringNotContainsString('token=', $response->headers->get('Location') ?? '');
        } else {
            // It's a view — confirm status 200
            $response->assertStatus(200);
        }
    }

    // =========================================================================
    // Admin modules view — SIONA visibility
    // =========================================================================

    public function test_admin_modules_shows_siona_in_connector_registry(): void
    {
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);

        $response = $this->actingAs($this->adminUser())->get('/admin/modules');

        $response->assertStatus(200);
        $response->assertSee('siona');
        $response->assertSeeText('SIONA');
    }

    public function test_admin_modules_shows_siona_health_panel(): void
    {
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);

        $response = $this->actingAs($this->adminUser())->get('/admin/modules');

        $response->assertStatus(200);
        $response->assertSeeText('SIONA Connector');
        $response->assertSee('/api/connectors/siona/health');
    }

    public function test_admin_modules_shows_siona_supported_auth_modes(): void
    {
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);

        $response = $this->actingAs($this->adminUser())->get('/admin/modules');

        $response->assertStatus(200);
        $response->assertSeeText('signed_launch');
        $response->assertSeeText('backchannel_launch');
    }

    public function test_admin_modules_siona_panel_never_renders_token(): void
    {
        $secret = 'admin-panel-siona-token-must-not-leak-phase19';
        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
            'siona.api_token'       => $secret,
        ]);

        $response = $this->actingAs($this->adminUser())->get('/admin/modules');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // SionaConnectorClient — health endpoint integration
    // =========================================================================

    public function test_siona_health_endpoint_uses_connector_client(): void
    {
        Http::fake(['siona.test/api/health' => Http::response(['status' => 'ok'], 200)]);

        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $response = $this->get('/api/connectors/siona/health');

        $response->assertStatus(200);
        $response->assertJson(['connector' => 'siona', 'status' => 'ok', 'configured' => true]);
    }

    public function test_siona_health_endpoint_never_exposes_token(): void
    {
        Http::fake(['siona.test/api/health' => Http::response([], 200)]);

        $secret = 'health-endpoint-secret-must-not-appear-phase19';
        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => $secret,
        ]);

        $response = $this->get('/api/connectors/siona/health');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // SIONA account mapping — metadata field
    // =========================================================================

    public function test_organization_module_link_metadata_column_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('organization_module_links', 'metadata'));
    }

    public function test_siona_link_can_store_workspace_id_in_metadata(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->forModule('siona', 'SIONA')
            ->create([
                'organization_id' => $org->id,
                'status'          => 'active',
                'metadata'        => ['siona_workspace_id' => 'ws-abc123'],
            ]);

        $this->assertDatabaseHas('organization_module_links', ['id' => $link->id]);
        $fresh = $link->fresh();
        $this->assertIsArray($fresh->metadata);
        $this->assertSame('ws-abc123', $fresh->metadata['siona_workspace_id']);
    }

    public function test_siona_link_metadata_workspace_id_not_exposed_in_portal(): void
    {
        $workspaceId = 'ws-secret-id-should-not-render-in-dom';

        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()
            ->forModule('siona', 'SIONA')
            ->create([
                'organization_id' => $org->id,
                'status'          => 'active',
                'external_url'    => 'https://siona.example.test',
                'metadata'        => ['siona_workspace_id' => $workspaceId],
            ]);
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        // workspace ID should not appear verbatim in the portal HTML
        $this->assertStringNotContainsString($workspaceId, $response->getContent());
    }

    // =========================================================================
    // Healthcheck — Phase 19 checks present and non-blocking
    // =========================================================================

    public function test_healthcheck_includes_siona_connector_client_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.connector_client')
            ->assertExitCode(0);
    }

    public function test_healthcheck_includes_siona_launch_registry_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.launch_registry')
            ->assertExitCode(0);
    }

    public function test_healthcheck_includes_siona_module_link_support_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.module_link_support')
            ->assertExitCode(0);
    }

    public function test_healthcheck_includes_siona_health_probe_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.health_probe')
            ->assertExitCode(0);
    }

    public function test_healthcheck_exits_zero_when_siona_unconfigured(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }
}
