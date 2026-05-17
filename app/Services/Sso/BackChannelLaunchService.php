<?php

namespace App\Services\Sso;

use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Issues and redeems back-channel launch codes for the Phase 11 SSO exchange.
 *
 * Flow:
 *   1. GlassPortal calls issueCode() — stores a short-lived payload in cache,
 *      returns an opaque 64-hex-char code to the handoff view.
 *   2. Browser POSTs the code (in form body, never in URL) to the module.
 *   3. Module calls GlassPortal's POST /api/sso/backchannel/redeem/{moduleKey}
 *      with the code in the request body (server-to-server).
 *   4. GlassPortal calls redeemCode() — validates, consumes, returns identity data.
 *
 * Security:
 * - Codes are 32 random bytes (hex-encoded, 64 chars) — brute-force infeasible.
 * - Cache key is SHA-256(code) — the raw code never appears in the cache key.
 * - Pending codes are deleted on first redeem; tombstones prevent replay.
 * - The code must never appear in logs, DB rows, or URLs.
 */
class BackChannelLaunchService
{
    private const PENDING_PREFIX   = 'glassportal:sso:backchannel:p:';
    private const TOMBSTONE_PREFIX = 'glassportal:sso:backchannel:u:';
    private const PROBE_KEY        = 'glassportal:sso:backchannel:probe';

    // =========================================================================
    // Issue
    // =========================================================================

    /**
     * Issue a one-time launch code for a user/link pair.
     *
     * The returned code must be placed in a POST form body — never in a URL.
     */
    public function issueCode(OrganizationModuleLink $link, User $user): BackChannelLaunchCodeResult
    {
        if (! $this->isEnabled()) {
            return BackChannelLaunchCodeResult::failure('backchannel_disabled');
        }

        $code      = bin2hex(random_bytes(32));
        $ttl       = max(10, (int) config('glasshouse_sso.backchannel.code_ttl_seconds', 60));
        $expiresAt = time() + $ttl;

        $payload = [
            'module_key'     => $link->module_key,
            'org_id'         => (string) $link->organization_id,
            'module_link_id' => (string) $link->id,
            'user_id'        => (string) $user->id,
            'email'          => $user->email,
            'name'           => $user->name,
            'role'           => $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role,
            'issued_at'      => time(),
            'expires_at'     => $expiresAt,
        ];

        Cache::put($this->pendingKey($code), $payload, $ttl);

        return BackChannelLaunchCodeResult::issued($code, $expiresAt);
    }

    // =========================================================================
    // Redeem
    // =========================================================================

    /**
     * Redeem a one-time launch code issued by issueCode().
     *
     * Validates format, checks replay tombstone, consumes the pending cache
     * entry, verifies module key, and returns user identity data.
     *
     * Reason codes:
     *   backchannel_disabled  — feature not enabled in config
     *   missing_code          — empty string provided
     *   malformed_code        — not a 64-char hex string
     *   code_replayed         — code was already consumed
     *   code_not_found        — code unknown or expired
     *   wrong_module          — code was issued for a different module key
     *   inactive_module_link  — link is no longer active in the DB
     *   organization_mismatch — org on link changed since code was issued
     *   user_not_found        — user no longer exists in the DB
     */
    public function redeemCode(string $moduleKey, string $code): BackChannelLaunchCodeResult
    {
        if (! $this->isEnabled()) {
            return BackChannelLaunchCodeResult::failure('backchannel_disabled');
        }

        if ($code === '') {
            return BackChannelLaunchCodeResult::failure('missing_code');
        }

        if (! $this->isValidCodeFormat($code)) {
            return BackChannelLaunchCodeResult::failure('malformed_code');
        }

        // Replay detection: tombstone means this code was already consumed
        if (Cache::has($this->tombstoneKey($code))) {
            return BackChannelLaunchCodeResult::failure('code_replayed');
        }

        // Pending entry must exist (also covers expired codes)
        $payload = Cache::get($this->pendingKey($code));
        if ($payload === null) {
            return BackChannelLaunchCodeResult::failure('code_not_found');
        }

        // Validate module key before consuming
        $strict = (bool) config('glasshouse_sso.backchannel.strict_module_match', true);
        if ($strict && ($payload['module_key'] ?? '') !== $moduleKey) {
            return BackChannelLaunchCodeResult::failure('wrong_module');
        }

        // Consume: set tombstone, delete pending.
        // Order: tombstone first so replay detection works even if delete fails.
        $replayTtl = max(60, (int) config('glasshouse_sso.backchannel.replay_cache_ttl_seconds', 600));
        Cache::put($this->tombstoneKey($code), 1, $replayTtl);
        Cache::forget($this->pendingKey($code));

        // Post-consumption checks against live DB state
        $link = OrganizationModuleLink::find($payload['module_link_id'] ?? null);
        if ($link === null || ! $link->isActive()) {
            return BackChannelLaunchCodeResult::failureWithContext('inactive_module_link', $payload);
        }

        if ((string) $link->organization_id !== ($payload['org_id'] ?? '')) {
            return BackChannelLaunchCodeResult::failureWithContext('organization_mismatch', $payload);
        }

        $user = User::find($payload['user_id'] ?? null);
        if ($user === null) {
            return BackChannelLaunchCodeResult::failureWithContext('user_not_found', $payload);
        }

        return BackChannelLaunchCodeResult::redeemed($payload);
    }

    // =========================================================================
    // Health probe
    // =========================================================================

    public function isCacheUsable(): bool
    {
        try {
            Cache::put(self::PROBE_KEY, 1, 5);
            $ok = Cache::get(self::PROBE_KEY) === 1;
            Cache::forget(self::PROBE_KEY);
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function isEnabled(): bool
    {
        return (bool) config('glasshouse_sso.backchannel.enabled', false);
    }

    private function isValidCodeFormat(string $code): bool
    {
        return strlen($code) === 64 && ctype_xdigit($code);
    }

    private function pendingKey(string $code): string
    {
        return self::PENDING_PREFIX . hash('sha256', $code);
    }

    private function tombstoneKey(string $code): string
    {
        return self::TOMBSTONE_PREFIX . hash('sha256', $code);
    }
}
