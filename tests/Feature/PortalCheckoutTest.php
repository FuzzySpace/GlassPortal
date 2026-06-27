<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingCheckoutSession;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\ProvisioningRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 27 — customer-facing checkout start flow. Lists active plans and starts
 * a Stripe Checkout session; fails safe (flash + no records) when checkout is
 * not configured. Starting checkout never grants billing/entitlement/provisioning.
 */
class PortalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value]);
    }

    private function activePlan(): BillingPlan
    {
        $product = BillingProduct::factory()->create();

        return BillingPlan::factory()->withStripePrice('price_portal_1')->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'name'               => 'Portal Pro Plan',
        ]);
    }

    private function enableCheckout(): void
    {
        config([
            'billing.enabled'           => true,
            'billing.mode'              => 'stripe',
            'billing.stripe.secret_key' => 'sk_test_portal',
            'billing.checkout.enabled'  => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Plans page
    // -------------------------------------------------------------------------

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/portal/billing/plans')->assertRedirect('/login');
    }

    public function test_non_customer_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($admin)->get('/portal/billing/plans')->assertForbidden();
    }

    public function test_customer_sees_active_plans(): void
    {
        $this->activePlan();

        $this->actingAs($this->customer())
            ->get('/portal/billing/plans')
            ->assertStatus(200)
            ->assertSeeText('Portal Pro Plan');
    }

    public function test_plans_page_shows_notice_when_checkout_disabled(): void
    {
        config(['billing.checkout.enabled' => false]);
        $this->activePlan();

        $this->actingAs($this->customer())
            ->get('/portal/billing/plans')
            ->assertStatus(200)
            ->assertSeeText('not currently available');
    }

    // -------------------------------------------------------------------------
    // Checkout start
    // -------------------------------------------------------------------------

    public function test_checkout_disabled_redirects_back_with_error_and_creates_nothing(): void
    {
        Http::fake();
        config(['billing.checkout.enabled' => false]);
        $plan = $this->activePlan();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->customer())
            ->post(route('portal.billing.checkout', $plan))
            ->assertRedirect(route('portal.billing.plans'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, BillingCheckoutSession::count());
    }

    public function test_checkout_start_redirects_to_stripe_when_configured(): void
    {
        $this->enableCheckout();
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id'  => 'cs_portal_redirect',
                'url' => 'https://checkout.stripe.com/c/pay/cs_portal_redirect',
            ], 200),
        ]);

        $plan = $this->activePlan();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->customer())
            ->post(route('portal.billing.checkout', $plan))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_portal_redirect');

        $this->assertDatabaseHas('billing_checkout_sessions', [
            'provider_session_id' => 'cs_portal_redirect',
            'billing_plan_id'     => $plan->id,
        ]);
    }

    public function test_checkout_start_never_grants_subscription_entitlement_or_provisioning(): void
    {
        $this->enableCheckout();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_portal_grant', 'url' => 'https://x/y'], 200)]);

        $org  = Organization::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $org->id]);
        $plan = $this->activePlan();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($user)
            ->post(route('portal.billing.checkout', $plan));

        $this->assertSame(0, BillingSubscription::count());
        $this->assertSame(0, BillingServiceEntitlement::count());
        $this->assertSame(0, ProvisioningRequest::count());
    }

    public function test_checkout_start_with_unpriced_plan_fails_safe(): void
    {
        $this->enableCheckout();
        Http::fake();

        $product = BillingProduct::factory()->create();
        $plan    = BillingPlan::factory()->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'stripe_price_id'    => null,
        ]);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->customer())
            ->post(route('portal.billing.checkout', $plan))
            ->assertRedirect(route('portal.billing.plans'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }
}
