<?php

namespace Tests\Unit\Sso;

use App\Services\Sso\ModuleSecretResolver;
use Tests\TestCase;

/**
 * Phase 21A — SIONA per-module signing secret wiring.
 *
 * Proves config/glasshouse_sso.php wires GLASSPORTAL_MODULE_SECRET_SIONA into
 * per_module_secrets, and that ModuleSecretResolver honours it (override when
 * set, safe fallback when empty) without affecting other modules. No database.
 */
class SionaModuleSecretWiringTest extends TestCase
{
    private string $global = 'global-signing-secret-long-enough-for-hmac';
    private string $siona  = 'siona-dedicated-secret-long-enough-for-hmac';

    private function resolver(): ModuleSecretResolver
    {
        return new ModuleSecretResolver();
    }

    private function baseConfig(array $perModule = []): void
    {
        config([
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => $perModule,
            'glasshouse_sso.keys'               => [],
            'glasshouse_sso.key_registry'       => [],
            'glasshouse_sso.active_kid'         => '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Config file wiring (env var → per_module_secrets.siona)
    // -------------------------------------------------------------------------

    public function test_config_wires_siona_to_env_var(): void
    {
        $value = 'env-wired-siona-secret-phase21a';
        $_ENV['GLASSPORTAL_MODULE_SECRET_SIONA']    = $value;
        $_SERVER['GLASSPORTAL_MODULE_SECRET_SIONA'] = $value;
        putenv("GLASSPORTAL_MODULE_SECRET_SIONA={$value}");

        try {
            $config = require base_path('config/glasshouse_sso.php');

            $this->assertArrayHasKey('siona', $config['per_module_secrets']);
            $this->assertSame($value, $config['per_module_secrets']['siona']);
        } finally {
            unset($_ENV['GLASSPORTAL_MODULE_SECRET_SIONA'], $_SERVER['GLASSPORTAL_MODULE_SECRET_SIONA']);
            putenv('GLASSPORTAL_MODULE_SECRET_SIONA');
        }
    }

    public function test_config_siona_defaults_to_empty_when_env_absent(): void
    {
        unset($_ENV['GLASSPORTAL_MODULE_SECRET_SIONA'], $_SERVER['GLASSPORTAL_MODULE_SECRET_SIONA']);
        putenv('GLASSPORTAL_MODULE_SECRET_SIONA');

        $config = require base_path('config/glasshouse_sso.php');

        $this->assertArrayHasKey('siona', $config['per_module_secrets']);
        $this->assertSame('', $config['per_module_secrets']['siona']);
    }

    // -------------------------------------------------------------------------
    // Resolver behavior for SIONA
    // -------------------------------------------------------------------------

    public function test_siona_uses_dedicated_secret_when_set(): void
    {
        $this->baseConfig(['siona' => $this->siona]);
        $r = $this->resolver();

        $this->assertTrue($r->hasPerModuleSecret('siona'));
        $this->assertSame($this->siona, $r->resolveForIssuance('siona'));
        $this->assertSame($this->siona, $r->resolveForVerification('siona', ''));
    }

    public function test_siona_falls_back_to_global_when_empty(): void
    {
        $this->baseConfig(['siona' => '']);
        $r = $this->resolver();

        $this->assertFalse($r->hasPerModuleSecret('siona'));
        $this->assertSame($this->global, $r->resolveForIssuance('siona'));
        $this->assertSame($this->global, $r->resolveForVerification('siona', ''));
    }

    public function test_siona_falls_back_to_global_when_key_absent(): void
    {
        // No 'siona' entry at all — must still fall back, never error.
        $this->baseConfig([]);
        $r = $this->resolver();

        $this->assertFalse($r->hasPerModuleSecret('siona'));
        $this->assertSame($this->global, $r->resolveForIssuance('siona'));
    }

    // -------------------------------------------------------------------------
    // Other modules are unaffected
    // -------------------------------------------------------------------------

    public function test_other_modules_unaffected_by_siona_secret(): void
    {
        $panel = 'glasspanel-dedicated-secret-long-enough-hmac';
        $this->baseConfig(['siona' => $this->siona, 'glasspanel' => $panel]);
        $r = $this->resolver();

        // glasspanel keeps its own dedicated secret
        $this->assertSame($panel, $r->resolveForIssuance('glasspanel'));
        // a module with no entry still uses the global secret
        $this->assertSame($this->global, $r->resolveForIssuance('dns'));
        // siona and glasspanel are isolated from each other
        $this->assertNotSame($r->resolveForIssuance('siona'), $r->resolveForIssuance('glasspanel'));
    }

    // -------------------------------------------------------------------------
    // .env.example documents the variable with no real secret
    // -------------------------------------------------------------------------

    public function test_env_example_documents_variable_without_value(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('GLASSPORTAL_MODULE_SECRET_SIONA', $env);
        // The line must be commented and carry no real secret value.
        $this->assertMatchesRegularExpression('/#\s*GLASSPORTAL_MODULE_SECRET_SIONA=\s*$/m', $env);
    }
}
