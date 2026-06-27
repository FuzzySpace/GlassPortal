<?php

namespace App\Services\Provisioning;

use App\Models\BillingServiceEntitlement;
use App\Models\ProvisioningRequest;
use App\Models\ProvisioningRequestEvent;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Provisioning request engine (Phase 26).
 *
 * Creates approval-gated provisioning requests from billing entitlements and
 * transitions their status through an explicit allowed-transition map, recording
 * an event for every transition. It safely reflects request progress back onto
 * the entitlement's *billing* lifecycle (provisioning_pending / active /
 * provisioning_failed) via BillingEntitlementService.
 *
 * Hard boundaries:
 *  - NEVER executes infrastructure, even on `complete` — only request + billing
 *    entitlement state are updated.
 *  - NEVER calls Stripe, SIONA, Proxmox, DNS, NetBox, Mail, or GamePanel.
 *  - Drivers are metadata only (config/provisioning.php); nothing is dispatched.
 */
class ProvisioningRequestService
{
    public const EVENT_CREATED   = 'created';
    public const EVENT_APPROVED  = 'approved';
    public const EVENT_REJECTED  = 'rejected';
    public const EVENT_QUEUED    = 'queued';
    public const EVENT_STARTED   = 'started';
    public const EVENT_COMPLETED = 'completed';
    public const EVENT_FAILED    = 'failed';
    public const EVENT_CANCELLED = 'cancelled';

    public function __construct(private BillingEntitlementService $entitlements) {}

    /**
     * Create a provisioning request from a billing entitlement, idempotently.
     * A duplicate idempotency_key, or an existing OPEN request for the same
     * entitlement+action, returns the existing request rather than a new one.
     */
    public function createFromEntitlement(
        BillingServiceEntitlement $entitlement,
        string $action = ProvisioningRequest::ACTION_PROVISION,
        array $payload = [],
        array $options = [],
        ?Model $actor = null,
    ): ProvisioningRequestResult {
        $idempotencyKey = $options['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return ProvisioningRequestResult::alreadyExists($existing, 'Request already exists for this idempotency key.');
            }
        }

        $openExisting = ProvisioningRequest::where('billing_service_entitlement_id', $entitlement->id)
            ->forAction($action)
            ->open()
            ->first();
        if ($openExisting !== null) {
            return ProvisioningRequestResult::alreadyExists($openExisting, 'An open request already exists for this entitlement and action.');
        }

        $requiresApproval = $options['requires_approval'] ?? true;
        $status           = $requiresApproval ? ProvisioningRequest::STATUS_PENDING_APPROVAL : ProvisioningRequest::STATUS_APPROVED;

        $request = ProvisioningRequest::create([
            'request_key'                    => $options['request_key'] ?? 'preq:' . $entitlement->id . ':' . $action . ':' . Str::lower(Str::random(10)),
            'billing_service_entitlement_id' => $entitlement->id,
            'billing_customer_id'            => $entitlement->billing_customer_id,
            'organization_id'                => $entitlement->organization_id,
            'user_id'                        => $entitlement->user_id,
            'module_key'                     => $entitlement->module_key,
            'product_key'                    => $entitlement->product_key,
            'service_type'                   => $entitlement->service_type,
            'driver_key'                     => $options['driver_key'] ?? $entitlement->module_key ?? config('provisioning.default_driver', 'manual'),
            'requested_action'               => $action,
            'status'                         => $status,
            'priority'                       => $options['priority'] ?? 'normal',
            'requires_approval'              => $requiresApproval,
            'approved_at'                    => $status === ProvisioningRequest::STATUS_APPROVED ? now() : null,
            'idempotency_key'                => $idempotencyKey,
            'payload'                        => $payload ?: null,
            'metadata'                       => $options['metadata'] ?? null,
        ]);

        $this->recordEvent($request, self::EVENT_CREATED, null, $request->status, 'Request created from entitlement', $actor);

        // Safe hand-off: a provision request moves the entitlement to
        // provisioning_pending (billing state only — no infrastructure).
        if ($action === ProvisioningRequest::ACTION_PROVISION) {
            $this->markEntitlement($request, 'markProvisioningPending', $actor);
        }

