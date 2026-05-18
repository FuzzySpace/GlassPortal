<?php

namespace GlassHouse\PortalAuth\Tests;

use GlassHouse\PortalAuth\Replay\ArrayReplayStore;
use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Standalone unit tests for SignedLaunchVerifier.
 * No Laravel required — runs with plain PHPUnit.
 */
class SignedLaunchVerifierStandaloneTest extends TestCase
{
    private const SECRET     = 'standalone-test-secret-long-enough-for-hmac-256';
    private const MODULE_KEY = 'glasspanel';
    private const ISSUER     = 'glassportal';

    private SignedLaunchVerifier  $verifier;
    private ArrayReplayStore      $replayStore;
    private SignedLaunchTokenParser $parser;

    protected function setUp(): void
    {
        $this->parser      = new SignedLaunchTokenParser();
        $this->replayStore = new ArrayReplayStore();
        $this->verifier    = $this->makeVerifier(self::SECRET);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeVerifier(
        string $secret,
        array $perModule = [],
        array $keyMap = [],
    ): SignedLaunchVerifier {
        return new SignedLaunchVerifier(
            secretResolver: new ModuleSecretResolver($secret, $perModule, $keyMap),
            replayStore:    $this->replayStore,
            parser:         $this->parser,
            issuer:         self::ISSUER,
            clockSkew:      30,
            replayTtl:      600,
        );
    }

    private function buildToken(
        array  $overrides = [],
        string $secret    = self::SECRET,
        string $kid       = '',
    ): string {
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

        $h   = $this->parser->encode(json_encode($headerData));
        $p   = $this->parser->encode(json_encode($claims));
        $sig = $this->parser->hmacB64("{$h}.{$p}", $secret);

        return "{$h}.{$p}.{$sig}";
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_valid_token_verifies_successfully(): void
    {
        $result = $this->verifier->verify($this->buildToken(), self::MODULE_KEY);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
        $this->assertNotNull($result->context);
        $this->assertSame('42', $result->context->userId);
        $this->assertSame(self::MODULE_KEY, $result->context->audience);
        $this->assertSame(self::ISSUER, $result->context->issuer);
    }

    public function test_context_is_populated_on_success(): void
    {
        $result = $this->verifier->verify($this->buildToken(), self::MODULE_KEY);

        $this->assertSame('user@example.com', $result->context->email);
        $this->assertSame('Test User', $result->context->name);
        $this->assertSame('7', $result->context->orgId);
        $this->assertSame('3', $result->context->moduleLinkId);
    }

    // =========================================================================
    // Security: no token leak in result
    // =========================================================================

    public function test_raw_token_not_stored_in_result(): void
    {
        $token  = $this->buildToken();
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $serialized = serialize($result);
        $this->assertStringNotContainsString($token, $serialized,
            'Raw token must never appear in serialized result');
    }

    public function test_secret_not_stored_in_result(): void
    {
        $result     = $this->verifier->verify($this->buildToken(), self::MODULE_KEY);
        $serialized = serialize($result);

        $this->assertStringNotContainsString(self::SECRET, $serialized,
            'Signing secret must never appear in serialized result');
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
        $this->assertSame('malformed_token',
            $this->verifier->verify('part1.part2', self::MODULE_KEY)->reason);
    }

    public function test_missing_required_claim_returns_malformed(): void
    {
        $now    = time();
        $claims = [
            'iss' => self::ISSUER, 'aud' => self::MODULE_KEY,
            'sub' => '1', 'org' => '1', 'mid' => '1', 'email' => 'u@e.com',
            'iat' => $now, 'exp' => $now + 60, 'nonce' => 'n',
            // jti deliberately omitted
        ];
        $h   = $this->parser->encode(json_encode(['alg' => 'HS256', 'typ' => 'SLP']));
        $p   = $this->parser->encode(json_encode($claims));
        $sig = $this->parser->hmacB64("{$h}.{$p}", self::SECRET);

        $result = $this->verifier->verify("{$h}.{$p}.{$sig}", self::MODULE_KEY);

        $this->assertSame('malformed_token', $result->reason);
    }

    // =========================================================================
    // Signature failures
    // =========================================================================

    public function test_tampered_payload_fails_signature(): void
    {
        $token  = $this->buildToken();
        $parts  = explode('.', $token);
        $parts[1] = $this->parser->encode(json_encode(['aud' => 'evil-module']));

        $result = $this->verifier->verify(implode('.', $parts), self::MODULE_KEY);

        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_wrong_secret_fails(): void
    {
        $token  = $this->buildToken(secret: 'completely-different-secret-for-wrong-key');
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertSame('invalid_signature', $result->reason);
    }

    public function test_missing_secret_returns_secret_missing(): void
    {
        $verifier = $this->makeVerifier('');   // empty global secret
        $result   = $verifier->verify($this->buildToken(), self::MODULE_KEY);

        $this->assertSame('secret_missing', $result->reason);
    }

    // =========================================================================
    // Audience
    // =========================================================================

    public function test_wrong_audience_is_rejected(): void
    {
        $token  = $this->buildToken(['aud' => 'aria']);
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_audience', $result->reason);
    }

    public function test_token_is_accepted_for_correct_audience(): void
    {
        $token  = $this->buildToken(['aud' => 'aria']);
        $result = $this->verifier->verify($token, 'aria');

        $this->assertTrue($result->ok);
    }

    // =========================================================================
    // Expiry
    // =========================================================================

    public function test_expired_token_is_rejected(): void
    {
        $token  = $this->buildToken(['exp' => time() - 200]);
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertSame('expired_token', $result->reason);
    }

    public function test_token_within_clock_skew_is_accepted(): void
    {
        $token  = $this->buildToken(['exp' => time() - 10]);  // expired 10s ago, skew=30
        $result = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($result->ok);
    }

    // =========================================================================
    // Replay detection
    // =========================================================================

    public function test_second_use_of_same_token_is_replay(): void
    {
        $token  = $this->buildToken();
        $first  = $this->verifier->verify($token, self::MODULE_KEY);
        $second = $this->verifier->verify($token, self::MODULE_KEY);

        $this->assertTrue($first->ok);
        $this->assertFalse($second->ok);
        $this->assertSame('replay_detected', $second->reason);
    }

    public function test_two_distinct_tokens_both_verify(): void
    {
        $t1 = $this->buildToken(['jti' => bin2hex(random_bytes(8))]);
        $t2 = $this->buildToken(['jti' => bin2hex(random_bytes(8))]);

        $this->assertTrue($this->verifier->verify($t1, self::MODULE_KEY)->ok);
        $this->assertTrue($this->verifier->verify($t2, self::MODULE_KEY)->ok);
    }

    // =========================================================================
    // Per-module secret resolution
    // =========================================================================

    public function test_per_module_secret_used_instead_of_global(): void
    {
        $moduleSecret = 'per-module-secret-long-enough-for-hmac-256-test';
        $token    = $this->buildToken(secret: $moduleSecret);
        $verifier = $this->makeVerifier(
            secret:    'global-that-must-not-match',
            perModule: [self::MODULE_KEY => $moduleSecret],
        );

        $this->assertTrue($verifier->verify($token, self::MODULE_KEY)->ok);
    }

    public function test_global_fallback_when_no_per_module_secret(): void
    {
        $this->assertTrue(
            $this->verifier->verify($this->buildToken(), self::MODULE_KEY)->ok
        );
    }

    public function test_kid_in_header_selects_correct_key(): void
    {
        $kidSecret = 'kid-v2-secret-long-enough-for-hmac-sha256-test';
        $token     = $this->buildToken(secret: $kidSecret, kid: 'v2');
        $verifier  = $this->makeVerifier(
            secret: 'global-secret',
            keyMap: ['v2' => $kidSecret],
        );

        $this->assertTrue($verifier->verify($token, self::MODULE_KEY)->ok);
    }

    public function test_per_module_secret_takes_priority_over_kid(): void
    {
        $moduleSecret = 'per-module-wins-over-kid-long-enough-for-hmac';
        $kidSecret    = 'kid-v1-that-should-not-be-used-in-this-test-x';
        $token        = $this->buildToken(secret: $moduleSecret, kid: 'v1');
        $verifier     = $this->makeVerifier(
            secret:    'global',
            perModule: [self::MODULE_KEY => $moduleSecret],
            keyMap:    ['v1' => $kidSecret],
        );

        $this->assertTrue($verifier->verify($token, self::MODULE_KEY)->ok);
    }
}
