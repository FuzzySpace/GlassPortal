<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
        ]);
    }

    public function test_healthcheck_passes_without_glassbilling_configured(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }

    public function test_healthcheck_shows_unconfigured_warning_for_glassbilling(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('not configured')
            ->assertExitCode(0);
    }

    public function test_healthcheck_strict_passes_when_glassbilling_online(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response(['status' => 'ok', 'version' => '1.0'], 200),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $this->artisan('glassportal:healthcheck --strict')
            ->assertExitCode(0);
    }

    public function test_healthcheck_strict_fails_when_glassbilling_offline(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 503),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $this->artisan('glassportal:healthcheck --strict')
            ->assertExitCode(1);
    }

    public function test_healthcheck_non_strict_passes_even_when_glassbilling_offline(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 503),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }

    public function test_healthcheck_strict_fails_on_401(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 401),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'wrong-token',
        ]);

        $this->artisan('glassportal:healthcheck --strict')
            ->assertExitCode(1);
    }

    public function test_healthcheck_reports_customer_mapping_column(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('glassbilling_customer_id')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_module_links_table(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('organization_module_links')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_launch_module_count(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('launch module')
            ->assertExitCode(0);
    }

    // Phase 23 — billing source-of-truth documentation checks (non-blocking).

    public function test_healthcheck_reports_billing_reconciliation_doc(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('billing.source_reconciliation_doc')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_billing_source_of_truth_adr(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('billing.source_of_truth_adr')
            ->assertExitCode(0);
    }

    // Phase 24 — billing foundation checks.

    public function test_healthcheck_includes_billing_foundation_checks_and_exits_zero(): void
    {
        // Default dev: billing disabled / Stripe unconfigured → warns, never fails.
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('billing.tables')
            ->expectsOutputToContain('billing.models')
            ->expectsOutputToContain('billing.stripe_config')
            ->expectsOutputToContain('billing.webhook_secret')
            ->assertExitCode(0);
    }

    public function test_healthcheck_never_prints_stripe_secret(): void
    {
        $secret = 'sk_live_healthcheck_secret_must_not_print';
        $whsec  = 'whsec_healthcheck_secret_must_not_print';
        config([
            'billing.enabled'               => true,
            'billing.mode'                  => 'stripe',
            'billing.stripe.secret_key'     => $secret,
            'billing.stripe.webhook_secret' => $whsec,
        ]);

        $this->artisan('glassportal:healthcheck')
            ->doesntExpectOutputToContain($secret)
            ->doesntExpectOutputToContain($whsec)
            ->assertExitCode(0);
    }

    // Phase 25 — entitlement checks.

    public function test_healthcheck_includes_entitlement_checks_and_exits_zero(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('billing.entitlements_table')
            ->expectsOutputToContain('billing.entitlement_events_table')
            ->expectsOutputToContain('billing.entitlement_models')
            ->expectsOutputToContain('billing.entitlement_service')
            ->assertExitCode(0);
    }

    // Phase 26 — provisioning request engine checks.

    public function test_healthcheck_includes_provisioning_checks_and_exits_zero(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('provisioning.requests_table')
            ->expectsOutputToContain('provisioning.request_events_table')
            ->expectsOutputToContain('provisioning.models')
            ->expectsOutputToContain('provisioning.service')
            ->expectsOutputToContain('provisioning.driver_registry')
            ->assertExitCode(0);
    }
}
