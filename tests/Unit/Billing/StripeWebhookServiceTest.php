<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\ProvisioningRequest;
use App\Services\Billing\StripeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 27 — StripeWebhookService. Verified events become local billing records,
 * entitlements, and APPROVAL-GATED provisioning requests. It never mutates
 * infrastructure and never calls Stripe/SIONA. Idempotent on provider_event_id.
 */
class StripeWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StripeWebhookService
    {
        return app(StripeWebhookService::class);
    }

    /** @param array<string,mixed> $object */
    private function event(string $type, array $object, string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => $object]];
    }

    // -------------------------------------------------------------------------
    // Idempotency + routing
    // -------------------------------------------------------------------------

    public function test_duplicate_event_is_not_reprocessed(): void
    {
        $event = $this->event('customer.created', ['id' => 'cus_dup', 'email' => 'a@b.test'], 'evt_dup');

        $first  = $this->service()->handle($event);
        $second = $this->service()->handle($event);

        $this->assertSame('processed', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, BillingEvent::where('provider_event_id', 'evt_dup')->count());
    }

    public function test_unsupported_event_is_ignored_but_recorded(): void
    {
        $result = $this->service()->handle($this->event('charge.refunded', ['id' => 'ch_1'], 'evt_ign'));

        $this->assertSame('ignored', $result['status']);
        $this->assertDatabaseHas('billing_events', [
            'provider_event_id' => 'evt_ign',
            'status'            => BillingEvent::STATUS_IGNORED,
        ]);
    }

    // -------------------------------------------------------------------------
    // checkout.session.completed — completes local session, no entitlement yet
    // -------------------------------------------------------------------------

    public function test_checkout_completed_marks_session_complete_without_granting(): void
    {
        $customer = BillingCustomer::factory()->create();
        $session  = BillingCheckoutSession::factory()->create([
            'billing_customer_id' => $customer->id,
            'provider_session_id' => 'cs_complete_1',
            'status'              => BillingCheckoutSession::STATUS_OPEN,
        ]);

        $result = $this->service()->handle($this->event('checkout.session.completed', [
            'id'             => 'cs_complete_1',
            'customer'       => 'cus_co_1',
            'subscription'   => 'sub_co_1',
            'payment_status' => 'paid',
            'amount_total'   => 4900,
        ], 'evt_co'));

        $this->assertSame('processed', $result['status']);

        $session->refresh();
        $this->assertTrue($session->isComplete());
        $this->assertSame('cus_co_1', $session->provider_customer_id);
        $this->assertSame('sub_co_1', $session->provider_subscription_id);

        // A subscription STUB may exist, but NO entitlement / provisioning is
        // granted from checkout completion alone.
        $this->assertSame(0, BillingServiceEntitlement::count());
        $this->assertSame(0, ProvisioningRequest::count());
    }

    public function test_checkout_completed_without_local_session_records_warning(): void
    {
        $result = $this->service()->handle($this->event('checkout.session.completed', [
            'id' => 'cs_unknown',
        ], 'evt_co_warn'));

        $this->assertSame('processed_with_warnings', $result['status']);
    }

    // -------------------------------------------------------------------------
    // subscription.created — entitlement + approval-gated provisioning request
    // -------------------------------------------------------------------------

    public function test_active_subscription_creates_entitlement_and_gated_provisioning(): void
    {
        $product = BillingProduct::factory()->create(['metadata' => ['module_key' => 'siona', 'service_type' => 'ai_sales']]);
        $plan    = BillingPlan::factory()->withStripePrice('price_sub_active')->create(['billing_product_id' => $product->id]);

        $result = $this->service()->handle($this->event('customer.subscription.created', [
            'id'       => 'sub_active_1',
            'customer' => 'cus_active_1',
            'status'   => 'active',
            'items'    => ['data' => [['price' => ['id' => 'price_sub_active']]]],
        ], 'evt_sub'));

        $this->assertSame('processed', $result['status']);

        $sub = BillingSubscription::where('stripe_subscription_id', 'sub_active_1')->first();
        $this->assertNotNull($sub);
        $this->assertSame($plan->id, $sub->billing_plan_id);

        $entitlement = BillingServiceEntitlement::first();
        $this->assertNotNull($entitlement);

        // Exactly one provisioning request, APPROVAL-GATED, never executed.
        $this->assertSame(1, ProvisioningRequest::count());
        $request = ProvisioningRequest::first();
        $this->assertTrue((bool) $request->requires_approval);
        $this->assertSame(ProvisioningRequest::STATUS_PENDING_APPROVAL, $request->status);
        $this->assertSame(ProvisioningRequest::ACTION_PROVISION, $request->requested_action);
    }

    public function test_repeated_subscription_events_do_not_duplicate_provisioning(): void
    {
        $plan = BillingPlan::factory()->withStripePrice('price_idem')->create();

        $make = fn (string $evtId) => $this->service()->handle($this->event('customer.subscription.updated', [
            'id'       => 'sub_idem',
            'customer' => 'cus_idem',
            'status'   => 'active',
            'items'    => ['data' => [['price' => ['id' => 'price_idem']]]],
        ], $evtId));

        $make('evt_idem_a');
        $make('evt_idem_b');

        // One subscription, one entitlement, one open provisioning request.
        $this->assertSame(1, BillingSubscription::where('stripe_subscription_id', 'sub_idem')->count());
        $this->assertSame(1, BillingServiceEntitlement::count());
        $this->assertSame(1, ProvisioningRequest::count());
    }

    public function test_subscription_without_customer_records_warning_and_persists_nothing(): void
    {
        $result = $this->service()->handle($this->event('customer.subscription.created', [
            'id'     => 'sub_no_cust',
            'status' => 'active',
            // no 'customer'
        ], 'evt_sub_warn'));

        $this->assertSame('processed_with_warnings', $result['status']);
        $this->assertSame(0, BillingSubscription::where('stripe_subscription_id', 'sub_no_cust')->count());
        $this->assertSame(0, ProvisioningRequest::count());
    }

    // -------------------------------------------------------------------------
    // invoice.paid / invoice.payment_failed
    // -------------------------------------------------------------------------

    public function test_invoice_paid_records_invoice_payment_and_activates_subscription(): void
    {
        $customer = BillingCustomer::factory()->withStripe('cus_inv_1')->create();
        $sub      = BillingSubscription::factory()->withStripe('sub_inv_1')->create([
            'billing_customer_id' => $customer->id,
            'status'              => 'incomplete',
        ]);

        $result = $this->service()->handle($this->event('invoice.paid', [
            'id'             => 'in_paid_1',
            'customer'       => 'cus_inv_1',
            'subscription'   => 'sub_inv_1',
            'amount_due'     => 4900,
            'amount_paid'    => 4900,
            'currency'       => 'usd',
            'payment_intent' => 'pi_paid_1',
        ], 'evt_inv_paid'));

        $this->assertSame('processed', $result['status']);
        $this->assertDatabaseHas('billing_invoices', ['stripe_invoice_id' => 'in_paid_1', 'status' => 'paid']);
        $this->assertDatabaseHas('billing_payments', ['stripe_payment_intent_id' => 'pi_paid_1', 'status' => 'succeeded']);
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_invoice_payment_failed_marks_subscription_and_entitlement_past_due(): void
    {
        $customer    = BillingCustomer::factory()->withStripe('cus_fail_1')->create();
        $sub         = BillingSubscription::factory()->withStripe('sub_fail_1')->create([
            'billing_customer_id' => $customer->id,
            'status'              => 'active',
        ]);
        $entitlement = BillingServiceEntitlement::factory()->forSubscription($sub)->create([
            'status' => BillingServiceEntitlement::STATUS_ACTIVE,
        ]);

        $result = $this->service()->handle($this->event('invoice.payment_failed', [
            'id'           => 'in_fail_1',
            'customer'     => 'cus_fail_1',
            'subscription' => 'sub_fail_1',
            'amount_due'   => 4900,
            'currency'     => 'usd',
        ], 'evt_inv_fail'));

        $this->assertSame('processed', $result['status']);
        $this->assertSame('past_due', $sub->fresh()->status);
        $this->assertSame(BillingServiceEntitlement::STATUS_PAST_DUE, $entitlement->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // subscription.deleted — cancels entitlements
    // -------------------------------------------------------------------------

    public function test_subscription_deleted_cancels_entitlements(): void
    {
        $sub         = BillingSubscription::factory()->withStripe('sub_del_1')->create(['status' => 'active']);
        $entitlement = BillingServiceEntitlement::factory()->forSubscription($sub)->create([
            'status' => BillingServiceEntitlement::STATUS_ACTIVE,
        ]);

        $result = $this->service()->handle($this->event('customer.subscription.deleted', [
            'id' => 'sub_del_1',
        ], 'evt_sub_del'));

        $this->assertSame('processed', $result['status']);
        $this->assertSame('canceled', $sub->fresh()->status);
        $this->assertSame(BillingServiceEntitlement::STATUS_CANCELLED, $entitlement->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // payment_method.attached — safe display data only
    // -------------------------------------------------------------------------

    public function test_payment_method_attached_stores_only_safe_card_data(): void
    {
        BillingCustomer::factory()->withStripe('cus_pm_1')->create();

        $result = $this->service()->handle($this->event('payment_method.attached', [
            'id'       => 'pm_1',
            'customer' => 'cus_pm_1',
            'type'     => 'card',
            'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ], 'evt_pm'));

        $this->assertSame('processed', $result['status']);

        $pm = BillingPaymentMethod::where('stripe_payment_method_id', 'pm_1')->first();
        $this->assertNotNull($pm);
        $this->assertSame('visa', $pm->brand);
        $this->assertSame('4242', $pm->last4);

        // Only the display fields are persisted — no full PAN / token columns.
        $this->assertArrayNotHasKey('number', $pm->getAttributes());
        $this->assertArrayNotHasKey('cvc', $pm->getAttributes());
    }

    // -------------------------------------------------------------------------
    // Hard boundaries
    // -------------------------------------------------------------------------

    public function test_webhook_processing_never_makes_outbound_http_calls(): void
    {
        Http::fake();

        $plan = BillingPlan::factory()->withStripePrice('price_no_http')->create();
        $this->service()->handle($this->event('customer.subscription.created', [
            'id'       => 'sub_no_http',
            'customer' => 'cus_no_http',
            'status'   => 'active',
            'items'    => ['data' => [['price' => ['id' => 'price_no_http']]]],
        ], 'evt_no_http'));

        // No Stripe / SIONA / Proxmox / DNS / infrastructure calls.
        Http::assertNothingSent();
    }

    public function test_provisioning_request_is_never_auto_executed(): void
    {
        $plan = BillingPlan::factory()->withStripePrice('price_exec')->create();

        $this->service()->handle($this->event('customer.subscription.created', [
            'id'       => 'sub_exec',
            'customer' => 'cus_exec',
            'status'   => 'active',
            'items'    => ['data' => [['price' => ['id' => 'price_exec']]]],
        ], 'evt_exec'));

        // No request ever reaches a running/completed state from webhook intake.
        $this->assertSame(0, ProvisioningRequest::whereIn('status', [
            ProvisioningRequest::STATUS_RUNNING,
            ProvisioningRequest::STATUS_COMPLETED,
        ])->count());
    }
}
