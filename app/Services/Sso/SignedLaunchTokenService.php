<?php

namespace App\Services\Sso;

use App\Models\OrganizationModuleLink;
use App\Models\User;
use InvalidArgumentException;
use Illuminate\Support\Facades\Cache;

/**
 * Generates and verifies compact signed launch tokens (SLP — Signed Launch Payload).
 *
 * Token format (similar to compact JWT):
 *   base64url(header) . base64url(payload) . base64url(signature)
 *
 * Where:
 *   header    = {"alg":"HS256","typ":"SLP"}
 *   payload   = JSON claims object (see generate())
 *   signature = HMAC-SHA256(header + "." + payload, signing_secret)
 *
 * Security invariants:
 * - The signing_secret NEVER appears in the token or any browser-visible surface.
 * - Tokens include a jti (unique ID) and nonce for replay detection.
 * - Issued JTIs are tracked in cache; verify() consumes them on first use.
 * - Tokens expire quickly (default 60s) to limit replay window.
 * - safePreview() strips nothing (all claims are identity info, no secrets).
 *
 * Phase 9 note: For high-risk modules, migrate to back-channel token exchange
 * where GlassPortal issues a one-time code and the module calls back to redeem
 * it for user identity. This removes the token from the browser entirely.
 */
class SignedLaunchTokenService
{
    private string $secret;
    private string $issuer;
    private int    $ttl;
    private int    $maxTtl;
    private int    $clockSkew;
    private int    $cacheTtl;
    private string $keyId;
    private array  $keys;

    public function __construct()
    {
        $cfg             = config('glasshouse_sso', []);
        $this->secret    = (string) ($cfg['signing_secret'] ?? '');
        $this->issuer    = (string) ($cfg['issuer'] ?? 'glassportal');
        $this->ttl       = (int)    ($cfg['default_ttl_seconds'] ?? 60);
        $this->maxTtl    = (int)    ($cfg['max_ttl_seconds'] ?? 300);
        $this->clockSkew = (int)    ($cfg['clock_skew_seconds'] ?? 30);
        $this->cacheTtl  = (int)    ($cfg['nonce_cache_ttl_seconds'] ?? 600);
        $this->keyId     = (string) ($cfg['key_id'] ?? '');
        $this->keys      = (array)  ($cfg['keys'] ?? []);
    }

    /**
     * Generate a signed launch token for the given link and user.
     *
     * @throws \RuntimeException if signing_secret is not configured
     *
     * @return array{
     *   token: string,          — compact signed token (SLP format)
     *   jti: string,            — unique token ID, stored in audit log
     *   nonce: string,          — replay-protection nonce
     *   expires_at: int,        — Unix timestamp, stored in audit log
     *   payload_preview: array, — claims without secrets (for safe logging/debugging)
     * }
     */
    public function generate(OrganizationModuleLink $link, User $user, ?int $ttl = null): array
    {
        if ($this->secret === '') {
            throw new \RuntimeException(
                'Signed launch secret is not configured. Set GLASSPORTAL_SIGNED_LAUNCH_SECRET.'
            );
        }

        $ttl = min($ttl ?? $this->ttl, $this->maxTtl);
        $iat = time();
        $exp = $iat + $ttl;
        $jti = $this->randomHex(16);
        $nonce = $this->randomHex(12);

        $claims = [
            'iss'   => $this->issuer,
            'aud'   => $link->module_key,
            'sub'   => (string) $user->id,
            'org'   => (string) $link->organization_id,
            'mid'   => (string) $link->id,
            'email' => $user->email,
            'name'  => $user->name,
            'role'  => $user->role instanceof \App\Enums\UserRole
                ? $user->role->value
                : (string) $user->role,
            'iat'   => $iat,
            'exp'   => $exp,
            'nonce' => $nonce,
            'jti'   => $jti,
        ];

        $token = $this->buildToken($claims);

        // Track issued JTI in cache for replay detection.
        // Cache failure degrades gracefully — the token is still valid,
        // but replay detection will be unavailable for this token.
        try {
            Cache::put("signed-launch:issued:{$jti}", $exp, $this->cacheTtl);
        } catch (\Throwable) {
            // cache unavailable — degraded replay protection
        }

        return [
            'token'           => $token,
            'jti'             => $jti,
            'nonce'           => $nonce,
            'expires_at'      => $exp,
            'payload_preview' => $this->safePreview($claims),
        ];
    }

