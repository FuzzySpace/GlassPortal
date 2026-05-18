<?php

namespace App\Services\Sso;

/**
 * Resolves the HMAC signing secret for a given module key.
 *
 * Priority order for issuance:
 *   1. per_module_secrets[moduleKey]  — per-module secret (most specific)
 *   2. signing_secret                 — global fallback
 *
 * Priority order for verification:
 *   1. per_module_secrets[audience]   — per-module secret (most specific)
 *   2. keys[kid]                      — KID rotation entry
 *   3. signing_secret                 — global fallback
 *
 * Phase 12 — SSO trust hardening.
 */
class ModuleSecretResolver
{
    /**
     * Resolve the signing secret to use when ISSUING a token for a module.
     * Priority: per_module_secrets[moduleKey] → global signing_secret
     */
    public function resolveForIssuance(string $moduleKey): string
    {
        $per = $this->perModuleSecrets();
        if (isset($per[$moduleKey]) && $per[$moduleKey] !== '') {
            return $per[$moduleKey];
        }
        return (string) config('glasshouse_sso.signing_secret', '');
    }

    /**
     * Resolve the secret to use when VERIFYING a token.
     * Priority: per_module_secrets[audience] → keys[kid] → signing_secret
     */
    public function resolveForVerification(string $audience, string $kid = ''): string
    {
        $per = $this->perModuleSecrets();
        if (isset($per[$audience]) && $per[$audience] !== '') {
            return $per[$audience];
        }

        if ($kid !== '') {
            $keys = (array) config('glasshouse_sso.keys', []);
            if (isset($keys[$kid]) && (string) $keys[$kid] !== '') {
                return (string) $keys[$kid];
            }
        }

        return (string) config('glasshouse_sso.signing_secret', '');
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
