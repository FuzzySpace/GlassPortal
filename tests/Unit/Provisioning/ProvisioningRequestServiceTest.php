<?php

namespace Tests\Unit\Provisioning;

use App\Models\BillingServiceEntitlement;
use App\Models\ProvisioningRequest;
use App\Models\User;
use App\Services\Provisioning\ProvisioningRequestResult;
use App\Services\Provisioning\ProvisioningRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 26 — provisioning request engine: creation, idempotency, the
 * allowed-transition state machine, event recording, safe entitlement hand-off,
 * and the no-infrastructure boundary.
 */
class ProvisioningRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProvisioningRequestService
    {
        return app(ProvisioningRequestService::class);
    }

    private function entitlement(): BillingServiceEntitlement
    {
        return BillingServiceEntitlement::factory()->create(['status' => 'active']);
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function openRequest(): ProvisioningRequest
    {
        return $this->service()->createFromEntitlement($this->entitlement())->request;
    }

    // -------------------------------------------------------------------------
    // Creation + idempotency
    // -------------------------------------------------------------------------

    public function test_create_request_from_entitlement_defaults_to_pending_approval(): void
    {
        $entitlement = $this->entitlement();
        $result      = $this->service()->createFromEntitlement($entitlement, 'provision', ['plan' => 'pro']);

        $this->assertTrue($result->ok);
        $this->assertSame(ProvisioningRequestResult::OUTCOME_CREATED, $result->status);
        $this->assertSame('pending_approval', $result->request->status);
        $this->assertTrue($result->request->requires_approval);
        $this->assertSame($entitlement->id, $result->request->billing_service_entitlement_id);

        // The provision request moves the entitlement to provisioning_pending.
        $this->assertSame('provisioning_pending', $entitlement->fresh()->status);

        $this->assertDatabaseHas('provisioning_request_events', [
            'provisioning_request_id' => $result->request->id,
            'event_type'              => 'created',
            'new_status'              => 'pending_approval',
        ]);
    }

    public function test_idempotency_prevents_duplicate_open_request(): void
    {
        $entitlement = $this->entitlement();

        $first  = $this->service()->createFromEntitlement($entitlement);
        $second = $this->service()->createFromEntitlement($entitlement);

        $this->assertSame(ProvisioningRequestResult::OUTCOME_ALREADY_EXISTS, $second->status);
        $this->assertSame($first->request->id, $second->request->id);
        $this->assertSame(1, ProvisioningRequest::where('billing_service_entitlement_id', $entitlement->id)->count());
    }

    public function test_idempotency_key_blocks_duplicate(): void
    {
        $a = $this->service()->createFromEntitlement($this->entitlement(), 'provision', [], ['idempotency_key' => 'idem-1']);
        $b = $this->service()->createFromEntitlement($this->entitlement(), 'provision', [], ['idempotency_key' => 'idem-1']);

        $this->assertSame(ProvisioningRequestResult::OUTCOME_ALREADY_EXISTS, $b->status);
        $this->assertSame($a->request->id, $b->request->id);
    }

    // -------------------------------------------------------------------------
    // Transitions
    // -------------------------------------------------------------------------

    public function test_approve_and_reject(): void
    {
        $request = $this->openRequest();
        $actor   = $this->actor();

        $this->assertTrue($this->service()->approve($request, $actor, 'looks good')->ok);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($actor->id, $request->fresh()->approved_by);
        $this->assertNotNull($request->fresh()->approved_at);

        $other = $this->openRequest();
        $this->assertTrue($this->service()->reject($other, $actor, 'denied')->ok);
        $this->assertSame('rejected', $other->fresh()->status);
        $this->assertSame($actor->id, $other->fresh()->rejected_by);
    }

    public function test_queue_start_complete(): void
    {
        $request = $this->openRequest();
        $this->service()->approve($request, $this->actor());

        $this->assertTrue($this->service()->queue($request)->ok);
        $this->assertSame('queued', $request->fresh()->status);

        $this->assertTrue($this->service()->start($request)->ok);
        $this->assertSame('running', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->started_at);

        $this->assertTrue($this->service()->complete($request, ['vm_id' => 42])->ok);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(42, $request->fresh()->result['vm_id']);
    }

    public function test_fail_then_requeue(): void
    {
        $request = $this->openRequest();
        $this->service()->approve($request, $this->actor());
        $this->service()->queue($request);
        $this->service()->start($request);

        $this->assertTrue($this->service()->fail($request, 'driver error')->ok);
        $this->assertSame('failed', $request->fresh()->status);
        $this->assertSame('driver error', $request->fresh()->failure_reason);

        // failed -> queued retry is allowed.
        $this->assertTrue($this->service()->queue($request)->ok);
        $this->assertSame('queued', $request->fresh()->status);
    }

    public function test_invalid_transition_fails_safely(): void
    {
        $request = $this->openRequest(); // pending_approval

        $result = $this->service()->complete($request); // pending_approval -> completed (invalid)

        $this->assertFalse($result->ok);
        $this->assertSame(ProvisioningRequestResult::OUTCOME_INVALID_TRANSITION, $result->status);
        $this->assertSame('pending_approval', $request->fresh()->status);
    }

    public function test_events_record_previous_and_new_status(): void
    {
        $request = $this->openRequest();
        $this->service()->approve($request, $this->actor(), 'ok');

        $event = $request->events()->where('event_type', 'approved')->first();
        $this->assertSame('pending_approval', $event->previous_status);
        $this->assertSame('approved', $event->new_status);
        $this->assertSame('User', class_basename($event->actor_type));
    }

    // -------------------------------------------------------------------------
    // Entitlement hand-off (billing state only — no infrastructure)
    // -------------------------------------------------------------------------

    public function test_completed_request_marks_entitlement_active(): void
    {
        $entitlement = $this->entitlement();
        $request     = $this->service()->createFromEntitlement($entitlement)->request;
        $this->service()->approve($request, $this->actor());
        $this->service()->queue($request);
        $this->service()->start($request);
        $this->service()->complete($request);

        $this->assertSame('active', $entitlement->fresh()->status);
    }

    public function test_failed_request_marks_entitlement_provisioning_failed(): void
    {
        $entitlement = $this->entitlement();
        $request     = $this->service()->createFromEntitlement($entitlement)->request;
        $this->service()->approve($request, $this->actor());
        $this->service()->queue($request);
        $this->service()->start($request);
        $this->service()->fail($request, 'boom');

        $this->assertSame('provisioning_failed', $entitlement->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // No-infrastructure boundary
    // -------------------------------------------------------------------------

    public function test_engine_makes_no_external_calls(): void
    {
        Http::fake();

        $entitlement = $this->entitlement();
        $request     = $this->service()->createFromEntitlement($entitlement)->request;
        $actor       = $this->actor();
        $this->service()->approve($request, $actor);
        $this->service()->queue($request);
        $this->service()->start($request);
        $this->service()->complete($request, ['ok' => true]);

        // The engine never calls Stripe/SIONA/Proxmox/DNS/etc — even on complete.
        Http::assertNothingSent();
    }

    public function test_service_source_has_no_ghpanel_reference(): void
    {
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningRequestService.php'));
        $this->assertStringNotContainsStringIgnoringCase('ghpanel', $source);
    }

    public function test_driver_registry_is_metadata_only(): void
    {
        // Drivers are config metadata; the engine never instantiates or dispatches them.
        $drivers = config('provisioning.drivers');
        $this->assertIsArray($drivers);
        $this->assertArrayHasKey('manual', $drivers);
        // No driver class/handler is referenced by the service.
        $source = file_get_contents(app_path('Services/Provisioning/ProvisioningRequestService.php'));
        $this->assertStringNotContainsString('->execute(', $source);
        $this->assertStringNotContainsString('dispatch(', $source);
    }
}
