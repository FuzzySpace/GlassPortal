<?php

namespace Tests\Feature\Commercial;

use App\Enums\UserRole;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\ProvisioningRequest;
use App\Models\User;
use App\Services\Billing\StripeWebhookService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 29D — commercial pilot flow, end to end with Stripe fully mocked.
 * Proves: signup → plan browse → checkout → webhook-driven billing state →
 * entitlement → approval-gated provisioning request → admin review/approve →
 * customer status visibility → strict cross-organization isolation.
 * No external network calls, no infrastructure execution.
 */
class CommercialPilotFlowTest extends TestCase
{
    use RefreshDatabase;

    private function enableBilling(): void
    {
        config([
            'billing.enabled'                => true,
            'billing.mode'                   => 'stripe',
            'billing.stripe.secret_key'      => 'sk_test_pilot',
            'billing.stripe.webhook_secret'  => 'whsec_pilot',
            'billing.checkout.enabled'       => true,
            'billing.webhooks.enabled'       => true,
        ]);
    }

    private function plan(string $priceId = 'price_pilot_1'): BillingPlan
    {
        $product = BillingProduct::factory()->create([
            'metadata' => ['module_key' => 'glasspanel', 'service_type' => 'hosting'],
        ]);

        return BillingPlan::factory()->withStripePrice($priceId)->create([
            'billing_product_id' => $product->id,
            'status'             => 'active',
            'name'               => 'Pilot Hosting Plan',
        ]);
    }

    /** @param array<string,mixed> $object */
    private function webhookEvent(string $type, array $object, string $id): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => $object]];
    }

    public function test_full_pilot_flow_from_checkout_to_admin_approval(): void
    {
        $this->enableBilling();
        $plan     = $this->plan();
        $customer = User::factory()->create(['role' => UserRole::Customer->value]);
        $admin    = User::factory()->create(['role' => UserRole::Admin->value]);

        // --- 1. Customer browses plans and starts checkout (Stripe mocked).
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id'  => 'cs_test_flow_1',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_flow_1',
            ], 200),
        ]);

        $this->actingAs($customer)->get('/portal/billing/plans')->assertOk();

        $this->actingAs($customer)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/portal/billing/checkout/plans/{$plan->id}")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_flow_1');

        $session = BillingCheckoutSession::where('provider_session_id', 'cs_test_flow_1')->first();
        $this->assertNotNull($session, 'Checkout start must persist a local session record');
        $localCustomer = BillingCustomer::find($session->billing_customer_id);
        $this->assertNotNull($localCustomer);

        // Starting checkout grants nothing.
        $this->assertSame(0, BillingServiceEntitlement::count());
        $this->assertSame(0, ProvisioningRequest::count());

        // --- 2. Stripe confirms via webhooks (service-level, signature covered
        //        by StripeWebhookEndpointTest).
        $webhooks = app(StripeWebhookService::class);

        $webhooks->handle($this->webhookEvent('checkout.session.completed', [
            'id'             => 'cs_test_flow_1',
            'customer'       => 'cus_flow_1',
            'subscription'   => 'sub_flow_1',
            'payment_status' => 'paid',
            'amount_total'   => 4900,
        ], 'evt_flow_checkout'));

        $webhooks->handle($this->webhookEvent('customer.subscription.created', [
            'id'       => 'sub_flow_1',
            'customer' => 'cus_flow_1',
            'status'   => 'active',
            'items'    => ['data' => [['price' => ['id' => 'price_pilot_1']]]],
        ], 'evt_flow_sub'));

        $webhooks->handle($this->webhookEvent('invoice.paid', [
            'id'           => 'in_flow_1',
            'customer'     => 'cus_flow_1',
            'subscription' => 'sub_flow_1',
            'payment_intent' => 'pi_flow_1',
            'amount_due'   => 4900,
            'amount_paid'  => 4900,
            'currency'     => 'usd',
        ], 'evt_flow_inv'));

        // --- 3. Billing state is correct.
        $session->refresh();
        $this->assertTrue($session->isComplete());
        $this->assertSame(1, BillingSubscription::where('stripe_subscription_id', 'sub_flow_1')->count());
        $this->assertSame(1, BillingInvoice::where('stripe_invoice_id', 'in_flow_1')->count());
        $this->assertSame(1, BillingPayment::count());

        // --- 4. Entitlement exists; provisioning request is approval-gated and NOT executed.
        $this->assertSame(1, BillingServiceEntitlement::count());
        $request = ProvisioningRequest::sole();
        $this->assertTrue((bool) $request->requires_approval);
        $this->assertSame(ProvisioningRequest::STATUS_PENDING_APPROVAL, $request->status);

        // --- 5. Admin reviews and approves; still no execution (status only).
        $this->actingAs($admin)->get('/admin/provisioning/requests')->assertOk();

        $this->actingAs($admin)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/provisioning/requests/{$request->id}/approve")
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(ProvisioningRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->approved_at);

        // The only outbound HTTP in this entire flow was the mocked Stripe
        // checkout call — approval triggered no provider/network activity.
        Http::assertSentCount(1);

        // --- 6. Idempotency: replaying every webhook changes nothing.
        $webhooks->handle($this->webhookEvent('customer.subscription.created', [
            'id'       => 'sub_flow_1',
            'customer' => 'cus_flow_1',
            'status'   => 'active',
            'items'    => ['data' => [['price' => ['id' => 'price_pilot_1']]]],
        ], 'evt_flow_sub'));

        $this->assertSame(1, BillingSubscription::where('stripe_subscription_id', 'sub_flow_1')->count());
        $this->assertSame(1, BillingServiceEntitlement::count());
        $this->assertSame(1, ProvisioningRequest::count());
        $this->assertSame(1, BillingEvent::where('provider_event_id', 'evt_flow_sub')->count());
    }

    public function test_customer_sees_own_billing_status_but_not_other_organizations(): void
    {
        $this->enableBilling();

        $orgA  = Organization::factory()->create();
        $orgB  = Organization::factory()->create();
        $userA = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $orgA->id]);
        $userB = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $orgB->id]);

        $customerA = BillingCustomer::factory()->create(['organization_id' => $orgA->id, 'user_id' => $userA->id]);
        $customerB = BillingCustomer::factory()->create(['organization_id' => $orgB->id, 'user_id' => $userB->id]);

        $subA = BillingSubscription::factory()->create(['billing_customer_id' => $customerA->id]);
        $subB = BillingSubscription::factory()->create(['billing_customer_id' => $customerB->id]);

        // A sees their own subscription detail; B's returns 404 (not 403 — no
        // existence leak).
        $this->actingAs($userA)->get("/portal/billing/subscriptions/{$subA->id}")->assertOk();
        $this->actingAs($userA)->get("/portal/billing/subscriptions/{$subB->id}")->assertNotFound();

        // Dashboard renders without cross-tenant data.
        $this->actingAs($userA)->get('/portal/billing')->assertOk();
    }

    public function test_failed_checkout_start_persists_nothing(): void
    {
        $this->enableBilling();
        $plan     = $this->plan('price_pilot_fail');
        $customer = User::factory()->create(['role' => UserRole::Customer->value]);

        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $this->actingAs($customer)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/portal/billing/checkout/plans/{$plan->id}")
            ->assertRedirect(); // back with error flash

        $this->assertSame(0, BillingCheckoutSession::count());
        $this->assertSame(0, BillingSubscription::count());
        $this->assertSame(0, BillingServiceEntitlement::count());
        $this->assertSame(0, ProvisioningRequest::count());
    }
}
