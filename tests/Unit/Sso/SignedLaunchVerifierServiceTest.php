<?php

namespace Tests\Unit\Sso;

use App\Data\Sso\VerifiedLaunchContext;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\SignedLaunchTokenService;
use App\Services\Sso\SignedLaunchVerifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedLaunchVerifierServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignedLaunchTokenService $tokenService;
    private SignedLaunchVerifierService $verifier;
    private string $secret = 'verifier-test-secret-long-enough-for-hmac-sha256-testing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.signing_secret'       => $this->secret]);
        config(['glasshouse_sso.issuer'               => 'glassportal-test']);
        config(['glasshouse_sso.default_ttl_seconds'  => 60]);
        config(['glasshouse_sso.max_ttl_seconds'      => 300]);
        config(['glasshouse_sso.clock_skew_seconds'   => 30]);
        config(['glasshouse_sso.nonce_cache_ttl_seconds' => 600]);
        config(['glasshouse_sso.key_id'               => '']);
        config(['glasshouse_sso.keys'                 => []]);

        $this->tokenService = new SignedLaunchTokenService();
        $this->verifier     = new SignedLaunchVerifierService($this->tokenService);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_verify_returns_verified_launch_context(): void
    {
        [$link, $user] = $this->fixtures();
        $result  = $this->tokenService->generate($link, $user);
        $context = $this->verifier->verify($result['token'], $link->module_key);

        $this->assertInstanceOf(VerifiedLaunchContext::class, $context);
    }

    public function test_verified_context_has_correct_claims(): void
    {
        [$link, $user] = $this->fixtures();
        $result  = $this->tokenService->generate($link, $user);
        $context = $this->verifier->verify($result['token'], $link->module_key);

        $this->assertSame('glassportal-test', $context->issuer);
        $this->assertSame($link->module_key, $context->audience);
        $this->assertSame((string) $user->id, $context->userId);
        $this->assertSame((string) $link->organization_id, $context->orgId);
        $this->assertSame((string) $link->id, $context->moduleLinkId);
        $this->assertSame($user->email, $context->email);
        $this->assertSame($user->name, $context->name);
        $this->assertNotEmpty($context->jti);
        $this->assertNotEmpty($context->nonce);
        $this->assertGreaterThan(0, $context->expiresAt);
    }

    public function test_verified_context_to_array_contains_all_claims(): void
    {
        [$link, $user] = $this->fixtures();
        $result  = $this->tokenService->generate($link, $user);
        $context = $this->verifier->verify($result['token'], $link->module_key);
        $arr     = $context->toArray();

        foreach (['iss', 'aud', 'sub', 'org', 'mid', 'email', 'name', 'role', 'iat', 'exp', 'nonce', 'jti'] as $key) {
            $this->assertArrayHasKey($key, $arr, "Missing key: {$key}");
        }
    }

    public function test_verified_context_does_not_contain_signing_secret(): void
    {
        [$link, $user] = $this->fixtures();
        $result  = $this->tokenService->generate($link, $user);
        $context = $this->verifier->verify($result['token'], $link->module_key);

        $this->assertStringNotContainsString($this->secret, json_encode($context->toArray()));
    }

    // -------------------------------------------------------------------------
    // Failure propagation
    // -------------------------------------------------------------------------

    public function test_tampered_token_throws(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->tokenService->generate($link, $user);
        $parts  = explode('.', $result['token']);
        $parts[1] .= 'tampered';
        $tampered = implode('.', $parts);

        $this->expectException(\InvalidArgumentException::class);
        $this->verifier->verify($tampered, $link->module_key);
    }

    public function test_wrong_audience_throws(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->tokenService->generate($link, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->verifier->verify($result['token'], 'wrong-module');
    }

    public function test_expired_token_throws(): void
    {
        config(['glasshouse_sso.default_ttl_seconds' => -120]); // issued 120s in the past
        config(['glasshouse_sso.clock_skew_seconds'  => 0]);
        $svc     = new SignedLaunchTokenService();
        $verifier = new SignedLaunchVerifierService($svc);

        [$link, $user] = $this->fixtures();
        $result = $svc->generate($link, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        $verifier->verify($result['token'], $link->module_key);
    }

    public function test_replay_throws_on_second_verify(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->tokenService->generate($link, $user);

        $this->verifier->verify($result['token'], $link->module_key);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('replay');
        $this->verifier->verify($result['token'], $link->module_key);
    }

    // -------------------------------------------------------------------------
    // KID support
    // -------------------------------------------------------------------------

    public function test_token_without_kid_verifies_with_primary_secret(): void
    {
        // Explicitly no key_id — tokens have no kid claim
        config(['glasshouse_sso.key_id' => '']);
        $svc     = new SignedLaunchTokenService();
        $verifier = new SignedLaunchVerifierService($svc);

        [$link, $user] = $this->fixtures();
        $result  = $svc->generate($link, $user);
        $context = $verifier->verify($result['token'], $link->module_key);

        $this->assertSame($link->module_key, $context->audience);
    }

    public function test_token_with_kid_verifies_via_keys_map(): void
    {
        config(['glasshouse_sso.key_id' => 'v1']);
        config(['glasshouse_sso.keys'   => ['v1' => $this->secret]]);

        $svc     = new SignedLaunchTokenService();
        $verifier = new SignedLaunchVerifierService($svc);

        [$link, $user] = $this->fixtures();
        $result = $svc->generate($link, $user);

        // Confirm kid is in the token header
        $headerJson = base64_decode(str_pad(
            strtr(explode('.', $result['token'])[0], '-_', '+/'),
            strlen(explode('.', $result['token'])[0]) % 4 === 0 ? strlen(explode('.', $result['token'])[0]) : strlen(explode('.', $result['token'])[0]) + (4 - strlen(explode('.', $result['token'])[0]) % 4), '='));
        $header = json_decode($headerJson, true);
        $this->assertSame('v1', $header['kid'] ?? null);

        $context = $verifier->verify($result['token'], $link->module_key);
        $this->assertSame($link->module_key, $context->audience);
    }

    public function test_token_with_unknown_kid_falls_back_to_primary_secret(): void
    {
        // Generate with kid=v1 (secret = primary secret), keys map empty
        config(['glasshouse_sso.key_id' => 'v1']);
        config(['glasshouse_sso.keys'   => []]);

        $svc = new SignedLaunchTokenService();
        [$link, $user] = $this->fixtures();
        $result = $svc->generate($link, $user);

        // Verify also has empty keys map — falls back to signing_secret
        $verifier = new SignedLaunchVerifierService($svc);
        $context  = $verifier->verify($result['token'], $link->module_key);

        $this->assertSame($link->module_key, $context->audience);
    }

    public function test_token_with_kid_fails_when_wrong_secret_in_keys_map(): void
    {
        config(['glasshouse_sso.key_id' => 'v1']);
        config(['glasshouse_sso.keys'   => ['v1' => $this->secret]]);
        $svc = new SignedLaunchTokenService();
        [$link, $user] = $this->fixtures();
        $result = $svc->generate($link, $user);

        // Now reconfigure with a different secret in the keys map
        config(['glasshouse_sso.keys' => ['v1' => 'completely-wrong-secret-that-is-long-enough']]);
        $wrongSvc     = new SignedLaunchTokenService();
        $wrongVerifier = new SignedLaunchVerifierService($wrongSvc);

        $this->expectException(\InvalidArgumentException::class);
        $wrongVerifier->verify($result['token'], $link->module_key);
    }

    // -------------------------------------------------------------------------
    // Helper
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
            'email'           => 'verifier@example.test',
            'name'            => 'Verifier User',
        ]);
        return [$link, $user];
    }
}
