<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Cache;

/**
 * Module-side signed launch verification service.
 *
 * Wraps SignedLaunchVerifierService to produce a normalized result object
 * instead of throwing exceptions. Designed for use at application boundaries
 * (middleware, controllers) where callers need to branch on failure reason
 * without catching exceptions.
 *
 * Failure reasons are mapped to the verification contract codes:
 *   missing_token, malformed_token, invalid_signature, expired_token,
 *   wrong_audience, replay_detected, secret_missing
 *
 * The original token string is never stored in any result or log output.
 */
class ModuleSignedLaunchVerifier
{
    public function __construct(private SignedLaunchVerifierService $verifier) {}

    /**
     * Verify a signed launch token for the given module key.
     *
     * Returns a SignedLaunchVerificationResult — never throws.
     * On success, the JTI is consumed (replay protection).
     */
    public function verify(string $token, string $moduleKey): SignedLaunchVerificationResult
    {
        if ($token === '') {
            return SignedLaunchVerificationResult::failure('missing_token');
        }

        // Fail fast with a clear reason before attempting crypto.
        if ((string) config('glasshouse_sso.signing_secret', '') === '') {
            return SignedLaunchVerificationResult::failure('secret_missing');
        }

        try {
            $context = $this->verifier->verify($token, $moduleKey);
            return SignedLaunchVerificationResult::success($context);
        } catch (\InvalidArgumentException $e) {
            return SignedLaunchVerificationResult::failure($this->mapReason($e->getMessage()));
        }
    }

    /**
     * Probe whether the replay-protection cache is writable and readable.
     * Returns false on any cache error — caller should treat as degraded.
     */
    public function isCacheUsable(): bool
    {
        try {
            $key = 'signed-launch:cache-probe:' . bin2hex(random_bytes(4));
            Cache::put($key, 1, 5);
            $ok = Cache::has($key);
            Cache::forget($key);
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    private function mapReason(string $message): string
    {
        $msg = strtolower($message);
        return match (true) {
            str_contains($msg, 'expired')                => 'expired_token',
            str_contains($msg, 'audience')               => 'wrong_audience',
            str_contains($msg, 'replay')                 => 'replay_detected',
            str_contains($msg, 'signature')              => 'invalid_signature',
            str_contains($msg, 'malformed')              => 'malformed_token',
            str_contains($msg, 'missing required claim') => 'malformed_token',
            str_contains($msg, 'issuer')                 => 'invalid_signature',
            default                                       => 'invalid_signature',
        };
    }
}
