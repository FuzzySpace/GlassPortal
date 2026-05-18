<?php

namespace GlassHouse\PortalAuth\Sso;

use GlassHouse\PortalAuth\Contracts\SecretResolverInterface;

/**
 * Resolves the HMAC signing secret for a given audience/module key.
 *
 * This implementation is framework-free: it operates against a plain config
 * array passed at construction time. Modules that bootstrap with Laravel can
 * build this from config('glasshouse_sso').
 *
 * Priority (verification):
 *   1. per_module_secrets[audience]  — per-module secret (most specific)
 *   2. key_registry[kid] (Phase 15)  — status-aware: active/previous verifies, disabled → empty
 *   3. keys[kid]                     — flat legacy KID map (Phase 9)
 *   4. signing_secret                — global fallback
 */
class ModuleSecretResolver implements SecretResolverInterface
{
    private string $globalSecret;
    private array  $perModuleSecrets;
    private array  $keyMap;
    private array  $keyRegistry;

    /**
     * @param string $globalSecret      The global HMAC signing secret.
     * @param array  $perModuleSecrets  Map of moduleKey → secret.
     * @param array  $keyMap            Flat map of kid → secret (legacy Phase 9).
     * @param array  $keyRegistry       Rich map of kid → entry (Phase 15).
     *                                  Each entry: ['secret', 'algorithm', 'status', ...]
     */
    public function __construct(
        string $globalSecret      = '',
        array  $perModuleSecrets  = [],
        array  $keyMap            = [],
        array  $keyRegistry       = [],
    ) {
        $this->globalSecret     = $globalSecret;
        $this->perModuleSecrets = $perModuleSecrets;
        $this->keyMap           = $keyMap;
        $this->keyRegistry      = $keyRegistry;
    }

    /**
     * Build a resolver from a glasshouse_sso config array.
     *
     * Reads key_registry (Phase 15) and includes active + previous keys in the
     * effective key map; disabled keys are mapped to '' so callers can distinguish
     * "not found" from "explicitly disabled". The flat keys[] map (Phase 9) is
     * used as a fallback for kids not present in key_registry.
     *
     * @param array $cfg  Typically config('glasshouse_sso').
     */
    public static function fromConfig(array $cfg): self
    {
        $registry = (array) ($cfg['key_registry'] ?? []);

        // Build a merged keyMap: key_registry takes precedence over flat keys[]
        // for the same kid. Disabled entries are mapped to '' (sentinel for rejection).
        $flatKeys = (array) ($cfg['keys'] ?? []);
        $mergedKeyMap = $flatKeys;

        foreach ($registry as $kid => $entry) {
            $status = (string) ($entry['status'] ?? 'active');
            if ($status === 'disabled') {
                $mergedKeyMap[(string) $kid] = '';
            } elseif (isset($entry['secret']) && (string) $entry['secret'] !== '') {
                $mergedKeyMap[(string) $kid] = (string) $entry['secret'];
            }
        }

        return new self(
            globalSecret:     (string) ($cfg['signing_secret'] ?? ''),
            perModuleSecrets: (array)  ($cfg['per_module_secrets'] ?? []),
            keyMap:           $mergedKeyMap,
            keyRegistry:      $registry,
        );
    }

    /**
     * Resolve the verification secret.
     *
     * When a kid maps to an empty string via key_registry (disabled status),
     * this method returns '' — the caller's HMAC compare will fail, safely
     * rejecting the token without a timing oracle.
     */
    public function resolveForVerification(string $audience, string $kid = ''): string
    {
        if (isset($this->perModuleSecrets[$audience]) && $this->perModuleSecrets[$audience] !== '') {
            return $this->perModuleSecrets[$audience];
        }

        if ($kid !== '') {
            // keyMap includes key_registry entries (merged in fromConfig).
            // '' in keyMap = disabled key; return '' to signal rejection.
            if (array_key_exists($kid, $this->keyMap)) {
                return (string) $this->keyMap[$kid];
            }
        }

        return $this->globalSecret;
    }

    public function hasPerModuleSecret(string $moduleKey): bool
    {
        return isset($this->perModuleSecrets[$moduleKey]) && $this->perModuleSecrets[$moduleKey] !== '';
    }

    /**
     * True when the registry contains entries (regardless of active_kid).
     */
    public function hasKeyRegistry(): bool
    {
        return count($this->keyRegistry) > 0;
    }
}
