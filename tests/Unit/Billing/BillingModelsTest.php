<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\PublicProductCatalogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 — billing foundation models, relationships, scopes, constraints.
 */
class BillingModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_billing_tables_exist(): void
    {
        foreach ([
            'billing_customers', 'billing_products', 'billing_plans', 'billing_subscriptions',
            'billing_invoices', 'billing_payments', 'billing_payment_methods', 'billing_events',
        ] as $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($table), "missing table {$table}");
        }
    }

    public function test_products_and_plans_create_via_factories(): void
    {
        $product = BillingProduct::factory()->create();
        $plan    = BillingPlan::factory()->create(['billing_product_id' => $product->id]);

        $this->assertDatabaseHas('billing_products', ['id' => $product->id]);
        $this->assertSame($product->id, $plan->product->id);
        $this->assertTrue($product->plans->contains($plan));
    }

    public function test_subscription_relates_to_customer_and_plan(): void
    {
        $sub = BillingSubscription::factory()->create();

        $this->assertInstanceOf(BillingCustomer::class, $sub->customer);
        $this->assertInstanceOf(BillingPlan::class, $sub->plan);
        $this->assertTrue($sub->customer->subscriptions->contains($sub));
    }

    public function test_invoice_and_payment_relate_correctly(): void
    {
        $customer = BillingCustomer::factory()->create();
        $invoice  = BillingInvoice::factory()->create(['billing_customer_id' => $customer->id]);
        $payment  = BillingPayment::factory()->create([
            'billing_customer_id' => $customer->id,
            'billing_invoice_id'  => $invoice->id,
        ]);

        $this->assertSame($customer->id, $invoice->customer->id);
        $this->assertSame($invoice->id, $payment->invoice->id);
        $this->assertSame($customer->id, $payment->customer->id);
        $this->assertTrue($invoice->payments->contains($payment));
    }

    public function test_customer_maps_to_organization(): void
    {
        $org      = Organization::factory()->create();
        $customer = BillingCustomer::factory()->forOrganization($org)->create();

        $this->assertSame($org->id, $customer->organization->id);
    }

    public function test_product_links_to_public_catalog_entry(): void
    {
        $entry   = PublicProductCatalogEntry::factory()->create();
        $product = BillingProduct::factory()->create(['public_catalog_entry_id' => $entry->id]);

        $this->assertSame($entry->id, $product->catalogEntry->id);
    }

    public function test_payment_method_only_stores_safe_card_display_data(): void
    {
        $pm = BillingPaymentMethod::factory()->default()->create(['brand' => 'visa', 'last4' => '4242']);

        $this->assertSame('Visa •••• 4242', $pm->label());
        $this->assertTrue($pm->is_default);
        // The schema has no column for a full PAN / CVC.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('billing_payment_methods', 'number'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('billing_payment_methods', 'cvc'));
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function test_scopes_filter_as_expected(): void
    {
        BillingCustomer::factory()->create(['status' => 'active']);
        BillingCustomer::factory()->create(['status' => 'inactive']);
        $this->assertSame(1, BillingCustomer::active()->count());

        BillingSubscription::factory()->create(['status' => 'active']);
        BillingSubscription::factory()->canceled()->create();
        $this->assertSame(1, BillingSubscription::active()->count());

        $cust = BillingCustomer::factory()->create();
        BillingInvoice::factory()->paid()->create(['billing_customer_id' => $cust->id]);
        BillingInvoice::factory()->create(['billing_customer_id' => $cust->id, 'status' => 'open']);
        $this->assertSame(1, BillingInvoice::paid()->count());
    }

    // -------------------------------------------------------------------------
    // billing_events — provider ids + idempotency
    // -------------------------------------------------------------------------

    public function test_billing_event_records_provider_event_id(): void
    {
        $event = BillingEvent::factory()->create(['provider_event_id' => 'evt_record_1']);

        $this->assertDatabaseHas('billing_events', [
            'provider'          => 'stripe',
            'provider_event_id' => 'evt_record_1',
        ]);
        $this->assertSame('pending', $event->status);
    }

    public function test_duplicate_provider_event_id_is_rejected(): void
    {
        BillingEvent::factory()->create(['provider_event_id' => 'evt_dup_unique']);

        $this->expectException(QueryException::class);
        BillingEvent::factory()->create(['provider_event_id' => 'evt_dup_unique']);
    }

    public function test_event_mark_processed_and_failed(): void
    {
        $event = BillingEvent::factory()->create();

        $event->markProcessed();
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at);

        $event2 = BillingEvent::factory()->create();
        $event2->markFailed('boom');
        $this->assertSame('failed', $event2->fresh()->status);
        $this->assertSame('boom', $event2->fresh()->error_message);
    }
}
