<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\SignedLaunchTokenService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SsoConsumeDevRouteTest extends TestCase
{
    use RefreshDatabase;


    private string $secret = 'dev-route-test-secret-long-enough-for-hmac-sha256-testing';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('glasshouse_sso.signed_launch.secret', $this->secret);
        config(['glasshouse_sso.signed_launch.secret' => $this->secret]);

        app()->forgetInstance(SignedLaunchTokenService::class);

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

        Config::set('glasshouse_sso.signing_secret', $this->secret);
        Config::set('glasshouse_sso.signed_launch.secret', $this->secret);

        config([
            'glasshouse_sso.signing_secret' => $this->secret,
            'glasshouse_sso.signed_launch.secret' => $this->secret,
        ]);

        app()->forgetInstance(SignedLaunchTokenService::class);

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_dev_consume_returns_verified_context_for_valid_token(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $response = $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $token]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
        $response->assertJsonPath('module_key', $link->module_key);
        $response->assertJsonPath('user_id', (string) $user->id);
        $response->assertJsonPath('user_email', $user->email);
        $response->assertJsonPath('user_name', $user->name);
        $response->assertJsonPath('role', UserRole::Customer->value);
        $response->assertJsonStructure(['ok', 'module_key', 'organization_id', 'user_id', 'user_email', 'user_name', 'role', 'jti', 'expires_at']);
    }

    public function test_dev_consume_accepts_launch_token_field(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post("/_dev/sso/consume/{$link->module_key}", ['launch_token' => $token])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_dev_consume_accepts_signed_launch_token_field(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post("/_dev/sso/consume/{$link->module_key}", ['signed_launch_token' => $token])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_token_in_query_string_is_rejected(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        // Token in URL query string must be rejected — it would appear in server logs.
        $this->post("/_dev/sso/consume/{$link->module_key}?slt={$token}", [])
            ->assertStatus(400);
    }

    public function test_token_in_query_string_returns_reason(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post("/_dev/sso/consume/{$link->module_key}?signed_launch_token={$token}", [])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'query_string_token');
    }

    // -------------------------------------------------------------------------
    // Security — response must not leak secrets or raw token
    // -------------------------------------------------------------------------

    public function test_dev_consume_response_does_not_contain_signing_secret(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $response = $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $token]);

        $this->assertStringNotContainsString($this->secret, $response->getContent());
    }

    public function test_dev_consume_response_does_not_contain_raw_token(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $response = $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $token]);

        $this->assertStringNotContainsString($token, $response->getContent());
    }

    // -------------------------------------------------------------------------
    // Method enforcement
    // -------------------------------------------------------------------------

    public function test_get_request_returns_405(): void
    {
        $this->get('/_dev/sso/consume/glasspanel')
            ->assertStatus(405);
    }

    // -------------------------------------------------------------------------
    // Missing / invalid token
    // -------------------------------------------------------------------------

    public function test_dev_consume_returns_401_when_token_missing(): void
    {
        $this->post('/_dev/sso/consume/glasspanel', [])
            ->assertStatus(401);
    }

    public function test_dev_consume_returns_401_for_tampered_token(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->generateToken($link, $user);
        $parts  = explode('.', $token);
        $parts[1] .= 'tampered';
        $tampered = implode('.', $parts);

        $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $tampered])
            ->assertStatus(401);
    }

    public function test_dev_consume_returns_403_for_wrong_module_key(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post('/_dev/sso/consume/wrong-module', ['slt' => $token])
            ->assertStatus(403);
    }

    public function test_dev_consume_returns_401_on_replay(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $token])
            ->assertStatus(200);

        $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $token])
            ->assertStatus(401);
    }

    public function test_dev_consume_returns_401_for_malformed_token(): void
    {
        $this->post('/_dev/sso/consume/glasspanel', ['slt' => 'not.a.valid.four.parts'])
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Error response shape
    // -------------------------------------------------------------------------

    public function test_error_response_includes_reason_field(): void
    {
        $this->post('/_dev/sso/consume/glasspanel', [])
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'reason'])
            ->assertJsonPath('reason', 'missing_token');
    }

    public function test_wrong_audience_error_includes_reason(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post('/_dev/sso/consume/wrong-module', ['slt' => $token])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'wrong_audience');
    }

    // -------------------------------------------------------------------------
    // Env guard — route must be registered in testing environment
    // -------------------------------------------------------------------------

    public function test_dev_route_is_registered_in_testing_environment(): void
    {
        $routes = app('router')->getRoutes();
        $this->assertNotNull(
            $routes->getByName('dev.sso.consume'),
            'dev.sso.consume route must be registered in testing environment'
        );
    }

    // -------------------------------------------------------------------------
    // KID support round-trip via dev route
    // -------------------------------------------------------------------------

    public function test_dev_consume_works_with_kid_in_token(): void
    {
        config(['glasshouse_sso.key_id' => 'v1']);
        config(['glasshouse_sso.keys'   => ['v1' => $this->secret]]);

        [$link, $user] = $this->fixtures();
        $token = $this->generateToken($link, $user);

        $this->post("/_dev/sso/consume/{$link->module_key}", ['slt' => $token])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fixtures(): array
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl('https://panel.test')
            ->forModule('glasspanel', 'GlassPanel')
            ->create(['organization_id' => $org->id, 'auth_mode' => 'signed_launch', 'status' => 'active']);
        $user = User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org->id,
            'email'           => 'devtest@example.test',
            'name'            => 'Dev Test User',
        ]);
        return [$link, $user];
    }

    private function generateToken(OrganizationModuleLink $link, User $user): string
    {
        $svc = new SignedLaunchTokenService();
        return $svc->generate($link, $user)['token'];
    }
}
