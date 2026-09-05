<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingServiceEntitlement;
use App\Models\ProvisioningRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\StripeWebhookService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 29 — the documented customer pilot path, end to end, with NO real Stripe
 * call (the checkout HTTP boundary is faked and the webhook is delivered to the
 * service directly). Verifies: plans → checkout session recorded → verified
 * webhook activates billing state → entitlement + approval-gated provisioning
 * request → all visible to the customer in self-service.
 */
class PilotCustomerPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_customer_pilot_path(): void
    {
        // --- Pilot data + customer scope -------------------------------------
        $org  = Organization::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $org->id]);
        $customer = BillingCustomer::factory()->forOrganization($org)->withStripe('cus_pilot')->create();

        $product = BillingProduct::factory()->create(['status' => 'active', 'name' => 'Pilot Hosting']);
        $plan    = BillingPlan::factory()->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'name'               => 'Pilot Hosting Monthly',
            'stripe_price_id'    => 'price_pilot_monthly',
        ]);

        // --- 1. Customer accesses available plans ----------------------------
        $this->actingAs($user)->get('/portal/billing/plans')
            ->assertStatus(200)
            ->assertSeeText('Pilot Hosting Monthly');

        // --- 2. Customer starts checkout → session recorded locally ----------
        config([
            'billing.enabled'           => true,
            'billing.mode'              => 'stripe',
            'billing.stripe.secret_key' => 'sk_test_pilot',
            'billing.checkout.enabled'  => true,
        ]);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_pilot', 'url' => 'https://checkout.stripe.com/c/pay/cs_pilot'], 200)]);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($user)
            ->post(route('portal.billing.checkout', $plan))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_pilot');

        $this->assertDatabaseHas('billing_checkout_sessions', [
            'provider_session_id' => 'cs_pilot',
            'billing_customer_id' => $customer->id,
        ]);

        // --- 3. Verified webhook activates billing state ---------------------
        // (delivered to the service directly — no real Stripe round-trip)
        $result = app(StripeWebhookService::class)->handle([
            'id'   => 'evt_pilot_sub',
            'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id'       => 'sub_pilot',
                'customer' => 'cus_pilot',
                'status'   => 'active',
                'items'    => ['data' => [['price' => ['id' => 'price_pilot_monthly']]]],
            ]],
        ]);
        $this->assertSame('processed', $result['status']);

        // Entitlement active for the org; provisioning request approval-gated.
        $entitlement = BillingServiceEntitlement::where('organization_id', $org->id)->first();
        $this->assertNotNull($entitlement);

        $request = ProvisioningRequest::where('organization_id', $org->id)->first();
        $this->assertNotNull($request);
        $this->assertTrue((bool) $request->requires_approval);
        $this->assertSame(ProvisioningRequest::STATUS_PENDING_APPROVAL, $request->status);
        // Never auto-executed.
        $this->assertNotContains($request->status, [ProvisioningRequest::STATUS_RUNNING, ProvisioningRequest::STATUS_COMPLETED]);

        // --- 4. Customer sees subscription + entitlement on the dashboard ----
        $this->actingAs($user)->get('/portal/billing')
            ->assertStatus(200)
            ->assertSeeText('Pilot Hosting Monthly');

        // --- 5. Customer sees provisioning status ----------------------------
        $this->actingAs($user)->get('/portal/provisioning')->assertStatus(200);
        $this->actingAs($user)->get('/portal/entitlements')->assertStatus(200);

        // --- 6. All billing self-service pages render ------------------------
        foreach ([
            '/portal/billing/subscriptions',
            '/portal/billing/invoices',
            '/portal/billing/payments',
            '/portal/billing/checkout-sessions',
            '/portal/billing/change-requests',
        ] as $path) {
            $this->actingAs($user)->get($path)->assertStatus(200);
        }
    }
}
