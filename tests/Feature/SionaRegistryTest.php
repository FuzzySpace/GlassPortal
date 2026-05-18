<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 18: SIONA registry bridge tests.
 *
 * Security invariant: SIONA_API_TOKEN must never appear in any HTTP response,
 * view output, log entry, or exception message. Every test that configures a
 * token value checks that it is absent from the response body.
 */
class SionaRegistryTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Config registry
    // =========================================================================

    public function test_glasshouse_modules_registry_includes_siona(): void
    {
        $module = config('glasshouse.modules.siona');

        $this->assertNotNull($module, 'siona must be present in glasshouse.modules');
        $this->assertSame('SIONA', $module['display_name']);
        $this->assertSame('Sales Intelligence & Outreach Navigation Assistant', $module['full_name']);
        $this->assertSame('ai_sales', $module['category']);
        $this->assertNotEmpty($module['notes']);
        $this->assertArrayHasKey('health_endpoint', $module);
        $this->assertArrayHasKey('timeout', $module);
    }

    public function test_launch_modules_registry_includes_siona(): void
    {
        $module = config('glasshouse.launch_modules.siona');

        $this->assertNotNull($module, 'siona must be present in glasshouse.launch_modules');
        $this->assertSame('SIONA', $module['display_name']);
        $this->assertNotEmpty($module['description']);
        $this->assertArrayHasKey('icon', $module);
    }

    public function test_siona_module_registry_entry_has_env_driven_credentials(): void
    {
        // Credentials are env-driven; defaults are empty strings (safe)
        $module = config('glasshouse.modules.siona');
        $this->assertNotNull($module['base_url']);
        $this->assertNotNull($module['token']);
    }

    // =========================================================================
    // config/siona.php
    // =========================================================================

    public function test_siona_config_file_has_all_expected_keys(): void
    {
        $cfg = config('siona');

        $this->assertArrayHasKey('enabled', $cfg);
        $this->assertArrayHasKey('api_url', $cfg);
        $this->assertArrayHasKey('api_token', $cfg);
        $this->assertArrayHasKey('launch_url', $cfg);
        $this->assertArrayHasKey('timeout', $cfg);
        $this->assertArrayHasKey('verify_tls', $cfg);
    }

    public function test_siona_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('siona.enabled'));
    }

    public function test_siona_api_token_default_is_empty_string(): void
    {
        $this->assertSame('', config('siona.api_token'));
    }

    // =========================================================================
    // Connector health route existence
    // =========================================================================

    public function test_siona_connector_health_route_is_registered(): void
    {
        $route = app('router')->getRoutes()->getByName('api.connectors.siona.health');
        $this->assertNotNull($route, 'api.connectors.siona.health named route must exist');
    }

    public function test_siona_health_endpoint_responds(): void
    {
        $response = $this->get('/api/connectors/siona/health');
        $response->assertStatus(200);
    }

    // =========================================================================
    // Health endpoint — unconfigured state
    // =========================================================================

    public function test_siona_health_returns_200_when_disabled(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $response = $this->get('/api/connectors/siona/health');
        $response->assertStatus(200);
    }

    public function test_siona_health_returns_unconfigured_status_when_disabled(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $response = $this->get('/api/connectors/siona/health');
        $response->assertJson([
            'connector'  => 'siona',
            'status'     => 'unconfigured',
            'configured' => false,
        ]);
        $this->assertNull($response->json('latency_ms'));
    }

    public function test_siona_health_returns_unconfigured_when_enabled_but_url_missing(): void
    {
        config(['siona.enabled' => true, 'siona.api_url' => '']);

        $response = $this->get('/api/connectors/siona/health');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'unconfigured', 'configured' => false]);
    }

    public function test_siona_health_response_has_all_required_fields(): void
    {
        config(['siona.enabled' => false]);

        $data = $this->get('/api/connectors/siona/health')->json();

        $this->assertArrayHasKey('connector', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('configured', $data);
        $this->assertArrayHasKey('latency_ms', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('siona', $data['connector']);
    }

    // =========================================================================
    // Token leakage guards
    // =========================================================================

    public function test_siona_health_response_never_leaks_api_token_when_unconfigured(): void
    {
        $secret = 'siona-secret-must-not-appear-in-response';
        config([
            'siona.enabled'   => false,
            'siona.api_token' => $secret,
        ]);

        $response = $this->get('/api/connectors/siona/health');
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_siona_health_response_has_no_token_or_api_token_field(): void
    {
        config(['siona.enabled' => false]);

        $data = $this->get('/api/connectors/siona/health')->json();
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('api_token', $data);
    }

    public function test_siona_health_response_never_leaks_token_on_successful_probe(): void
    {
        Http::fake(['siona.test/api/health' => Http::response(['status' => 'ok'], 200)]);

        $secret = 'live-probe-token-must-not-leak';
        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => $secret,
        ]);

        $response = $this->get('/api/connectors/siona/health');
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // Health endpoint — live probe states
    // =========================================================================

    public function test_siona_health_returns_ok_when_probe_succeeds(): void
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
        $this->assertIsInt($response->json('latency_ms'));
    }

    public function test_siona_health_returns_degraded_on_non_2xx_response(): void
    {
        Http::fake(['siona.test/api/health' => Http::response([], 503)]);

        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $response = $this->get('/api/connectors/siona/health');
        $response->assertStatus(200);
        $response->assertJson(['connector' => 'siona', 'status' => 'degraded', 'configured' => true]);
    }

    public function test_siona_health_returns_error_and_200_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        config([
            'siona.enabled' => true,
            'siona.api_url' => 'http://siona.test',
        ]);

        $response = $this->get('/api/connectors/siona/health');
        $response->assertStatus(200);
        $response->assertJson(['connector' => 'siona', 'configured' => true]);
        $this->assertContains($response->json('status'), ['error', 'degraded']);
    }

    // =========================================================================
    // Healthcheck command (artisan)
    // =========================================================================

    public function test_healthcheck_exits_0_when_siona_unconfigured(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $this->artisan('glassportal:healthcheck')->assertExitCode(0);
    }

    public function test_healthcheck_reports_siona_module_registry_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.module_registry')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_siona_config_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.config')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_siona_connector_route_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.connector_route')
            ->assertExitCode(0);
    }

    // =========================================================================
    // Admin modules view
    // =========================================================================

    private function staffUser(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    public function test_admin_modules_shows_siona_in_connector_registry(): void
    {
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);

        $response = $this->actingAs($this->staffUser())->get('/admin/modules');
        $response->assertStatus(200);
        $response->assertSee('siona');
        $response->assertSeeText('SIONA');
    }

    public function test_admin_modules_shows_siona_in_launch_registry(): void
    {
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);

        $response = $this->actingAs($this->staffUser())->get('/admin/modules');
        $response->assertStatus(200);
        // Both the connector registry key and the launch registry key render
        $response->assertSee('siona');
    }

    public function test_admin_modules_does_not_render_siona_api_token(): void
    {
        $secret = 'admin-view-token-must-not-appear';
        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
            'siona.api_token'       => $secret,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/admin/modules');
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // Portal modules launchpad
    // =========================================================================

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    public function test_portal_modules_renders_siona_when_org_link_exists(): void
    {
        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()
            ->withLaunchUrl('https://siona.example.test')
            ->forModule('siona', 'SIONA')
            ->create([
                'organization_id' => $org->id,
                'status'          => 'active',
            ]);

        $user     = $this->customerUser($org);
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSee('siona');
        $response->assertSeeText('SIONA');
    }

    public function test_portal_modules_shows_not_linked_for_org_without_siona_link(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');
        $response->assertStatus(200);
        // SIONA appears in registry but is not linked
        $response->assertSee('siona');
        $response->assertSeeText('not linked');
    }

    public function test_portal_modules_shows_setup_required_for_siona_signed_launch_without_secret(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);

        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()
            ->ssoMode('signed_launch')
            ->forModule('siona', 'SIONA')
            ->create([
                'organization_id' => $org->id,
                'status'          => 'active',
            ]);

        $user     = $this->customerUser($org);
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Setup required');
    }

    public function test_portal_modules_does_not_render_siona_api_token(): void
    {
        $secret = 'portal-view-token-must-not-appear';
        config(['siona.api_token' => $secret]);

        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_portal_siona_launch_uses_module_launch_service_flow(): void
    {
        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()
            ->withLaunchUrl('https://siona.example.test')
            ->forModule('siona', 'SIONA')
            ->create([
                'organization_id' => $org->id,
                'status'          => 'active',
            ]);

        $link = OrganizationModuleLink::where('module_key', 'siona')->first();
        $user = $this->customerUser($org);

        // Launch must go through the audited ModuleLaunchService (redirect for standalone)
        $response = $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");
        $response->assertRedirect('https://siona.example.test');

        $this->assertDatabaseHas('module_launch_events', [
            'module_key' => 'siona',
            'event_type' => 'allowed',
        ]);
    }
}
