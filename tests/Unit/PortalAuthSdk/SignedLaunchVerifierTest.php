<?php

namespace Tests\Unit\PortalAuthSdk;

use GlassHouse\PortalAuth\Replay\ArrayReplayStore;
use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use Tests\TestCase;

/**
 * Unit tests for the SDK SignedLaunchVerifier.
 *
 * All tests use ArrayReplayStore (in-memory) and an explicit secret resolver
 * so they run without a database, cache, or Laravel bootstrap beyond TestCase.
 */
class SignedLaunchVerifierTest extends TestCase
{
    private const SECRET     = 'test-signing-secret-long-enough-for-hmac-256';
    private const MODULE_KEY = 'glasspanel';
    private const ISSUER     = 'glassportal';

    private SignedLaunchVerifier  $verifier;
    private ArrayReplayStore      $replayStore;
    private SignedLaunchTokenParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser      = new SignedLaunchTokenParser();
        $this->replayStore = new ArrayReplayStore();
        $this->verifier    = $this->makeVerifier(self::SECRET);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeVerifier(string $secret, array $perModule = [], array $keyMap = []): SignedLaunchVerifier
    {
        return new SignedLaunchVerifier(
            secretResolver: new ModuleSecretResolver($secret, $perModule, $keyMap),
            replayStore:    $this->replayStore,
            parser:         $this->parser,
            issuer:         self::ISSUER,
            clockSkew:      30,
            replayTtl:      600,
        );
    }

