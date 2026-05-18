<?php

namespace GlassHouse\PortalAuth\Sso;

use GlassHouse\PortalAuth\Contracts\ReplayStoreInterface;
use GlassHouse\PortalAuth\Contracts\SecretResolverInterface;
use GlassHouse\PortalAuth\DTO\SignedLaunchVerificationResult;
use GlassHouse\PortalAuth\DTO\VerifiedLaunchContext;

/**
 * Framework-free signed launch token verifier.
 *
 * Accepts injected SecretResolverInterface and ReplayStoreInterface so it
 * can be used outside Laravel (e.g., with ArrayReplayStore in tests, or
 * a Redis-backed store in a non-Laravel module).
 *
 * Verification steps:
 *   1. Structural check — exactly 3 dot-separated parts
 *   2. Signature check  — HMAC-SHA256 over header.payload
 *   3. Payload decode   — valid JSON
 *   4. Required claims  — iss, aud, sub, org, mid, email, iat, exp, nonce, jti
 *   5. Audience         — aud matches expected module key
 *   6. Expiry           — exp + clock skew not in the past
 *   7. Replay           — JTI not previously consumed; consumed on success
 *
 * The raw token string is never stored in any result or exception message.
 */
class SignedLaunchVerifier
{
    private const REQUIRED_CLAIMS = ['iss', 'aud', 'sub', 'org', 'mid', 'email', 'iat', 'exp', 'nonce', 'jti'];

    public function __construct(
        private readonly SecretResolverInterface $secretResolver,
        private readonly ReplayStoreInterface    $replayStore,
        private readonly SignedLaunchTokenParser $parser       = new SignedLaunchTokenParser(),
        private readonly string                  $issuer       = 'glassportal',
        private readonly int                     $clockSkew    = 30,
        private readonly int                     $replayTtl    = 600,
    ) {}

    /**
     * Verify a signed launch token for the given module key.
     *
     * Always returns a result — never throws. On success the JTI is consumed.
     */
    public function verify(string $token, string $moduleKey): SignedLaunchVerificationResult
    {
        if ($token === '') {
            return SignedLaunchVerificationResult::failure('missing_token');
        }

        $parts = $this->parser->split($token);
        if ($parts === null) {
            return SignedLaunchVerificationResult::failure('malformed_token');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Resolve secret using KID from header (if present)
        $header = $this->parser->decodeHeader($headerB64);
        $kid    = (string) ($header['kid'] ?? '');
        $secret = $this->secretResolver->resolveForVerification($moduleKey, $kid);

        if ($secret === '') {
            return SignedLaunchVerificationResult::failure('secret_missing');
        }

        // 1. Signature
        $expectedSig = $this->parser->hmacB64("{$headerB64}.{$payloadB64}", $secret);
        if (! hash_equals($expectedSig, $sigB64)) {
            return SignedLaunchVerificationResult::failure('invalid_signature');
        }

        // 2. Payload
        $payload = $this->parser->decodePayload($payloadB64);
        if ($payload === null) {
            return SignedLaunchVerificationResult::failure('malformed_token');
        }

        // 3. Required claims
        foreach (self::REQUIRED_CLAIMS as $claim) {
            if (! array_key_exists($claim, $payload)) {
                return SignedLaunchVerificationResult::failure('malformed_token');
            }
        }

        // 4. Audience
        if ((string) ($payload['aud'] ?? '') !== $moduleKey) {
            return SignedLaunchVerificationResult::failure('wrong_audience');
        }

        // 5. Expiry
        if (time() > ((int) ($payload['exp'] ?? 0) + $this->clockSkew)) {
            return SignedLaunchVerificationResult::failure('expired_token');
        }

        // 6. Replay
        $jti = (string) ($payload['jti'] ?? '');
        if ($this->replayStore->isConsumed($jti)) {
            return SignedLaunchVerificationResult::failure('replay_detected');
        }
        $this->replayStore->consume($jti, $this->replayTtl);

        return SignedLaunchVerificationResult::success(VerifiedLaunchContext::fromPayload($payload));
    }
}