        return ProvisioningRequestResult::created($request, 'Provisioning request created.');
    }

    // -------------------------------------------------------------------------
    // Transitions

    public function approve(ProvisioningRequest $request, User $actor, ?string $reason = null): ProvisioningRequestResult
    {
        return $this->transition($request, ProvisioningRequest::STATUS_APPROVED, self::EVENT_APPROVED, $reason, $actor, [
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ]);
    }

    public function reject(ProvisioningRequest $request, User $actor, ?string $reason = null): ProvisioningRequestResult
    {
        return $this->transition($request, ProvisioningRequest::STATUS_REJECTED, self::EVENT_REJECTED, $reason, $actor, [
            'rejected_by' => $actor->getKey(),
            'rejected_at' => now(),
        ]);
    }

    public function queue(ProvisioningRequest $request, ?string $reason = null, ?Model $actor = null): ProvisioningRequestResult
    {
        return $this->transition($request, ProvisioningRequest::STATUS_QUEUED, self::EVENT_QUEUED, $reason, $actor);
    }

    public function start(ProvisioningRequest $request, ?string $reason = null, ?Model $actor = null): ProvisioningRequestResult
    {
        return $this->transition($request, ProvisioningRequest::STATUS_RUNNING, self::EVENT_STARTED, $reason, $actor, [
            'started_at' => now(),
        ]);
    }

    /**
     * Mark a running request complete. Updates request + entitlement state ONLY
     * — it does not provision any infrastructure.
     */
    public function complete(ProvisioningRequest $request, array $result = [], ?string $reason = null, ?Model $actor = null): ProvisioningRequestResult
    {
        $outcome = $this->transition($request, ProvisioningRequest::STATUS_COMPLETED, self::EVENT_COMPLETED, $reason, $actor, [
            'completed_at' => now(),
            'result'       => $result ?: null,
        ]);

        if ($outcome->ok && $request->requested_action === ProvisioningRequest::ACTION_PROVISION) {
            $this->markEntitlement($request, 'activate', $actor);
        }

        return $outcome;
    }

    public function fail(ProvisioningRequest $request, ?string $reason = null, ?Model $actor = null): ProvisioningRequestResult
    {
        $outcome = $this->transition($request, ProvisioningRequest::STATUS_FAILED, self::EVENT_FAILED, $reason, $actor, [
            'failed_at'      => now(),
            'failure_reason' => $reason,
        ]);

        if ($outcome->ok && $request->requested_action === ProvisioningRequest::ACTION_PROVISION) {
            $this->markEntitlement($request, 'markProvisioningFailed', $actor);
        }

        return $outcome;
    }

    public function cancel(ProvisioningRequest $request, ?string $reason = null, ?Model $actor = null): ProvisioningRequestResult
    {
        return $this->transition($request, ProvisioningRequest::STATUS_CANCELLED, self::EVENT_CANCELLED, $reason, $actor, [
            'cancelled_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------

    private function transition(
        ProvisioningRequest $request,
        string $newStatus,
        string $eventType,
        ?string $reason,
        ?Model $actor,
        array $extraAttributes = [],
    ): ProvisioningRequestResult {
        $previous = $request->status;

        if ($previous === $newStatus) {
            return ProvisioningRequestResult::unchanged($request, "Request already {$newStatus}.");
        }

        if (! $request->canTransitionTo($newStatus)) {
            return ProvisioningRequestResult::invalidTransition($request, $previous, $newStatus);
        }

        $request->forceFill(array_merge(['status' => $newStatus], $extraAttributes))->save();

        $this->recordEvent($request, $eventType, $previous, $newStatus, $reason, $actor);

        return ProvisioningRequestResult::transitioned($request, $previous, $newStatus, $reason);
    }

    /**
     * Reflect request progress onto the entitlement's billing lifecycle.
     * Best-effort: the entitlement service safely rejects invalid transitions,
     * and a missing entitlement is a no-op. Never touches infrastructure.
     */
    private function markEntitlement(ProvisioningRequest $request, string $method, ?Model $actor): void
    {
        $entitlement = $request->entitlement;
        if ($entitlement === null) {
            return;
        }

        $this->entitlements->{$method}($entitlement, "provisioning request #{$request->id}", $actor);
    }

    private function recordEvent(
        ProvisioningRequest $request,
        string $eventType,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $message,
        ?Model $actor,
    ): void {
        ProvisioningRequestEvent::create([
            'provisioning_request_id' => $request->id,
            'event_type'              => $eventType,
            'previous_status'         => $previousStatus,
            'new_status'              => $newStatus,
            'actor_type'              => $actor !== null ? $actor::class : null,
            'actor_id'                => $actor?->getKey(),
            'message'                 => $message,
            'metadata'                => null,
        ]);
    }
}
