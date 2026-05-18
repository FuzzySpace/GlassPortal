<?php

namespace App\Services\Sso;

/**
 * Resolves signing keys from the Phase 15 key_registry config.
 *
 * Key lifecycle:
 *   active   — current signing key; valid for issuance and verification
 *   previous — retired key; valid for verification only (tokens in flight)
 *   disabled — explicitly revoked; rejected on verification
 *
 * This resolver never exposes raw secrets in its public output (publicKeyMetadata).
 */
class SigningKeyResolver
{
    private array  $registry;
    private string $activeKid;

    public function __construct(?array $registry = null, ?string $activeKid = null)
    {
        $this->registry  = $registry  ?? (array) config('glasshouse_sso.key_registry', []);
        $this->activeKid = $activeKid ?? (string) config('glasshouse_sso.active_kid', '');
    }

    /**
     * Return the active signing key or null if none is configured.
     *
     * @return array{kid: string, secret: string, algorithm: string}|null
     */
    public function activeSigningKey(): ?array
    {
        if ($this->activeKid === '') {
            return null;
        }

        $entry = $this->registry[$this->activeKid] ?? null;
        if ($entry === null) {
            return null;
        }

        if (($entry['status'] ?? '') !== 'active') {
            return null;
        }

        $secret = (string) ($entry['secret'] ?? '');
        if ($secret === '') {
            return null;
        }

        return [
            'kid'       => $this->activeKid,
            'secret'    => $secret,
            'algorithm' => (string) ($entry['algorithm'] ?? 'HS256'),
        ];
    }

    /**
     * Resolve a secret by kid, respecting lifecycle status.
     *
     * Returns:
     *   string  — the secret (active or previous key found)
     *   null    — kid is explicitly disabled (caller must reject the token)
     *   ''      — kid not found in registry (caller may fall through to flat keys[])
     */
    public function resolveByKid(string $kid): ?string
    {
        if ($kid === '' || ! isset($this->registry[$kid])) {
            return '';
        }

        $entry  = $this->registry[$kid];
        $status = (string) ($entry['status'] ?? 'active');

        if ($status === 'disabled') {
            return null;
        }

        $secret = (string) ($entry['secret'] ?? '');
        return $secret !== '' ? $secret : '';
    }

    /**
     * True when an active key is fully configured (kid + non-empty secret).
     */
    public function hasActiveKey(): bool
    {
        return $this->activeSigningKey() !== null;
    }

    /**
     * Return safe JWKS-style metadata for all non-disabled keys.
     * Raw secrets are NEVER included.
     *
     * @return array<int, array{kid: string, alg: string, use: string, kty: string, status: string, iss: string}>
     */
    public function publicKeyMetadata(): array
    {
        $issuer = (string) config('glasshouse_sso.issuer', 'glassportal');
        $keys   = [];

        foreach ($this->registry as $kid => $entry) {
            $status = (string) ($entry['status'] ?? 'active');
            if ($status === 'disabled') {
                continue;
            }

            $keys[] = [
                'kid'    => (string) $kid,
                'alg'    => (string) ($entry['algorithm'] ?? 'HS256'),
                'use'    => 'sig',
                'kty'    => 'oct',
                'status' => $status,
                'iss'    => $issuer,
            ];
        }

        return $keys;
    }

    /**
     * True when the registry is non-empty (regardless of active_kid).
     */
    public function hasRegistry(): bool
    {
        return count($this->registry) > 0;
    }
}
