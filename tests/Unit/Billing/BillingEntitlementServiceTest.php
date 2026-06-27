<?php

namespace Tests\Unit\Billing;

use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Services\Billing\BillingEntitlementResult;
use App\Services\Billing\BillingEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 25 — entitlement lifecycle service: creation, idempotency, the
 * allowed-transition state machine, event recording, and the no-infrastructure
 * boundary.
 */
class BillingEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BillingEntitlementService
    {
        return app(BillingEntitlementService::class);
    }

    private function pendingEntitlement(): BillingServiceEntitlement
    {
        $sub = BillingSubscription::factory()->create();

        return $this->service()->createFromSubscription($sub)->entitlement;
    }

    // -------------------------------------------------------------------------
    // Creation + idempotency
    // -------------------------------------------------------------------------

    public function test_create_from_subscription(): void
    {
        $sub    = BillingSubscription::factory()->create();
        $result = $this->service()->createFromSubscription($sub);

        $this->assertTrue($result->ok);
        $this->assertSame(BillingEntitlementResult::OUTCOME_CREATED, $result->status);
        $this->assertSame(BillingServiceEntitlement::STATUS_PENDING, $result->entitlement->status);
        $this->assertSame($sub->id, $result->entitlement->billing_subscription_id);
        $this->assertSame($sub->billing_plan_id, $result->entitlement->billing_plan_id);

        // A 'created' event is recorded.
        $this->assertDatabaseHas('billing_service_entitlement_events', [
            'billing_service_entitlement_id' => $result->entitlement->id,
            'event_type'                     => 'created',
            'new_status'                     => 'pending',
        ]);
    }

    public function test_idempotency_prevents_duplicate_for_same_subscription(): void
    {
        $sub = BillingSubscription::factory()->create();

        $first  = $this->service()->createFromSubscription($sub);
        $second = $this->service()->createFromSubscription($sub);

        $this->assertSame(BillingEntitlementResult::OUTCOME_ALREADY_EXISTS, $second->status);
        $this->assertSame($first->entitlement->id, $second->entitlement->id);
        $this->assertSame(1, BillingServiceEntitlement::where('billing_subscription_id', $sub->id)->count());
    }

    // -------------------------------------------------------------------------
    // Valid transitions
    // -------------------------------------------------------------------------

    public function test_pending_to_active(): void
    {
        $e = $this->pendingEntitlement();

        $result = $this->service()->activate($e, 'subscription paid');

        $this->assertTrue($result->ok);
        $this->assertSame('pending', $result->previousStatus);
        $this->assertSame('active', $result->newStatus);
        $this->assertSame('active', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->starts_at);
    }

    public function test_active_to_suspended_and_back(): void
    {
        $e = $this->pendingEntitlement();
        $this->service()->activate($e);

        $this->assertTrue($this->service()->suspend($e, 'non-payment')->ok);
        $this->assertSame('suspended', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->suspended_at);

        $this->assertTrue($this->service()->reactivate($e)->ok);
        $this->assertSame('active', $e->fresh()->status);
        $this->assertNull($e->fresh()->suspended_at);
    }

    public function test_active_to_cancelled_to_terminated(): void
    {
        $e = $this->pendingEntitlement();
        $this->service()->activate($e);

        $this->assertTrue($this->service()->cancel($e)->ok);
        $this->assertSame('cancelled', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->cancelled_at);

        $this->assertTrue($this->service()->terminate($e)->ok);
        $this->assertSame('terminated', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->terminated_at);
    }

    public function test_provisioning_pending_and_failed_cycle(): void
    {
        $e = $this->pendingEntitlement();
        $this->service()->activate($e);

        $this->assertTrue($this->service()->markProvisioningPending($e)->ok);
        $this->assertSame('provisioning_pending', $e->fresh()->status);

        $this->assertTrue($this->service()->markProvisioningFailed($e)->ok);
        $this->assertSame('provisioning_failed', $e->fresh()->status);

        // failed -> pending again, then -> active.
        $this->assertTrue($this->service()->markProvisioningPending($e)->ok);
        $this->assertTrue($this->service()->activate($e)->ok);
        $this->assertSame('active', $e->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Invalid transitions fail safely
    // -------------------------------------------------------------------------

    public function test_invalid_transition_fails_safely(): void
    {
        $e = $this->pendingEntitlement();
        $this->service()->activate($e);
        $this->service()->terminate($e); // active -> terminated (valid)

        $result = $this->service()->activate($e->fresh()); // terminated -> active (invalid)

        $this->assertFalse($result->ok);
        $this->assertSame(BillingEntitlementResult::OUTCOME_INVALID_TRANSITION, $result->status);
        $this->assertSame('terminated', $e->fresh()->status); // unchanged
    }

    public function test_reactivate_only_from_suspended(): void
    {
        $e = $this->pendingEntitlement(); // pending

        $result = $this->service()->reactivate($e);

        $this->assertFalse($result->ok);
        $this->assertSame(BillingEntitlementResult::OUTCOME_INVALID_TRANSITION, $result->status);
    }

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------

    public function test_events_record_previous_and_new_status(): void
    {
        $e = $this->pendingEntitlement();
        $this->service()->activate($e, 'paid');
        $this->service()->suspend($e, 'dunning');

        $suspendEvent = $e->events()->where('event_type', 'suspended')->first();
        $this->assertSame('active', $suspendEvent->previous_status);
        $this->assertSame('suspended', $suspendEvent->new_status);
        $this->assertSame('dunning', $suspendEvent->reason);

        // created + activated + suspended = 3 events.
        $this->assertSame(3, $e->events()->count());
    }

    // -------------------------------------------------------------------------
    // No-infrastructure boundary
    // -------------------------------------------------------------------------

    public function test_service_makes_no_external_calls(): void
    {
        Http::fake();

        $e = $this->pendingEntitlement();
        $this->service()->activate($e);
        $this->service()->markProvisioningPending($e);
        $this->service()->markProvisioningFailed($e);
        $this->service()->markProvisioningPending($e);
        $this->service()->activate($e);
        $this->service()->suspend($e);
        $this->service()->cancel($e);
        $this->service()->terminate($e);

        // The entitlement service never calls Stripe/SIONA/Proxmox/DNS/etc.
        Http::assertNothingSent();
    }

    public function test_service_source_has_no_ghpanel_reference(): void
    {
        $source = file_get_contents(app_path('Services/Billing/BillingEntitlementService.php'));
        $this->assertStringNotContainsStringIgnoringCase('ghpanel', $source);
    }
}
