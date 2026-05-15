<?php

namespace Tests\Unit\Sso;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\SignedLaunchTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SignedLaunchTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignedLaunchTokenService $svc;
    private string $secret = 'test-signing-secret-must-be-long-enough-for-hmac-sha256-testing';

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.signing_secret'       => $this->secret]);
        config(['glasshouse_sso.issuer'               => 'glassportal-test']);
        config(['glasshouse_sso.default_ttl_seconds'  => 60]);
        config(['glasshouse_sso.max_ttl_seconds'      => 300]);
        config(['glasshouse_sso.clock_skew_seconds'   => 30]);
        config(['glasshouse_sso.nonce_cache_ttl_seconds' => 600]);
        $this->svc = new SignedLaunchTokenService();
    }

    // -------------------------------------------------------------------------
    // Token generation
    // -------------------------------------------------------------------------

    public function test_token_is_generated_as_three_part_compact_string(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        $this->assertNotEmpty($result['token']);
        $parts = explode('.', $result['token']);
        $this->assertCount(3, $parts, 'SLP token must have exactly 3 dot-separated parts');
    }

    public function test_token_payload_contains_all_required_claims(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        $preview = $result['payload_preview'];
        foreach (['iss', 'aud', 'sub', 'org', 'mid', 'email', 'name', 'role', 'iat', 'exp', 'nonce', 'jti'] as $claim) {
            $this->assertArrayHasKey($claim, $preview, "Missing claim: {$claim}");
        }
        $this->assertSame('glassportal-test', $preview['iss']);
        $this->assertSame($link->module_key, $preview['aud']);
        $this->assertSame((string) $user->id, $preview['sub']);
        $this->assertSame($user->email, $preview['email']);
    }

    public function test_token_does_not_contain_signing_secret(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        $this->assertStringNotContainsString($this->secret, $result['token']);
        $this->assertStringNotContainsString($this->secret, json_encode($result['payload_preview']));
    }

    public function test_jti_is_stored_in_cache_after_generation(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        $this->assertTrue(Cache::has("signed-launch:issued:{$result['jti']}"));
    }

    public function test_expires_at_reflects_configured_ttl(): void
    {
        [$link, $user] = $this->fixtures();
        $before = time();
        $result = $this->svc->generate($link, $user);
        $after  = time();

        $this->assertGreaterThanOrEqual($before + 60, $result['expires_at']);
        $this->assertLessThanOrEqual($after + 60, $result['expires_at']);
    }

    public function test_generate_throws_when_secret_is_empty(): void
    {
        config(['glasshouse_sso.signing_secret' => '']);
        $svc = new SignedLaunchTokenService();
        [$link, $user] = $this->fixtures();

        $this->expectException(\RuntimeException::class);
        $svc->generate($link, $user);
    }

    // -------------------------------------------------------------------------
    // Token verification
    // -------------------------------------------------------------------------

    public function test_valid_token_can_be_verified(): void
    {
        [$link, $user] = $this->fixtures();
        $result  = $this->svc->generate($link, $user);
        $payload = $this->svc->verify($result['token'], $link->module_key);

        $this->assertSame($link->module_key, $payload['aud']);
        $this->assertSame((string) $user->id, $payload['sub']);
    }

    public function test_tampered_payload_fails_verification(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        // Modify the middle part (payload)
        $parts = explode('.', $result['token']);
        $decoded = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + (4 - strlen($parts[1]) % 4), '=')), true);
        $decoded['role'] = 'owner'; // tamper
        $parts[1] = rtrim(strtr(base64_encode(json_encode($decoded)), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->verify($tampered, $link->module_key);
    }

    public function test_expired_token_fails_verification(): void
    {
        config(['glasshouse_sso.default_ttl_seconds' => -120]); // expired 120s ago
        config(['glasshouse_sso.clock_skew_seconds'  => 0]);
        $svc = new SignedLaunchTokenService();

        [$link, $user] = $this->fixtures();
        $result = $svc->generate($link, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        $svc->verify($result['token'], $link->module_key);
    }

    public function test_wrong_audience_fails_verification(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('audience');
        $this->svc->verify($result['token'], 'wrong_module_key');
    }

    public function test_wrong_issuer_fails_verification(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        // Verify against a service configured with a different issuer
        config(['glasshouse_sso.issuer' => 'different-portal']);
        $otherSvc = new SignedLaunchTokenService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('issuer');
        $otherSvc->verify($result['token'], $link->module_key);
    }

    public function test_malformed_token_fails_verification(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->verify('not.a.valid.token.parts', 'any_module');
    }

    public function test_replayed_token_fails_on_second_verify(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        // First verification succeeds and consumes the JTI
        $this->svc->verify($result['token'], $link->module_key);

        // Second verification must fail (replay detected)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('replay');
        $this->svc->verify($result['token'], $link->module_key);
    }

    public function test_wrong_secret_fails_verification(): void
    {
        [$link, $user] = $this->fixtures();
        $result = $this->svc->generate($link, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->verify($result['token'], $link->module_key, 'wrong-secret-entirely');
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
            'email'           => 'test@example.test',
            'name'            => 'Test User',
        ]);
        return [$link, $user];
    }
}