    /**
     * Verify a signed launch token.
     *
     * Checks: signature, issuer, audience, expiry (with clock skew),
     * required claims, and replay (JTI consumed on first successful verify).
     *
     * On success, returns the decoded payload and consumes the JTI so it
     * cannot be verified again. On failure, throws InvalidArgumentException.
     *
     * @throws InvalidArgumentException on any verification failure
     */
    public function verify(string $token, string $expectedAudience, ?string $secret = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed token: expected exactly 3 parts.');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Resolve signing secret: explicit override > kid key map > primary secret
        if ($secret !== null && $secret !== '') {
            $resolvedSecret = $secret;
        } else {
            $headerData = json_decode($this->b64UrlDecode($headerB64), true) ?? [];
            $kid        = (string) ($headerData['kid'] ?? '');
            if ($kid !== '' && isset($this->keys[$kid])) {
                $resolvedSecret = (string) $this->keys[$kid];
            } else {
                $resolvedSecret = $this->secret;
            }
        }

        // 1. Signature check
        $expectedSig = $this->hmacB64("{$headerB64}.{$payloadB64}", $resolvedSecret);
        if (! hash_equals($expectedSig, $sigB64)) {
            throw new InvalidArgumentException('Token signature verification failed.');
        }

        // 2. Decode payload
        $payload = json_decode($this->b64UrlDecode($payloadB64), true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Malformed token: payload could not be decoded.');
        }

        // 3. Required claims
        foreach (['iss', 'aud', 'sub', 'org', 'mid', 'email', 'iat', 'exp', 'nonce', 'jti'] as $claim) {
            if (! array_key_exists($claim, $payload)) {
                throw new InvalidArgumentException("Token is missing required claim: {$claim}.");
            }
        }

        // 4. Issuer
        if ($payload['iss'] !== $this->issuer) {
            throw new InvalidArgumentException("Token issuer is invalid: {$payload['iss']}.");
        }

        // 5. Audience
        if ($payload['aud'] !== $expectedAudience) {
            throw new InvalidArgumentException(
                "Token audience '{$payload['aud']}' does not match expected '{$expectedAudience}'."
            );
        }

        // 6. Expiry (with clock skew tolerance)
        if (time() > ($payload['exp'] + $this->clockSkew)) {
            throw new InvalidArgumentException('Token has expired.');
        }

        // 7. Replay protection — JTI must exist in cache (was issued by us)
        //    and is consumed on first successful verification.
        $jtiKey = "signed-launch:issued:{$payload['jti']}";
        try {
            if (! Cache::has($jtiKey)) {
                throw new InvalidArgumentException(
                    'Token replay detected or token was not issued by this portal (JTI not found).'
                );
            }
            // Consume the JTI so it cannot be verified again
            Cache::forget($jtiKey);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            // Cache unavailable — skip replay check (degraded mode)
        }

        return $payload;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function buildToken(array $claims): string
    {
        $headerData = ['alg' => 'HS256', 'typ' => 'SLP'];
        if ($this->keyId !== '') {
            $headerData['kid'] = $this->keyId;
        }
        $header  = $this->b64UrlEncode(json_encode($headerData));
        $payload = $this->b64UrlEncode(
            json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $sig = $this->hmacB64("{$header}.{$payload}", $this->secret);
        return "{$header}.{$payload}.{$sig}";
    }

    private function hmacB64(string $data, string $secret): string
    {
        return $this->b64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    private function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $data): string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
        return (string) base64_decode(strtr($padded, '-_', '+/'));
    }

    private function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Return claims suitable for safe logging/debug display.
     * All standard SLP claims are identity metadata — no secrets are present.
     * Never include the signing_secret or any credential in this output.
     */
    private function safePreview(array $claims): array
    {
        return $claims;
    }
}
