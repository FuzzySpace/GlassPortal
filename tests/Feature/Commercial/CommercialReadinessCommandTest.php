<?php

namespace Tests\Feature\Commercial;

use App\Enums\UserRole;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29D — glassportal:commercial-readiness. Blockers exit 1; a fully
 * configured system exits 0; secrets are never printed; drift guards fire when
 * the app is pointed at the preserved companion runtime.
 */
class CommercialReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeReady(): void
    {
        User::factory()->create(['role' => UserRole::Owner->value]);

        $product = BillingProduct::factory()->create();
        BillingPlan::factory()->withStripePrice('price_ready_1')->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
        ]);

        config([
            'billing.enabled'               => true,
            'billing.stripe.secret_key'     => 'sk_test_readiness_secret_value',
            'billing.stripe.webhook_secret' => 'whsec_readiness_secret_value',
            'billing.checkout.enabled'      => true,
            'billing.webhooks.enabled'      => true,
        ]);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('glassportal:commercial-readiness', \Illuminate\Support\Facades\Artisan::all());
    }

    public function test_fails_with_blockers_on_unconfigured_system(): void
    {
        // No owner, no products, billing disabled.
        $this->artisan('glassportal:commercial-readiness')->assertExitCode(1);
    }

    public function test_passes_on_fully_configured_system_with_test_keys(): void
    {
        $this->makeReady();

        // Test-mode keys yield warnings but not blockers → exit 0.
        $this->artisan('glassportal:commercial-readiness')->assertExitCode(0);
    }

    public function test_never_prints_secret_values(): void
    {
        $this->makeReady();

        $this->artisan('glassportal:commercial-readiness')
            ->doesntExpectOutputToContain('sk_test_readiness_secret_value')
            ->doesntExpectOutputToContain('whsec_readiness_secret_value')
            ->assertExitCode(0);
    }

    public function test_blocks_when_no_owner_or_admin_exists(): void
    {
        $this->makeReady();
        User::query()->delete();

        $this->artisan('glassportal:commercial-readiness')->assertExitCode(1);
    }

    public function test_blocks_when_app_url_points_at_preserved_companion_runtime(): void
    {
        $this->makeReady();
        config(['app.url' => 'http://40.160.61.180:18180']);

        $this->artisan('glassportal:commercial-readiness')->assertExitCode(1);
    }

    public function test_blocks_when_checkout_disabled(): void
    {
        $this->makeReady();
        config(['billing.checkout.enabled' => false]);

        $this->artisan('glassportal:commercial-readiness')->assertExitCode(1);
    }
}

