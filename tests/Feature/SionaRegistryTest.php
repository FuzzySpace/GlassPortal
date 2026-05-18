<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SionaRegistryTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Config registry
    // =========================================================================

    public function test_config_registry_includes_siona_connector_module(): void
    {
        $module = config('glasshouse.modules.siona');

        $this->assertNotNull($module, 'siona must be present in glasshouse.modules');
        $this->assertSame('SIONA', $module['display_name']);
        $this->assertSame('Sales Intelligence & Outreach Navigation Assistant', $module['full_name']);
        $this->assertSame('ai_sales', $module['category']);
        $this->assertNotEmpty($module['notes']);
        $this->assertArrayHasKey('health_endpoint', $module);
    }

    public function test_launch_registry_includes_siona(): void
    {
        $module = config('glasshouse.launch_modules.siona');

        $this->assertNotNull($module, 'siona must be present in glasshouse.launch_modules');
        $this->assertSame('SIONA', $module['display_name']);
        $this->assertNotEmpty($module['description']);
        $this->assertArrayHasKey('icon', $module);
    }

    public function test_siona_connector_module_has_env_driven_fields(): void
    {
        $module = config('glasshouse.modules.siona');

        // All credential/URL fields must be env-driven (safe default is empty)
        $this->assertNotNull($module['base_url']);
        $this->assertNotNull($module['token']);
    }

    // =========================================================================
    // SIONA config file
    // =========================================================================

    public function test_siona_config_file_has_expected_keys(): void
    {
        $this->assertNotNull(config('siona.enabled'));
        $this->assertNotNull(config('siona.timeout'));
        $this->assertNotNull(config('siona.verify_tls'));
        $this->assertArrayHasKey('api_url', config('siona'));
        $this->assertArrayHasKey('api_token', config('siona'));
        $this->assertArrayHasKey('launch_url', config('siona'));
    }

    public function test_siona_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('siona.enabled'));
    }

    // =========================================================================
    // Connector health route
    // =========================================================================

    public function test_siona_connector_health_route_exists(): void
    {
        $route = app('router')->getRoutes()->getByName('api.connectors.siona.health');

        $this->assertNotNull($route, 'api.connectors.siona.health named route must exist');
    }

    public function test_siona_health_endpoint_is_accessible(): void
    {
        $response = $this->get('/api/connectors/siona/health');

        $response->assertStatus(200);
    }

    public function test_siona_health_unconfigured_returns_200(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $response = $this->get('/api/connectors/siona/health');

        $response->assertStatus(200);
    }

    public function test_siona_health_unconfigured_returns_status_unconfigured(): void
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

    public function test_siona_health_enabled_but_missing_url_returns_unconfigured(): void
    {
        config(['siona.enabled' => true, 'siona.api_url' => '']);

        $response = $this->get('/api/connectors/siona/health');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'unconfigured', 'configured' => false]);
    }

    public function test_siona_health_response_has_required_fields(): void
    {
        config(['siona.enabled' => false]);

        $response = $this->get('/api/connectors/siona/health');

        $data = $response->json();
        $this->assertArrayHasKey('connector', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('configured', $data);
        $this->assertArrayHasKey('latency_ms', $data);
        $this->assertArrayHasKey('message', $data);
    }

    public function test_siona_health_response_never_leaks_api_token(): void
    {
        $secret = 'siona-secret-token-that-must-not-leak';
        config([
            'siona.enabled'   => false,
            'siona.api_token' => $secret,
        ]);

        $response = $this->get('/api/connectors/siona/health');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_siona_health_response_does_not_contain_token_field(): void
    {
        config(['siona.enabled' => false]);

        $response = $this->get('/api/connectors/siona/health');

        $data = $response->json();
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('api_token', $data);
    }

    public function test_siona_health_returns_ok_when_probe_succeeds(): void
    {
        Http::fake([
            'siona.test/api/health' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'siona.enabled'  => true,
            'siona.api_url'  => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $response = $this->get('/api/connectors/siona/health');

        $response->assertStatus(200);
        $response->assertJson(['connector' => 'siona', 'status' => 'ok', 'configured' => true]);
        $this->assertIsInt($response->json('latency_ms'));
    }

    public function test_siona_health_returns_degraded_on_non_2xx(): void
    {
        Http::fake([
            'siona.test/api/health' => Http::response([], 503),
        ]);

        config([
            'siona.enabled'  => true,
            'siona.api_url'  => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $response = $this->get('/api/connectors/siona/health');

        $response->assertStatus(200);
        $response->assertJson(['connector' => 'siona', 'status' => 'degraded', 'configured' => true]);
    }

    public function test_siona_health_returns_error_on_connection_failure(): void
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

    public function test_siona_health_response_never_contains_token_on_probe_success(): void
    {
        Http::fake([
            'siona.test/api/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $secret = 'probe-secret-that-must-not-appear';
        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => $secret,
        ]);

        $response = $this->get('/api/connectors/siona/health');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // Healthcheck command
    // =========================================================================

    public function test_healthcheck_does_not_fail_when_siona_unconfigured(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_siona_module_registry(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.module_registry')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_siona_connector_route(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.connector_route')
            ->assertExitCode(0);
    }

    public function test_healthcheck_warns_not_fails_when_siona_disabled(): void
    {
        config(['siona.enabled' => false]);

        // Exit 0 means no fatal failures triggered by SIONA being unconfigured
        $this->artisan('glassportal:healthcheck')
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
        // siona key appears in the launch registry table
        $response->assertSee('siona');
    }

    public function test_admin_modules_does_not_render_siona_token(): void
    {
        $secret = 'admin-view-siona-token-must-not-leak';
        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
            'siona.api_token'       => $secret,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/admin/modules');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    // =========================================================================
    // Portal modules view
    // =========================================================================

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    public function test_portal_modules_can_render_siona_linked_module(): void
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

    public function test_portal_modules_siona_not_linked_shows_not_linked(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        // SIONA appears in registry but is not linked for this org
        $response->assertSee('siona');
        $response->assertSeeText('not linked');
    }

    public function test_portal_modules_does_not_render_siona_token(): void
    {
        $secret = 'portal-view-siona-token-must-not-leak';
        config(['siona.api_token' => $secret]);

        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/modules');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }
}
