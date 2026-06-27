<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingServiceEntitlementEvent;
use App\Models\BillingSubscription;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 25 — entitlement model: tables, relationships, scopes, helpers.
 */
class BillingEntitlementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitlement_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('billing_service_entitlements'));
        $this->assertTrue(Schema::hasTable('billing_service_entitlement_events'));
    }

    public function test_factories_create_valid_records(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->create();
        $event       = BillingServiceEntitlementEvent::factory()->create();

        $this->assertDatabaseHas('billing_service_entitlements', ['id' => $entitlement->id]);
        $this->assertDatabaseHas('billing_service_entitlement_events', ['id' => $event->id]);
    }

    public function test_relationships_work(): void
    {
        $sub         = BillingSubscription::factory()->create();
        $entitlement = BillingServiceEntitlement::factory()->forSubscription($sub)->create();
        BillingServiceEntitlementEvent::factory()->create(['billing_service_entitlement_id' => $entitlement->id]);

        $this->assertInstanceOf(BillingCustomer::class, $entitlement->customer);
        $this->assertSame($sub->id, $entitlement->subscription->id);
        $this->assertNotNull($entitlement->plan);
        $this->assertCount(1, $entitlement->events);

        // Reverse relationships.
        $this->assertTrue($entitlement->customer->serviceEntitlements->contains($entitlement));
        $this->assertTrue($sub->serviceEntitlements->contains($entitlement));
    }

    public function test_organization_reverse_relationship(): void
    {
        $org         = Organization::factory()->create();
        $customer    = BillingCustomer::factory()->forOrganization($org)->create();
        $entitlement = BillingServiceEntitlement::factory()->create([
            'billing_customer_id' => $customer->id,
            'organization_id'     => $org->id,
        ]);

        $this->assertTrue($org->billingServiceEntitlements->contains($entitlement));
    }

    public function test_status_helpers(): void
    {
        $active = BillingServiceEntitlement::factory()->create(['status' => 'active']);
        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isSuspended());
        $this->assertFalse($active->isTerminal());
        $this->assertTrue($active->canSuspend());
        $this->assertTrue($active->canCancel());
        $this->assertTrue($active->canTerminate());
        $this->assertTrue($active->canProvision());
        $this->assertFalse($active->canReactivate());

        $suspended = BillingServiceEntitlement::factory()->suspended()->create();
        $this->assertTrue($suspended->isSuspended());
        $this->assertTrue($suspended->canReactivate());
        $this->assertFalse($suspended->canProvision());

        $terminated = BillingServiceEntitlement::factory()->status('terminated')->create();
        $this->assertTrue($terminated->isTerminal());
        $this->assertFalse($terminated->canSuspend());
        $this->assertFalse($terminated->canTerminate());
        $this->assertFalse($terminated->canProvision());
    }

    public function test_transition_map_gates_correctly(): void
    {
        $pending = BillingServiceEntitlement::factory()->pending()->create();
        $this->assertTrue($pending->canTransitionTo('active'));
        $this->assertTrue($pending->canTransitionTo('cancelled'));
        $this->assertFalse($pending->canTransitionTo('terminated'));
        $this->assertFalse($pending->canTransitionTo('suspended'));
    }

    public function test_scopes(): void
    {
        BillingServiceEntitlement::factory()->create(['status' => 'active']);
        BillingServiceEntitlement::factory()->pending()->create();
        BillingServiceEntitlement::factory()->suspended()->create();
        BillingServiceEntitlement::factory()->status('cancelled')->create();
        BillingServiceEntitlement::factory()->status('terminated')->create();

        $this->assertSame(1, BillingServiceEntitlement::active()->count());
        $this->assertSame(1, BillingServiceEntitlement::pending()->count());
        $this->assertSame(1, BillingServiceEntitlement::suspended()->count());
        $this->assertSame(1, BillingServiceEntitlement::cancelled()->count());
        $this->assertSame(1, BillingServiceEntitlement::terminated()->count());
    }

    public function test_period_dates_and_metadata_are_cast(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->create([
            'current_period_end' => now()->addMonth(),
            'metadata'           => ['note' => 'x'],
        ]);

        $fresh = $entitlement->fresh();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->current_period_end);
        $this->assertIsArray($fresh->metadata);
    }
}
