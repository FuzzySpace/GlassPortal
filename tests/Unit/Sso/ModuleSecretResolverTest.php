<?php

namespace Tests\Unit\Sso;

use App\Services\Sso\ModuleSecretResolver;
use Tests\TestCase;

/**
 * Unit tests for ModuleSecretResolver (Phase 12).
 *
 * These tests do not require a database — they only manipulate config values.
 */
class ModuleSecretResolverTest extends TestCase
{
    private ModuleSecretResolver $resolver;
    private string $globalSecret = 'global-test-secret-long-enough-for-hmac';
    private string $moduleSecret = 'module-specific-secret-for-glasspanel';

    protected function setUp(): void
    {
        parent::setUp();
        config(['glasshouse_sso.signing_secret'   => $this->globalSecret]);
        config(['glasshouse_sso.per_module_secrets' => []]);
        config(['glasshouse_sso.keys'             => []]);
        $this->resolver = new ModuleSecretResolver();
    }

    // =========================================================================
    // resolveForIssuance
    // =========================================================================

    public function test_global_secret_is_returned_when_no_per_module_secret(): void
    {
        $secret = $this->resolver->resolveForIssuance('glasspanel');

        $this->assertSame($this->globalSecret, $secret);
    }

    public function test_per_module_secret_overrides_global_for_issuance(): void
    {
        config(['glasshouse_sso.per_module_secrets' => [
            'glasspanel' => $this->moduleSecret,
        ]]);

        $secret = $this->resolver->resolveForIssuance('glasspanel');

        $this->assertSame($this->moduleSecret, $secret);
    }

    public function test_empty_per_module_secret_falls_back_to_global(): void
    {
        config(['glasshouse_sso.per_module_secrets' => [
            'glasspanel' => '',
        ]]);

        $secret = $this->resolver->resolveForIssuance('glasspanel');

        $this->assertSame($this->globalSecret, $secret);
    }

    // =========================================================================
    // hasPerModuleSecret
    // =========================================================================

    public function test_has_per_module_secret_returns_false_when_not_set(): void
    {
        $this->assertFalse($this->resolver->hasPerModuleSecret('glasspanel'));
    }

    public function test_has_per_module_secret_returns_true_when_set(): void
    {
        config(['glasshouse_sso.per_module_secrets' => [
            'glasspanel' => $this->moduleSecret,
        ]]);

        $this->assertTrue($this->resolver->hasPerModuleSecret('glasspanel'));
    }

    // =========================================================================
    // resolveForVerification — KID fallback
    // =========================================================================

    public function test_kid_used_in_verification_when_no_per_module_secret(): void
    {
        $kidSecret = 'kid-v1-signing-secret-long-enough-for-hmac';
        config(['glasshouse_sso.keys' => ['v1' => $kidSecret]]);

        $secret = $this->resolver->resolveForVerification('glasspanel', 'v1');

        $this->assertSame($kidSecret, $secret);
    }

    public function test_per_module_secret_overrides_kid_in_verification(): void
    {
        $kidSecret = 'kid-v1-signing-secret-long-enough-for-hmac';
        config(['glasshouse_sso.keys'              => ['v1' => $kidSecret]]);
        config(['glasshouse_sso.per_module_secrets' => [
            'glasspanel' => $this->moduleSecret,
        ]]);

        $secret = $this->resolver->resolveForVerification('glasspanel', 'v1');

        // Per-module secret must take priority over KID
        $this->assertSame($this->moduleSecret, $secret);
    }

    public function test_kid_falls_back_to_global_when_kid_not_in_keys(): void
    {
        config(['glasshouse_sso.keys' => ['v1' => 'some-other-secret']]);

        // 'v2' is not in the keys map
        $secret = $this->resolver->resolveForVerification('glasspanel', 'v2');

        $this->assertSame($this->globalSecret, $secret);
    }

    // =========================================================================
    // resolveForVerification — no KID
    // =========================================================================

    public function test_global_secret_returned_when_no_per_module_and_no_kid(): void
    {
        $secret = $this->resolver->resolveForVerification('glasspanel', '');

        $this->assertSame($this->globalSecret, $secret);
    }

    // =========================================================================
    // Multiple modules
    // =========================================================================

    public function test_different_modules_get_different_secrets(): void
    {
        $glasspanelSecret = 'glasspanel-specific-secret-long-enough-for-hmac';
        $ariaSecret       = 'aria-specific-secret-long-enough-for-hmac-test';
        config(['glasshouse_sso.per_module_secrets' => [
            'glasspanel' => $glasspanelSecret,
            'aria'       => $ariaSecret,
        ]]);

        $panel = $this->resolver->resolveForIssuance('glasspanel');
        $aria  = $this->resolver->resolveForIssuance('aria');

        $this->assertSame($glasspanelSecret, $panel);
        $this->assertSame($ariaSecret, $aria);
        $this->assertNotSame($panel, $aria);
    }
}
