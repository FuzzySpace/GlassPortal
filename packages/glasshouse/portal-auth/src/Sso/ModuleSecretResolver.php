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
 *   1. per_module_secrets[audience]  — per-module secret
 *   2. keys[kid]                     — KID rotation entry
 *   3. signing_secret                — global fallback
 */
class ModuleSecretResolver implements SecretResolverInterface
{
    private string $globalSecret;
    private array  $perModuleSecrets;
    private array  $keyMap;

    /**
     * @param string $globalSecret      The global HMAC signing secret.
     * @param array  $perModuleSecrets  Map of moduleKey → secret.
     * @param array  $keyMap            Map of kid → secret (key rotation).
     */
    public function __construct(
        string $globalSecret      = '',
        array  $perModuleSecrets  = [],
        array  $keyMap            = [],
    ) {
        $this->globalSecret     = $globalSecret;
        $this->perModuleSecrets = $perModuleSecrets;
        $this->keyMap           = $keyMap;
    }

    /**
     * Build a resolver from a glasshouse_sso config array.
     *
     * @param array $cfg  Typically config('glasshouse_sso').
     */
    public static function fromConfig(array $cfg): self
    {
        return new self(
            globalSecret:     (string) ($cfg['signing_secret'] ?? ''),
            perModuleSecrets: (array)  ($cfg['per_module_secrets'] ?? []),
            keyMap:           (array)  ($cfg['keys'] ?? []),
        );
    }

    public function resolveForVerification(string $audience, string $kid = ''): string
    {
        if (isset($this->perModuleSecrets[$audience]) && $this->perModuleSecrets[$audience] !== '') {
            return $this->perModuleSecrets[$audience];
        }

        if ($kid !== '' && isset($this->keyMap[$kid]) && (string) $this->keyMap[$kid] !== '') {
            return (string) $this->keyMap[$kid];
        }

        return $this->globalSecret;
    }

    public function hasPerModuleSecret(string $moduleKey): bool
    {
        return isset($this->perModuleSecrets[$moduleKey]) && $this->perModuleSecrets[$moduleKey] !== '';
    }
}
