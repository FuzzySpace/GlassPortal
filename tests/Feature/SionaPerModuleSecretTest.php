<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Sso\SignedLaunchTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 21A — SIONA per-module signing secret hardening.
 *
 * End-to-end proof that SIONA signed_launch tokens are signed and verified with
 * GLASSPORTAL_MODULE_SECRET_SIONA when set, that the healthcheck reports status,
 * and that the admin UI surfaces the state without ever rendering the secret.
 */
class SionaPerModuleSecretTest extends TestCase
{
    use RefreshDatabase;

    private string $global = 'global-signing-secret-long-enough-for-hmac';
    private string $siona  = 'siona-dedicated-secret-long-enough-for-hmac';

    private function sionaLink(): OrganizationModuleLink
    {
        $org = Organization::factory()->create();

        return OrganizationModuleLink::factory()->forModule('siona', 'SIONA')->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'signed_launch',
            'status'          => 'active',
            'external_url'    => 'https://siona.example.test/sso',
        ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    // -------------------------------------------------------------------------
    // Token issuance + verification with the SIONA module secret
    // -------------------------------------------------------------------------

    public function test_siona_token_verifies_with_siona_module_secret(): void
    {
        config([
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => ['siona' => $this->siona],
            'glasshouse_sso.keys'               => [],
            'glasshouse_sso.key_registry'       => [],
            'glasshouse_sso.active_kid'         => '',
        ]);

        $link = $this->sionaLink();
        $user = User::factory()->create(['organization_id' => $link->organization_id]);
        $svc  = new SignedLaunchTokenService();

        // Resolver path (no explicit secret) resolves the SIONA module secret.
        $gen     = $svc->generate($link, $user);
        $payload = $svc->verify($gen['token'], 'siona');
        $this->assertSame('siona', $payload['aud']);

        // Explicit SIONA secret also verifies (fresh token — verify consumes JTI).
        $gen2 = $svc->generate($link, $user);
        $this->assertSame('siona', $svc->verify($gen2['token'], 'siona', $this->siona)['aud']);
    }

    public function test_siona_token_does_not_verify_with_global_secret_only(): void
    {
        config([
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => ['siona' => $this->siona],
            'glasshouse_sso.keys'               => [],
            'glasshouse_sso.key_registry'       => [],
            'glasshouse_sso.active_kid'         => '',
        ]);

        $link = $this->sionaLink();
        $user = User::factory()->create(['organization_id' => $link->organization_id]);
        $svc  = new SignedLaunchTokenService();

        $gen = $svc->generate($link, $user);

        // The token was signed with the SIONA secret; the global secret must fail.
        $this->expectException(InvalidArgumentException::class);
        $svc->verify($gen['token'], 'siona', $this->global);
    }

    public function test_siona_token_falls_back_to_global_when_module_secret_empty(): void
    {
        config([
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => ['siona' => ''],
            'glasshouse_sso.keys'               => [],
            'glasshouse_sso.key_registry'       => [],
            'glasshouse_sso.active_kid'         => '',
        ]);

        $link = $this->sionaLink();
        $user = User::factory()->create(['organization_id' => $link->organization_id]);
        $svc  = new SignedLaunchTokenService();

        // With no dedicated secret, issuance + verification use the global secret.
        $gen     = $svc->generate($link, $user);
        $payload = $svc->verify($gen['token'], 'siona', $this->global);
        $this->assertSame('siona', $payload['aud']);
    }

    public function test_other_module_token_unaffected_by_siona_secret(): void
    {
        config([
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => ['siona' => $this->siona],
            'glasshouse_sso.keys'               => [],
            'glasshouse_sso.key_registry'       => [],
            'glasshouse_sso.active_kid'         => '',
        ]);

        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->forModule('dns', 'DNS')->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'signed_launch',
            'status'          => 'active',
            'external_url'    => 'https://dns.example.test/sso',
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $svc  = new SignedLaunchTokenService();

        // dns has no per-module secret → global. SIONA secret must NOT verify it.
        $gen = $svc->generate($link, $user);
        $this->assertSame('dns', $svc->verify($gen['token'], 'dns', $this->global)['aud']);

        $gen2 = $svc->generate($link, $user);
        $this->expectException(InvalidArgumentException::class);
        $svc->verify($gen2['token'], 'dns', $this->siona);
    }

    // -------------------------------------------------------------------------
    // Healthcheck
    // -------------------------------------------------------------------------

    public function test_healthcheck_includes_siona_per_module_secret_check(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.per_module_secret')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_dedicated_secret(): void
    {
        config(['glasshouse_sso.per_module_secrets' => ['siona' => $this->siona]]);

        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('dedicated per-module signing secret')
            ->assertExitCode(0);
    }

    public function test_healthcheck_warns_when_active_siona_uses_global_fallback(): void
    {
        config([
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => ['siona' => ''],
        ]);
        $this->sionaLink(); // active signed_launch siona link

        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('using the GLOBAL fallback secret')
            ->assertExitCode(0);
    }

    public function test_healthcheck_fails_when_active_siona_has_no_secret(): void
    {
        config([
            'glasshouse_sso.signing_secret'     => '',
            'glasshouse_sso.per_module_secrets' => ['siona' => ''],
            'glasshouse_sso.key_registry'       => [],
            'glasshouse_sso.active_kid'         => '',
        ]);
        $this->sionaLink(); // active signed_launch siona link, but no secret anywhere

        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.per_module_secret')
            ->assertExitCode(1);
    }

    public function test_healthcheck_never_prints_siona_secret(): void
    {
        $secret = 'healthcheck-siona-secret-must-not-print-21a';
        config(['glasshouse_sso.per_module_secrets' => ['siona' => $secret]]);

        $this->artisan('glassportal:healthcheck')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // Admin modules panel
    // -------------------------------------------------------------------------

    public function test_admin_modules_shows_dedicated_secret_label(): void
    {
        config([
            'glassbilling.base_url'             => '',
            'glassbilling.token'                => '',
            'glasshouse_sso.per_module_secrets' => ['siona' => $this->siona],
        ]);

        $this->actingAs($this->adminUser())
            ->get('/admin/modules')
            ->assertStatus(200)
            ->assertSeeText('Dedicated SIONA signing secret configured');
    }

    public function test_admin_modules_shows_global_fallback_label(): void
    {
        config([
            'glassbilling.base_url'             => '',
            'glassbilling.token'                => '',
            'glasshouse_sso.signing_secret'     => $this->global,
            'glasshouse_sso.per_module_secrets' => ['siona' => ''],
        ]);

        $this->actingAs($this->adminUser())
            ->get('/admin/modules')
            ->assertStatus(200)
            ->assertSeeText('Using global fallback secret');
    }

    public function test_admin_modules_never_renders_siona_signing_secret(): void
    {
        $secret = 'admin-siona-signing-secret-must-not-leak-21a';
        config([
            'glassbilling.base_url'             => '',
            'glassbilling.token'                => '',
            'glasshouse_sso.per_module_secrets' => ['siona' => $secret],
        ]);

        $response = $this->actingAs($this->adminUser())->get('/admin/modules');

        $this->assertStringNotContainsString($secret, $response->getContent());
    }
}
