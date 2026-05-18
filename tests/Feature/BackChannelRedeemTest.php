<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\BackChannelLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackChannelRedeemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.backchannel.enabled'                  => true]);
        config(['glasshouse_sso.backchannel.code_ttl_seconds'         => 60]);
        config(['glasshouse_sso.backchannel.replay_cache_ttl_seconds' => 600]);
        config(['glasshouse_sso.backchannel.strict_module_match'      => true]);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_redeem_valid_code_returns_200_with_identity(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $response = $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
        $response->assertJsonPath('module_key', $link->module_key);
        $response->assertJsonPath('user_id', (string) $user->id);
        $response->assertJsonPath('email', $user->email);
        $response->assertJsonPath('name', $user->name);
        $response->assertJsonStructure(['ok', 'module_key', 'user_id', 'org_id', 'email', 'name', 'role', 'expires_at']);
    }

    public function test_redeem_response_role_matches_user_role(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $response = $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ]);

        $response->assertJsonPath('role', UserRole::Customer->value);
    }

    // -------------------------------------------------------------------------
    // Security — response must not leak raw code
    // -------------------------------------------------------------------------

    public function test_redeem_response_does_not_contain_raw_code(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $response = $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", [
            'launch_code' => $code,
        ]);

        $this->assertStringNotContainsString($code, $response->getContent());
    }

    // -------------------------------------------------------------------------
    // Method enforcement
    // -------------------------------------------------------------------------

    public function test_get_request_returns_405(): void
    {
        $this->getJson('/api/sso/backchannel/redeem/glasspanel')
            ->assertStatus(405);
    }

    // -------------------------------------------------------------------------
    // Missing / invalid code
    // -------------------------------------------------------------------------

    public function test_redeem_returns_401_when_code_missing(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', [])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'missing_code');
    }

    public function test_redeem_returns_401_for_malformed_code(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => 'short'])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'malformed_code');
    }

    public function test_redeem_returns_401_for_unknown_code(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'code_not_found');
    }

    public function test_redeem_returns_401_on_replay(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(200);

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'code_replayed');
    }

    public function test_redeem_returns_403_for_wrong_module_key(): void
    {
        [$link, $user, $code] = $this->fixtures();

        $this->postJson('/api/sso/backchannel/redeem/wrong-module', ['launch_code' => $code])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'wrong_module');
    }

    public function test_redeem_returns_403_for_inactive_link(): void
    {
        [$link, $user, $code] = $this->fixtures();
        $link->update(['status' => 'inactive']);

        $this->postJson("/api/sso/backchannel/redeem/{$link->module_key}", ['launch_code' => $code])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'inactive_module_link');
    }

    // -------------------------------------------------------------------------
    // Error response shape
    // -------------------------------------------------------------------------

    public function test_error_response_includes_reason_and_ok_false(): void
    {
        $this->postJson('/api/sso/backchannel/redeem/glasspanel', [])
            ->assertStatus(401)
            ->assertJson(['ok' => false])
            ->assertJsonStructure(['ok', 'error', 'reason']);
    }

    // -------------------------------------------------------------------------
    // Disabled
    // -------------------------------------------------------------------------

    public function test_redeem_returns_401_when_backchannel_disabled(): void
    {
        config(['glasshouse_sso.backchannel.enabled' => false]);

        $this->postJson('/api/sso/backchannel/redeem/glasspanel', ['launch_code' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'backchannel_disabled');
    }

    // -------------------------------------------------------------------------
    // Route registered
    // -------------------------------------------------------------------------

    public function test_backchannel_redeem_route_is_registered(): void
    {
        $routes = app('router')->getRoutes();
        $this->assertNotNull(
            $routes->getByName('api.sso.backchannel.redeem'),
            'api.sso.backchannel.redeem route must be registered'
        );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function fixtures(): array
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://panel.test/sso/receive')
            ->forModule('glasspanel', 'GlassPanel')
            ->create([
                'organization_id' => $org->id,
                'auth_mode'       => 'backchannel_launch',
                'status'          => 'active',
            ]);
        $user = User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org->id,
            'email'           => 'bcfeature@example.test',
            'name'            => 'Back-Channel Feature User',
        ]);

        $service = new BackChannelLaunchService();
        $issued  = $service->issueCode($link, $user);

        return [$link, $user, $issued->code];
    }
}
