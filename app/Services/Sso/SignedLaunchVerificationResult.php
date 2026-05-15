<?php

namespace App\Services\Sso;

use App\Data\Sso\VerifiedLaunchContext;

/**
 * Normalized result of a signed launch token verification attempt.
 *
 * Success path  : ok=true,  reason='ok',          safeContext populated
 * Failure path  : ok=false, reason=<code string>, safeContext=null
 *
 * Reason codes (failure):
 *   missing_token       — no token submitted
 *   malformed_token     — wrong number of parts or unparsable payload
 *   invalid_signature   — HMAC check failed, issuer mismatch, or missing claims
 *   expired_token       — exp + clock_skew is in the past
 *   wrong_audience      — aud claim does not match expected module key
 *   replay_detected     — JTI was already consumed (or was never issued here)
 *   secret_missing      — GLASSPORTAL_SIGNED_LAUNCH_SECRET is not configured
 *   inactive_module_link — module link is suspended or deleted (caller-supplied)
 *   organization_mismatch — org claim does not match caller's expected org (caller-supplied)
 *
 * Security: the original token string is never stored in this object.
 */
readonly class SignedLaunchVerificationResult
{
    private function __construct(
        public bool                   $ok,
        public string                 $reason,
        public ?array                 $claims,
        public ?string                $jti,
        public ?int                   $expiresAt,
        public ?VerifiedLaunchContext $safeContext,
    ) {}

    public static function success(VerifiedLaunchContext $context): self
    {
        return new self(
            ok:          true,
            reason:      'ok',
            claims:      $context->toArray(),
            jti:         $context->jti,
            expiresAt:   $context->expiresAt,
            safeContext: $context,
        );
    }

    public static function failure(string $reason): self
    {
        return new self(
            ok:          false,
            reason:      $reason,
            claims:      null,
            jti:         null,
            expiresAt:   null,
            safeContext: null,
        );
    }
}
