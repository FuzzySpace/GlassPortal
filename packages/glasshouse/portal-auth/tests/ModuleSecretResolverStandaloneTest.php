<?php

namespace GlassHouse\PortalAuth\Tests;

use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use PHPUnit\Framework\TestCase;

/**
 * Standalone unit tests for ModuleSecretResolver.
 * No Laravel required — runs with plain PHPUnit.
 */
class ModuleSecretResolverStandaloneTest extends TestCase
{
    private const GLOBAL = 'global-signing-secret-long-enough-for-hmac';
    private const MODULE = 'per-module-secret-long-enough-for-hmac-256x';

    // =========================================================================
    // resolveForVerification
    // =========================================================================

    public function test_global_secret_returned_with_no_overrides(): void
    {
        $resolver = new ModuleSecretResolver(self::GLOBAL);
        $this->assertSame(self::GLOBAL, $resolver->resolveForVerification('glasspanel'));
    }

    public function test_per_module_overrides_global(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL,
            perModuleSecrets: ['glasspanel' => self::MODULE],
        );
        $this->assertSame(self::MODULE, $resolver->resolveForVerification('glasspanel'));
    }

    public function test_per_module_does_not_affect_other_modules(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL,
            perModuleSecrets: ['glasspanel' => self::MODULE],
        );
        $this->assertSame(self::GLOBAL, $resolver->resolveForVerification('aria'));
    }

    public function test_kid_resolved_from_key_map_when_no_per_module(): void
    {
        $kidSecret = 'kid-v1-secret-long-enough-for-hmac';
        $resolver  = new ModuleSecretResolver(
            globalSecret: self::GLOBAL,
            keyMap:       ['v1' => $kidSecret],
        );
        $this->assertSame($kidSecret, $resolver->resolveForVerification('glasspanel', 'v1'));
    }

    public function test_per_module_takes_priority_over_kid(): void
    {
        $kidSecret = 'kid-v1-that-should-not-win-in-this-scenario';
        $resolver  = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL,
            perModuleSecrets: ['glasspanel' => self::MODULE],
            keyMap:           ['v1' => $kidSecret],
        );
        $this->assertSame(self::MODULE, $resolver->resolveForVerification('glasspanel', 'v1'));
    }

    public function test_global_fallback_when_kid_absent_from_map(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret: self::GLOBAL,
            keyMap:       ['v1' => 'some-key'],
        );
        $this->assertSame(self::GLOBAL, $resolver->resolveForVerification('glasspanel', 'v99'));
    }

    public function test_empty_per_module_secret_falls_back_to_global(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL,
            perModuleSecrets: ['glasspanel' => ''],
        );
        $this->assertSame(self::GLOBAL, $resolver->resolveForVerification('glasspanel'));
    }

    // =========================================================================
    // hasPerModuleSecret
    // =========================================================================

    public function test_returns_false_when_not_set(): void
    {
        $resolver = new ModuleSecretResolver(self::GLOBAL);
        $this->assertFalse($resolver->hasPerModuleSecret('glasspanel'));
    }

    public function test_returns_true_when_set(): void
    {
        $resolver = new ModuleSecretResolver(self::GLOBAL, ['glasspanel' => self::MODULE]);
        $this->assertTrue($resolver->hasPerModuleSecret('glasspanel'));
    }

    public function test_returns_false_for_empty_string_secret(): void
    {
        $resolver = new ModuleSecretResolver(self::GLOBAL, ['glasspanel' => '']);
        $this->assertFalse($resolver->hasPerModuleSecret('glasspanel'));
    }

    // =========================================================================
    // fromConfig factory
    // =========================================================================

    public function test_from_config_builds_correctly(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret'    => self::GLOBAL,
            'per_module_secrets' => ['aria' => self::MODULE],
            'keys'              => ['v2' => 'v2-key-secret'],
        ]);

        $this->assertSame(self::GLOBAL, $resolver->resolveForVerification('glasspanel'));
        $this->assertSame(self::MODULE, $resolver->resolveForVerification('aria'));
        $this->assertSame('v2-key-secret', $resolver->resolveForVerification('glasspanel', 'v2'));
    }

    public function test_from_config_handles_missing_keys(): void
    {
        $resolver = ModuleSecretResolver::fromConfig(['signing_secret' => self::GLOBAL]);
        $this->assertSame(self::GLOBAL, $resolver->resolveForVerification('glasspanel'));
    }

    // =========================================================================
    // Multiple modules
    // =========================================================================

    public function test_different_modules_get_different_secrets(): void
    {
        $s1 = 'glasspanel-secret-long-enough-for-hmac-256';
        $s2 = 'aria-secret-long-enough-for-hmac-256-testx';
        $resolver = new ModuleSecretResolver(self::GLOBAL, ['glasspanel' => $s1, 'aria' => $s2]);

        $this->assertNotSame(
            $resolver->resolveForVerification('glasspanel'),
            $resolver->resolveForVerification('aria'),
        );
    }
}
