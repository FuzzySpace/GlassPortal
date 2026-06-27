<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\ProvisioningRequest;
use App\Models\User;
use App\Services\Billing\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 27 — StripeCheckoutService. Fails safe when checkout/Stripe is not
 * configured; on success creates ONLY a local checkout session (never a
 * subscription, entitlement, or provisioning request — those wait for the
 * verified webhook). No real Stripe calls; the HTTP boundary is faked.
 */
class StripeCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StripeCheckoutService
    {
        return app(StripeCheckoutService::class);
    }

    private function enableCheckout(): void
    {
        config([
            'billing.enabled'                => true,
            'billing.mode'                   => 'stripe',
            'billing.stripe.secret_key'      => 'sk_test_checkout',
            'billing.checkout.enabled'       => true,
            'billing.checkout.mode'          => 'subscription',
            'billing.checkout.success_url'   => 'https://portal.test/billing/success',
            'billing.checkout.cancel_url'    => 'https://portal.test/billing/cancel',
        ]);
    }

    private function activePlanWithPrice(): BillingPlan
    {
        $product = BillingProduct::factory()->create();

        return BillingPlan::factory()->withStripePrice('price_test_123')->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
        ]);
    }

    // -------------------------------------------------------------------------
    // Safe failures — no Stripe call, no records created
    // -------------------------------------------------------------------------

    public function test_fails_safely_when_checkout_disabled(): void
    {
        Http::fake();
        config(['billing.checkout.enabled' => false]);

        $plan = $this->activePlanWithPrice();
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertFalse($result->ok);
        $this->assertSame('disabled', $result->status);
        Http::assertNothingSent();
        $this->assertSame(0, BillingCheckoutSession::count());
    }

    public function test_fails_safely_when_stripe_unconfigured(): void
    {
        Http::fake();
        config([
            'billing.checkout.enabled'  => true,
            'billing.enabled'           => true,
            'billing.mode'              => 'stripe',
            'billing.stripe.secret_key' => '', // not configured
        ]);

        $plan = $this->activePlanWithPrice();
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertFalse($result->ok);
        $this->assertSame('unconfigured', $result->status);
        Http::assertNothingSent();
        $this->assertSame(0, BillingCheckoutSession::count());
    }

    public function test_fails_safely_when_plan_inactive(): void
    {
        Http::fake();
        $this->enableCheckout();

        $plan = $this->activePlanWithPrice();
        $plan->update(['status' => 'archived']);
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertFalse($result->ok);
        $this->assertSame('plan_unavailable', $result->status);
        Http::assertNothingSent();
    }

    public function test_fails_safely_when_plan_has_no_stripe_price(): void
    {
        Http::fake();
        $this->enableCheckout();

        $product = BillingProduct::factory()->create();
        $plan    = BillingPlan::factory()->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'stripe_price_id'    => null,
        ]);
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertFalse($result->ok);
        $this->assertSame('no_price', $result->status);
        Http::assertNothingSent();
    }

    public function test_handles_stripe_http_error_safely(): void
    {
        $this->enableCheckout();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $plan = $this->activePlanWithPrice();
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertFalse($result->ok);
        $this->assertSame('stripe_error', $result->status);
        $this->assertSame(0, BillingCheckoutSession::count());
    }

    // -------------------------------------------------------------------------
    // Success — local checkout session only
    // -------------------------------------------------------------------------

    public function test_success_creates_local_session_and_returns_redirect_url(): void
    {
        $this->enableCheckout();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id'  => 'cs_test_created_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_created_1',
            ], 200),
        ]);

        $plan = $this->activePlanWithPrice();
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertTrue($result->ok);
        $this->assertSame('created', $result->status);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_created_1', $result->redirectUrl);

        $this->assertDatabaseHas('billing_checkout_sessions', [
            'provider_session_id' => 'cs_test_created_1',
            'billing_plan_id'     => $plan->id,
            'status'              => BillingCheckoutSession::STATUS_OPEN,
        ]);
    }

    public function test_success_never_creates_subscription_entitlement_or_provisioning(): void
    {
        $this->enableCheckout();
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'cs_test_noprov', 'url' => 'https://x/y'], 200),
        ]);

        $plan = $this->activePlanWithPrice();
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->service()->createSessionForPlan($plan, $user, $org);

        // The core Phase 27 invariant: starting checkout grants nothing.
        $this->assertSame(0, BillingSubscription::count());
        $this->assertSame(0, BillingServiceEntitlement::count());
        $this->assertSame(0, ProvisioningRequest::count());
    }

    public function test_reuses_existing_org_customer(): void
    {
        $this->enableCheckout();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_x', 'url' => 'https://x/y'], 200)]);

        $org      = Organization::factory()->create();
        $existing = BillingCustomer::factory()->forOrganization($org)->create();
        $user     = User::factory()->create(['organization_id' => $org->id]);
        $plan     = $this->activePlanWithPrice();

        $this->service()->createSessionForPlan($plan, $user, $org);

        // No new customer created — the org's existing one is reused.
        $this->assertSame(1, BillingCustomer::count());
        $this->assertDatabaseHas('billing_checkout_sessions', [
            'provider_session_id' => 'cs_x',
            'billing_customer_id' => $existing->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Secret hygiene
    // -------------------------------------------------------------------------

    public function test_secret_key_never_appears_in_result(): void
    {
        $secret = 'sk_live_checkout_secret_MUST_NOT_LEAK';
        $this->enableCheckout();
        config(['billing.stripe.secret_key' => $secret]);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_secret', 'url' => 'https://x/y'], 200)]);

        $plan = $this->activePlanWithPrice();
        $user = User::factory()->create();

        $result = $this->service()->createSessionForPlan($plan, $user);

        $this->assertStringNotContainsString($secret, (string) json_encode([
            'status'   => $result->status,
            'message'  => $result->message,
            'redirect' => $result->redirectUrl,
        ]));

        // And the secret is never persisted on the local session payload.
        $session = BillingCheckoutSession::first();
        $this->assertStringNotContainsString($secret, (string) json_encode($session?->payload));
    }
}
