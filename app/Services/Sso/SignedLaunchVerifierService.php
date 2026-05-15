<?php

namespace App\Services\Sso;

use App\Data\Sso\VerifiedLaunchContext;

/**
 * Reusable verifier layer for signed module launch tokens (SLP).
 *
 * Wraps SignedLaunchTokenService::verify() and returns a typed VerifiedLaunchContext.
 * Downstream module middleware should use this service rather than calling the token
 * service directly — it normalizes the raw payload into a typed context object.
 *
 * Security: the raw token is consumed by verify() and never stored or returned.
 * The signing secret is never included in the returned context.
 */
class SignedLaunchVerifierService
{
    public function __construct(private SignedLaunchTokenService $tokenService) {}

    /**
     * Verify a signed launch token for the given module and return a typed context.
     *
     * On success, the JTI is consumed so the token cannot be reused (replay protection).
     *
     * @throws \InvalidArgumentException if signature, claims, expiry, audience, or replay check fails
     */
    public function verify(string $token, string $moduleKey): VerifiedLaunchContext
    {
        $payload = $this->tokenService->verify($token, $moduleKey);

        return VerifiedLaunchContext::fromPayload($payload);
    }
}
