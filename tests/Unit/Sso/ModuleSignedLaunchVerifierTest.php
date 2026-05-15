<?php

namespace Tests\Unit\Sso;

use App\Data\Sso\VerifiedLaunchContext;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\ModuleSignedLaunchVerifier;
use App\Services\Sso\SignedLaunchTokenService;
use App\Services\Sso\SignedLaunchVerificationResult;
use App\Services\Sso\SignedLaunchVerifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleSignedLaunchVerifierTest extends TestCase
{
    use RefreshDatabase;

    private SignedLaunchTokenService $tokenService;
    private ModuleSignedLaunchVerifier $modVerifier;
    private string $secret = 'module-verifier-test-secret-long-enough-for-hmac-sha256';

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.signing_secret'          => $this->secret]);
        config(['glasshouse_sso.issuer'                  => 'glassportal-test']);
        config(['glasshouse_sso.default_ttl_seconds'     => 60]);
        config(['glasshouse_sso.max_ttl_seconds'         => 300]);
        config(['glasshouse_sso.clock_skew_seconds'      => 30]);
        config(['glasshouse_sso.nonce_cache_ttl_seconds' => 600]);
        config(['glasshouse_sso.key_id'                  => '']);
        config(['glasshouse_sso.keys'                    => []]);

        $this->tokenService = new SignedLaunchTokenService();
        $this->modVerifier  = new ModuleSignedLaunchVerifier(
            new SignedLaunchVerifierService($this->tokenService)
        );
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    public function test_valid_token_returns_ok_result(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $result = $this->modVerifier->verify($token, $link->module_key);

        $this->assertInstanceOf(SignedLaunchVerificationResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
    }

    public function test_success_result_has_safe_context(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $result = $this->modVerifier->verify($token, $link->module_key);

        $this->assertInstanceOf(VerifiedLaunchContext::class, $result->safeContext);
        $this->assertSame($link->module_key, $result->safeContext->audience);
        $this->assertSame((string) $user->id, $result->safeContext->userId);
    }

    public function test_success_result_has_expected_scalar_fields(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $result = $this->modVerifier->verify($token, $link->module_key);

        $this->assertNotEmpty($result->jti);
        $this->assertGreaterThan(time(), $result->expiresAt);
        $this->assertIsArray($result->claims);
        foreach (['iss', 'aud', 'sub', 'org', 'email', 'role', 'jti', 'exp'] as $key) {
            $this->assertArrayHasKey($key, $result->claims, "Missing claim key: {$key}");
        }
    }

    public function test_success_result_does_not_contain_signing_secret(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $result = $this->modVerifier->verify($token, $link->module_key);

        $serialised = json_encode([
            'claims'     => $result->claims,
            'jti'        => $result->jti,
            'expiresAt'  => $result->expiresAt,
            'safeContext' => $result->safeContext?->toArray(),
        ]);
        $this->assertStringNotContainsString($this->secret, $serialised);
    }

    public function test_success_result_does_not_contain_raw_token(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $result = $this->modVerifier->verify($token, $link->module_key);

        $serialised = json_encode([
            'claims'      => $result->claims,
            'safeContext' => $result->safeContext?->toArray(),
        ]);
        $this->assertStringNotContainsString($token, $serialised);
    }

    // -------------------------------------------------------------------------
    // Failure paths — reason codes
    // -------------------------------------------------------------------------

    public function test_empty_token_returns_missing_token(): void
    {
        $result = $this->modVerifier->verify('', 'glasspanel');

        $this->assertFalse($result->ok);
        $this->assertSame('missing_token', $result->reason);
    }

    public function test_missing_secret_returns_secret_missing(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);
        $verifier = new ModuleSignedLaunchVerifier(
            new SignedLaunchVerifierService(new SignedLaunchTokenService())
        );

        $result = $verifier->verify('any.token.string', 'glasspanel');

        $this->assertFalse($result->ok);
        $this->assertSame('secret_missing', $result->reason);
    }

    public function test_tampered_token_returns_invalid_signature(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $parts  = explode('.', $token);
        $parts[1] .= 'tampered';
        $result = $this->modVerifier->verify(implode('.', $parts), $link->module_key);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_expired_token_returns_expired_token(): void
    {
        config(['glasshouse_sso.default_ttl_seconds' => -120]);
        config(['glasshouse_sso.clock_skew_seconds'  => 0]);
        $svc      = new SignedLaunchTokenService();
        $verifier = new ModuleSignedLaunchVerifier(new SignedLaunchVerifierService($svc));

        [$link, $user] = $this->fixtures();
        $token  = $svc->generate($link, $user)['token'];
        $result = $verifier->verify($token, $link->module_key);

        $this->assertFalse($result->ok);
        $this->assertSame('expired_token', $result->reason);
    }

    public function test_wrong_audience_returns_wrong_audience(): void
    {
        [$link, $user] = $this->fixtures();
        $token  = $this->tokenService->generate($link, $user)['token'];
        $result = $this->modVerifier->verify($token, 'completely-wrong-module-key');

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_audience', $result->reason);
    }

    public function test_replayed_token_returns_replay_detected(): void
    {
        [$link, $user] = $this->fixtures();
        $token = $this->tokenService->generate($link, $user)['token'];

        $first = $this->modVerifier->verify($token, $link->module_key);
        $this->assertTrue($first->ok);

        $second = $this->modVerifier->verify($token, $link->module_key);
        $this->assertFalse($second->ok);
        $this->assertSame('replay_detected', $second->reason);
    }

    public function test_malformed_token_returns_malformed_token(): void
    {
        $result = $this->modVerifier->verify('not.a.valid.four.parts', 'glasspanel');

        $this->assertFalse($result->ok);
        $this->assertSame('malformed_token', $result->reason);
    }

    // -------------------------------------------------------------------------
    // Failure result shape
    // -------------------------------------------------------------------------

    public function test_failure_result_has_null_safe_context(): void
    {
        $result = $this->modVerifier->verify('', 'glasspanel');

        $this->assertNull($result->safeContext);
        $this->assertNull($result->claims);
        $this->assertNull($result->jti);
        $this->assertNull($result->expiresAt);
    }

    // -------------------------------------------------------------------------
    // Cache probe
    // -------------------------------------------------------------------------

    public function test_cache_probe_returns_true_when_cache_working(): void
    {
        $this->assertTrue($this->modVerifier->isCacheUsable());
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
            'email'           => 'modverifier@example.test',
            'name'            => 'Module Verifier User',
        ]);
        return [$link, $user];
    }
}
