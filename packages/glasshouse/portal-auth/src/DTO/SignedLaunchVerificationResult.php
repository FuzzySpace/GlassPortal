<?php

namespace GlassHouse\PortalAuth\DTO;

/**
 * Result of a signed launch token verification attempt.
 *
 * Success  : ok=true,  reason='ok', context populated.
 * Failure  : ok=false, reason=<code>, context=null.
 *
 * Reason codes (failure):
 *   missing_token       — no token provided
 *   malformed_token     — wrong number of parts or unparsable payload
 *   invalid_signature   — HMAC check failed, issuer mismatch, or missing claims
 *   expired_token       — exp claim is in the past (after clock skew tolerance)
 *   wrong_audience      — aud does not match expected module key
 *   replay_detected     — JTI was already consumed
 *   secret_missing      — no signing secret is configured
 *
 * Security: the raw token string is never stored in this object.
 */
readonly class SignedLaunchVerificationResult
{
    private function __construct(
        public bool                   $ok,
        public string                 $reason,
        public ?VerifiedLaunchContext $context,
    ) {}

    public static function success(VerifiedLaunchContext $context): self
    {
        return new self(ok: true, reason: 'ok', context: $context);
    }

    public static function failure(string $reason): self
    {
        return new self(ok: false, reason: $reason, context: null);
    }
}
