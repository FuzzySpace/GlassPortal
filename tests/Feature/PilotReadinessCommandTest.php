<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\BillingProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 29 — glassportal:pilot-readiness command. Exit 0 when no blocked checks,
 * exit 1 when a critical dependency is missing. Never prints secrets.
 */
class PilotReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedActiveProductAndPlan(): void
    {
        $product = BillingProduct::factory()->create(['status' => 'active']);
        BillingPlan::factory()->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'stripe_price_id'    => 'price_test_pilot',
        ]);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('glassportal:pilot-readiness', Artisan::all());
    }

    public function test_exits_nonzero_when_no_active_product(): void
    {
        // Empty DB → product/plan blocked.
        $this->artisan('glassportal:pilot-readiness')
            ->expectsOutputToContain('product_catalog.active_product')
            ->assertExitCode(1);
    }

    public function test_exits_zero_when_product_and_plan_present(): void
    {
        $this->seedActiveProductAndPlan();

        $this->artisan('glassportal:pilot-readiness')->assertExitCode(0);
    }

    public function test_output_does_not_include_raw_secrets(): void
    {
        $secret = 'sk_live_PILOT_CMD_SECRET_MUST_NOT_PRINT';
        $whsec  = 'whsec_PILOT_CMD_SECRET_MUST_NOT_PRINT';
        config([
            'billing.enabled'               => true,
            'billing.mode'                  => 'stripe',
            'billing.stripe.secret_key'     => $secret,
            'billing.stripe.webhook_secret' => $whsec,
            'billing.checkout.enabled'      => true,
            'billing.webhooks.enabled'      => true,
        ]);
        $this->seedActiveProductAndPlan();

        $this->artisan('glassportal:pilot-readiness')
            ->doesntExpectOutputToContain($secret)
            ->doesntExpectOutputToContain($whsec)
            ->assertExitCode(0);
    }
}
