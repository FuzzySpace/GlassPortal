<?php

namespace Tests\Unit\PortalAuthSdk;

use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use Tests\TestCase;

/**
 * Unit tests for the SDK ModuleSecretResolver (framework-free).
 */
class ModuleSecretResolverSdkTest extends TestCase
{
    private const GLOBAL_SECRET = 'global-secret-long-enough-for-hmac-256-test';
    private const MODULE_SECRET = 'per-module-secret-long-enough-for-hmac-256';

    // =========================================================================
    // resolveForVerification
    // =========================================================================

    public function test_global_secret_returned_when_no_per_module_or_kid(): void
    {
        $resolver = new ModuleSecretResolver(self::GLOBAL_SECRET);
        $this->assertSame(self::GLOBAL_SECRET, $resolver->resolveForVerification('glasspanel'));
    }

    public function test_per_module_secret_overrides_global(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL_SECRET,
            perModuleSecrets: ['glasspanel' => self::MODULE_SECRET],
        );
        $this->assertSame(self::MODULE_SECRET, $resolver->resolveForVerification('glasspanel'));
    }

    public function test_kid_resolves_from_key_map_when_no_per_module(): void
    {
        $kidSecret = 'kid-v1-secret-long-enough-for-hmac';
        $resolver  = new ModuleSecretResolver(
            globalSecret: self::GLOBAL_SECRET,
            keyMap:       ['v1' => $kidSecret],
        );
        $this->assertSame($kidSecret, $resolver->resolveForVerification('glasspanel', 'v1'));
    }

    public function test_per_module_overrides_kid(): void
    {
        $kidSecret = 'kid-v1-secret-long-enough-for-hmac';
        $resolver  = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL_SECRET,
            perModuleSecrets: ['glasspanel' => self::MODULE_SECRET],
            keyMap:           ['v1' => $kidSecret],
        );
        $this->assertSame(self::MODULE_SECRET, $resolver->resolveForVerification('glasspanel', 'v1'));
    }

    public function test_global_fallback_when_kid_not_in_map(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret: self::GLOBAL_SECRET,
            keyMap:       ['v1' => 'some-other-secret'],
        );
        $this->assertSame(self::GLOBAL_SECRET, $resolver->resolveForVerification('glasspanel', 'v99'));
    }

    // =========================================================================
    // hasPerModuleSecret
    // =========================================================================

    public function test_has_per_module_secret_false_when_not_set(): void
    {
        $resolver = new ModuleSecretResolver(self::GLOBAL_SECRET);
        $this->assertFalse($resolver->hasPerModuleSecret('glasspanel'));
    }

    public function test_has_per_module_secret_true_when_set(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL_SECRET,
            perModuleSecrets: ['glasspanel' => self::MODULE_SECRET],
        );
        $this->assertTrue($resolver->hasPerModuleSecret('glasspanel'));
    }

    public function test_empty_per_module_secret_falls_back_to_global(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL_SECRET,
            perModuleSecrets: ['glasspanel' => ''],
        );
        $this->assertSame(self::GLOBAL_SECRET, $resolver->resolveForVerification('glasspanel'));
        $this->assertFalse($resolver->hasPerModuleSecret('glasspanel'));
    }

    // =========================================================================
    // fromConfig factory
    // =========================================================================

    public function test_from_config_builds_correctly(): void
    {
        $resolver = ModuleSecretResolver::fromConfig([
            'signing_secret'    => self::GLOBAL_SECRET,
            'per_module_secrets' => ['aria' => self::MODULE_SECRET],
            'keys'              => ['v2' => 'v2-key-secret'],
        ]);

        $this->assertSame(self::GLOBAL_SECRET, $resolver->resolveForVerification('glasspanel'));
        $this->assertSame(self::MODULE_SECRET, $resolver->resolveForVerification('aria'));
        $this->assertSame('v2-key-secret', $resolver->resolveForVerification('glasspanel', 'v2'));
    }

    public function test_different_modules_get_different_secrets(): void
    {
        $resolver = new ModuleSecretResolver(
            globalSecret:    self::GLOBAL_SECRET,
            perModuleSecrets: [
                'glasspanel' => 'glasspanel-specific-secret-long-enough',
                'aria'       => 'aria-specific-secret-also-long-enough-x',
            ],
        );

        $panel = $resolver->resolveForVerification('glasspanel');
        $aria  = $resolver->resolveForVerification('aria');

        $this->assertNotSame($panel, $aria);
        $this->assertSame('glasspanel-specific-secret-long-enough', $panel);
        $this->assertSame('aria-specific-secret-also-long-enough-x', $aria);
    }
}