    private function buildToken(array $overrides = [], string $secret = self::SECRET, string $kid = ''): string
    {
        $now    = time();
        $claims = array_merge([
            'iss'   => self::ISSUER,
            'aud'   => self::MODULE_KEY,
            'sub'   => '42',
            'org'   => '7',
            'mid'   => '3',
            'email' => 'user@example.com',
            'name'  => 'Test User',
            'role'  => 'customer',
            'iat'   => $now,
            'exp'   => $now + 60,
            'nonce' => bin2hex(random_bytes(8)),
            'jti'   => bin2hex(random_bytes(8)),
        ], $overrides);

        $headerData = ['alg' => 'HS256', 'typ' => 'SLP'];
        if ($kid !== '') {
            $headerData['kid'] = $kid;
        }

        $headerB64  = $this->parser->encode(json_encode($headerData));
        $payloadB64 = $this->parser->encode(json_encode($claims));
        $sig        = $this->parser->hmacB64("{$headerB64}.{$payloadB64}", $secret);

        return "{$headerB64}.{$payloadB64}.{$sig}";
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_valid_token_verifies_successfully(): void
    {
        $token  = $this->buildToken();
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
        $this->assertNotNull($result->context);
        $this->assertSame('42', $result->context->userId);
        $this->assertSame('7', $result->context->orgId);
        $this->assertSame(self::MODULE_KEY, $result->context->audience);
    }

    public function test_context_is_not_null_on_success(): void
    {
        $token  = $this->buildToken();
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertNotNull($result->context);
        $this->assertSame('user@example.com', $result->context->email);
        $this->assertSame('Test User', $result->context->name);
    }

    public function test_raw_token_not_present_in_result(): void
    {
        $token  = $this->buildToken();
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        // The result object must not carry the raw token
        $serialized = serialize($result);
        $this->assertStringNotContainsString($token, $serialized);
    }

    // =========================================================================
    // Structural failures
    // =========================================================================

    public function test_empty_token_returns_missing_token(): void
    {
        $result = $this->verifier->verify('', self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('missing_token', $result->reason);
        $this->assertNull($result->context);
    }

    public function test_two_part_token_returns_malformed(): void
    {
        $result = $this->verifier->verify('part1.part2', self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('malformed_token', $result->reason);
    }

    public function test_missing_required_claim_returns_malformed(): void
    {
        // Remove 'jti' claim
        $token  = $this->buildToken(['jti' => null]);
        // The claim key still exists with null; use an alternative: omit via filter
        // Build manually without 'jti'
        $now    = time();
        $claims = [
            'iss'   => self::ISSUER,
            'aud'   => self::MODULE_KEY,
            'sub'   => '42',
            'org'   => '7',
            'mid'   => '3',
            'email' => 'user@example.com',
            'iat'   => $now,
            'exp'   => $now + 60,
            'nonce' => 'abc123',
            // jti deliberately omitted
        ];
        $headerB64  = $this->parser->encode(json_encode(['alg' => 'HS256', 'typ' => 'SLP']));
        $payloadB64 = $this->parser->encode(json_encode($claims));
        $sig        = $this->parser->hmacB64("{$headerB64}.{$payloadB64}", self::SECRET);
        $token      = "{$headerB64}.{$payloadB64}.{$sig}";

        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('malformed_token', $result->reason);
    }

    // =========================================================================
    // Signature failures
    // =========================================================================

    public function test_tampered_payload_fails_signature(): void
    {
        $token  = $this->buildToken();
        $parts  = explode('.', $token);
        // Tamper with the payload
        $parts[1] = $this->parser->encode(json_encode(['aud' => 'evil', 'sub' => '999']));
        $tampered = implode('.', $parts);

        $result = $this->verifier->verify($tampered, self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_wrong_secret_fails_signature(): void
    {
        $token  = $this->buildToken(secret: 'wrong-secret-entirely-different-key-abc');
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_missing_secret_returns_secret_missing(): void
    {
        $verifier = $this->makeVerifier('');  // empty global secret
        $token    = $this->buildToken();

        $result = $verifier->verify($token, self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('secret_missing', $result->reason);
    }

    // =========================================================================
    // Audience
    // =========================================================================

    public function test_wrong_audience_fails_verification(): void
    {
        $token  = $this->buildToken(['aud' => 'aria']);
        $result = $this->verifier->verify($token, self::MODULE_KEY); // expects glasspanel

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_audience', $result->reason);
    }

    // =========================================================================
    // Expiry
    // =========================================================================

    public function test_expired_token_fails_verification(): void
    {
        // Token expired 200 seconds ago, well outside 30s clock skew
        $token  = $this->buildToken(['exp' => time() - 200]);
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('expired_token', $result->reason);
    }

    public function test_token_within_clock_skew_still_valid(): void
    {
        // Expired 10 seconds ago but clock_skew is 30
        $token  = $this->buildToken(['exp' => time() - 10]);
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
    }

    // =========================================================================
    // Replay detection
    // =========================================================================

    public function test_second_verification_of_same_token_is_replay(): void
    {
        $token = $this->buildToken();

        $first  = $this->verifier->verify($token, self::MODULE_KEY);
        $second = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($first->ok);
        $this->assertFalse($second->ok);
        $this->assertSame('replay_detected', $second->reason);
    }

    public function test_two_different_tokens_both_verify(): void
    {
        $t1 = $this->buildToken(['jti' => bin2hex(random_bytes(8))]);
        $t2 = $this->buildToken(['jti' => bin2hex(random_bytes(8))]);

        $r1 = $this->verifier->verify($t1, self::MODULE_KEY);
        $r2 = $this->verifier->verify($t2, self::MODULE_KEY);

        $this->assertTrue($r1->ok);
        $this->assertTrue($r2->ok);
    }

    // =========================================================================
    // Per-module secrets
    // =========================================================================

    public function test_per_module_secret_is_used_for_verification(): void
    {
        $moduleSecret = 'per-module-secret-for-glasspanel-long-enough';
        $token        = $this->buildToken(secret: $moduleSecret);
        $verifier     = $this->makeVerifier(
            secret:    'global-secret-that-should-not-match',
            perModule: [self::MODULE_KEY => $moduleSecret],
        );

        $result = $verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
    }

    public function test_global_fallback_used_when_no_per_module_secret(): void
    {
        $token  = $this->buildToken(secret: self::SECRET);
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
    }

    public function test_kid_based_secret_is_used_in_verification(): void
    {
        $kidSecret = 'kid-v2-secret-long-enough-for-hmac-sha256-test';
        $token     = $this->buildToken(secret: $kidSecret, kid: 'v2');
        $verifier  = $this->makeVerifier(
            secret: 'global-secret',
            keyMap: ['v2' => $kidSecret],
        );

        $result = $verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
    }

    public function test_per_module_secret_overrides_kid(): void
    {
        $moduleSecret = 'per-module-overrides-kid-long-enough-for-hmac';
        $kidSecret    = 'kid-v1-secret-long-enough-for-hmac-256-tes';
        $token        = $this->buildToken(secret: $moduleSecret, kid: 'v1');
        $verifier     = $this->makeVerifier(
            secret:    'global',
            perModule: [self::MODULE_KEY => $moduleSecret],
            keyMap:    ['v1' => $kidSecret],
        );

        $result = $verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
    }

    // =========================================================================
    // Query-string token rejection (middleware-level behavior documented)
    // =========================================================================

    public function test_verifier_itself_does_not_inspect_request_source(): void
    {
        // The verifier has no knowledge of HTTP — it processes a plain token string.
        // Query-string rejection is enforced by the middleware layer.
        // This test documents that distinction.
        $token  = $this->buildToken();
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok, 'Verifier does not reject tokens based on transport; middleware does.');
    }
}
