<?php

namespace GlassHouse\PortalAuth\Tests;

use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Phase 15 key_registry support in the SDK ModuleSecretResolver.
 */
class ModuleSecretResolverKeyRegistryTest extends TestCase
{
    // =========================================================================
    // fromConfig — key_registry merging
    // =========================================================================

    public function test_from_config_uses_global_secret_when_no_registry(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global-secret',
            'key_registry'   => [],
        ]);

        $this->assertSame('global-secret', $resolver->resolveForVerification('anymodule'));
    }

    public function test_from_config_includes_active_key_in_key_map(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'key_registry'   => [
                'v2' => ['secret' => 'secret-v2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
        ]);

        $this->assertSame('secret-v2', $resolver->resolveForVerification('anymodule', 'v2'));
    }

    public function test_from_config_includes_previous_key_in_key_map(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'key_registry'   => [
                'v1' => ['secret' => 'secret-v1', 'algorithm' => 'HS256', 'status' => 'previous'],
            ],
        ]);

        $this->assertSame('secret-v1', $resolver->resolveForVerification('anymodule', 'v1'));
    }

    public function test_from_config_disabled_key_returns_empty_string(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'key_registry'   => [
                'v1' => ['secret' => 'secret-v1', 'algorithm' => 'HS256', 'status' => 'disabled'],
            ],
        ]);

        // Disabled key returns '' (sentinel — HMAC will fail in caller)
        $this->assertSame('', $resolver->resolveForVerification('anymodule', 'v1'));
    }

    public function test_from_config_registry_takes_precedence_over_flat_keys_for_same_kid(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'keys'           => ['v1' => 'flat-secret-v1'],
            'key_registry'   => [
                'v1' => ['secret' => 'registry-secret-v1', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
        ]);

        $this->assertSame('registry-secret-v1', $resolver->resolveForVerification('anymodule', 'v1'));
    }

    public function test_from_config_flat_keys_used_for_kids_not_in_registry(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'keys'           => ['legacy' => 'flat-legacy-secret'],
            'key_registry'   => [
                'v2' => ['secret' => 'registry-secret-v2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
        ]);

        // 'legacy' is not in registry — falls through to flat keys
        $this->assertSame('flat-legacy-secret', $resolver->resolveForVerification('anymodule', 'legacy'));
    }

    // =========================================================================
    // Priority chain with per_module_secrets
    // =========================================================================

    public function test_per_module_secret_takes_precedence_over_registry(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret'    => 'global',
            'per_module_secrets' => ['glasspanel' => 'per-module-glasspanel'],
            'key_registry'      => [
                'v2' => ['secret' => 'registry-v2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
        ]);

        $this->assertSame('per-module-glasspanel', $resolver->resolveForVerification('glasspanel', 'v2'));
    }

    public function test_registry_key_used_when_no_per_module_secret(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret'    => 'global',
            'per_module_secrets' => [],
            'key_registry'      => [
                'v2' => ['secret' => 'registry-v2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
        ]);

        $this->assertSame('registry-v2', $resolver->resolveForVerification('unknown-module', 'v2'));
    }

    public function test_falls_through_to_global_when_kid_not_found(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret'  => 'global-fallback',
            'key_registry'    => [
                'v2' => ['secret' => 'registry-v2', 'algorithm' => 'HS256', 'status' => 'active'],
            ],
        ]);

        // unknown kid → not in registry → not in flat keys → global
        $this->assertSame('global-fallback', $resolver->resolveForVerification('module', 'unknown-kid'));
    }

    // =========================================================================
    // hasKeyRegistry
    // =========================================================================

    public function test_has_key_registry_true_when_registry_has_entries(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'key_registry'   => [
                'v1' => ['secret' => 's1', 'algorithm' => 'HS256', 'status' => 'disabled'],
            ],
        ]);

        $this->assertTrue($resolver->hasKeyRegistry());
    }

    public function test_has_key_registry_false_when_empty(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret' => 'global',
            'key_registry'   => [],
        ]);

        $this->assertFalse($resolver->hasKeyRegistry());
    }
}
