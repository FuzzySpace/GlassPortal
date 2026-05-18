<?php

namespace App\Services\Sso;

/**
 * Resolves the HMAC signing secret for a given module key.
 *
 * Priority order for issuance:
 *   1. per_module_secrets[moduleKey]             — per-module secret (most specific)
 *   2. SigningKeyResolver::activeSigningKey()     — active key_registry entry (Phase 15)
 *   3. signing_secret                            — global fallback (legacy)
 *
 * Priority order for verification:
 *   1. per_module_secrets[audience]              — per-module secret (most specific)
 *   2. SigningKeyResolver::resolveByKid(kid)      — key_registry, status-aware (Phase 15)
 *      · null  → disabled key → caller rejects
 *      · ''    → not in registry → fall through to flat keys[]
 *   3. keys[kid]                                 — flat legacy key map (Phase 9)
 *   4. signing_secret                            — global fallback
 *
 * Phase 12 — SSO trust hardening.
 * Phase 15 — key_registry + SigningKeyResolver integration.
 */
class ModuleSecretResolver
{
    private SigningKeyResolver $keyResolver;

    public function __construct(?SigningKeyResolver $keyResolver = null)
    {
        $this->keyResolver = $keyResolver ?? new SigningKeyResolver();
    }

    /**
     * Resolve the signing secret to use when ISSUING a token for a module.
     * Priority: per_module_secrets[moduleKey] → active key_registry → global signing_secret
     */
    public function resolveForIssuance(string $moduleKey): string
    {
        $per = $this->perModuleSecrets();
        if (isset($per[$moduleKey]) && $per[$moduleKey] !== '') {
            return $per[$moduleKey];
        }

        $active = $this->keyResolver->activeSigningKey();
        if ($active !== null) {
            return $active['secret'];
        }

        return (string) config('glasshouse_sso.signing_secret', '');
    }

    /**
     * Resolve the secret to use when VERIFYING a token.
     *
     * When a kid is present and found in key_registry with status "disabled",
     * this method returns '' (empty), and the caller must treat the token as
     * invalid. A disabled key is never a successful fallback.
     *
     * Priority: per_module_secrets[audience] → key_registry[kid] → keys[kid] → signing_secret
     *
     * @param string $audience  The "aud" claim (module key) from the token
     * @param string $kid       The "kid" claim from the token header (may be empty)
     * @return string           Empty string only if kid is explicitly disabled
     */
    public function resolveForVerification(string $audience, string $kid = ''): string
    {
        $per = $this->perModuleSecrets();
        if (isset($per[$audience]) && $per[$audience] !== '') {
            return $per[$audience];
        }

        if ($kid !== '') {
            // Phase 15: status-aware registry lookup
            $fromRegistry = $this->keyResolver->resolveByKid($kid);
            if ($fromRegistry === null) {
                // Explicitly disabled — signal rejection with a sentinel that
                // won't match any HMAC. The caller (SignedLaunchTokenService)
                // already performs a constant-time compare, so an empty string
                // safely causes a signature mismatch without leaking timing.
                return '';
            }
            if ($fromRegistry !== '') {
                return $fromRegistry;
            }

            // Not in key_registry — try Phase 9 flat keys map
            $keys = (array) config('glasshouse_sso.keys', []);
            if (isset($keys[$kid]) && (string) $keys[$kid] !== '') {
                return (string) $keys[$kid];
            }
        }

        return (string) config('glasshouse_sso.signing_secret', '');
    }

    /**
     * Resolve the active signing key metadata for token generation.
     * Returns null when using legacy signing_secret (no kid to embed).
     *
     * @return array{kid: string, secret: string, algorithm: string}|null
     */
    public function activeKeyInfo(): ?array
    {
        return $this->keyResolver->activeSigningKey();
    }

    /** True when a per-module secret is non-empty for the given key. */
    public function hasPerModuleSecret(string $moduleKey): bool
    {
        $per = $this->perModuleSecrets();
        return isset($per[$moduleKey]) && $per[$moduleKey] !== '';
    }

    private function perModuleSecrets(): array
    {
        return (array) config('glasshouse_sso.per_module_secrets', []);
    }
}
