<?php

namespace App\Services\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingServiceEntitlementEvent;
use App\Models\BillingSubscription;
use Illuminate\Database\Eloquent\Model;

/**
 * Entitlement lifecycle service (Phase 25).
 *
 * Creates entitlements from billing state and transitions their lifecycle
 * status through an explicit allowed-transition map, recording an event for
 * every transition.
 *
 * Hard boundaries (the core Phase 25 rule — billing determines entitlement,
 * provisioning fulfills it later):
 *  - NEVER mutates infrastructure (Proxmox/DNS/NetBox/Mail/GamePanel).
 *  - NEVER calls Stripe.
 *  - NEVER calls SIONA or any module provisioning.
 *  - It only reads billing records and writes entitlement rows + events.
 */
class BillingEntitlementService
{
    // Lifecycle event types written to billing_service_entitlement_events.
    public const EVENT_CREATED              = 'created';
    public const EVENT_ACTIVATED            = 'activated';
    public const EVENT_SUSPENDED            = 'suspended';
    public const EVENT_REACTIVATED          = 'reactivated';
    public const EVENT_CANCELLED            = 'cancelled';
    public const EVENT_TERMINATED           = 'terminated';
    public const EVENT_PROVISIONING_PENDING = 'provisioning_pending';
    public const EVENT_PROVISIONING_FAILED  = 'provisioning_failed';

    /**
     * Create an entitlement from a billing subscription, idempotently.
     * A second call for the same subscription+plan returns the existing row.
     */
    public function createFromSubscription(BillingSubscription $subscription, array $overrides = [], ?Model $actor = null): BillingEntitlementResult
    {
        $planId = $subscription->billing_plan_id;
        $key    = $overrides['entitlement_key'] ?? "sub:{$subscription->id}:plan:" . ($planId ?? '0');

        $existing = BillingServiceEntitlement::where('entitlement_key', $key)->first();
        if ($existing !== null) {
            return BillingEntitlementResult::alreadyExists($existing, 'Entitlement already exists for this subscription.');
        }

        $plan     = $subscription->plan;
        $product  = $plan?->product;
        $customer = $subscription->customer;

        $attributes = array_merge([
            'billing_customer_id'     => $subscription->billing_customer_id,
            'billing_subscription_id' => $subscription->id,
            'billing_plan_id'         => $planId,
            'billing_product_id'      => $plan?->billing_product_id,
            'organization_id'         => $customer?->organization_id,
            'user_id'                 => $customer?->user_id,
            'entitlement_key'         => $key,
            'service_type'            => $product?->metadata['service_type'] ?? null,
            'module_key'              => $product?->metadata['module_key'] ?? null,
            'product_key'             => $product?->product_key,
            'name'                    => $plan?->name ?? $product?->name ?? 'Service',
            'description'             => $product?->description,
            'status'                  => BillingServiceEntitlement::STATUS_PENDING,
            'quantity'                => 1,
            'current_period_start'    => $subscription->current_period_start,
            'current_period_end'      => $subscription->current_period_end,
            'metadata'                => null,
        ], $overrides);

        $entitlement = BillingServiceEntitlement::create($attributes);

        $this->recordEvent($entitlement, self::EVENT_CREATED, null, $entitlement->status, 'Created from subscription', $actor);

        return BillingEntitlementResult::created($entitlement, 'Entitlement created from subscription.');
    }

    /**
     * Create an entitlement directly for a customer (explicit grant).
     */
    public function createForCustomer(BillingCustomer $customer, array $attributes = [], ?Model $actor = null): BillingEntitlementResult
    {
        $key = $attributes['entitlement_key'] ?? 'cust:' . $customer->id . ':' . ($attributes['product_key'] ?? uniqid('ent', true));

        $existing = BillingServiceEntitlement::where('entitlement_key', $key)->first();
        if ($existing !== null) {
            return BillingEntitlementResult::alreadyExists($existing, 'Entitlement already exists.');
        }

        $entitlement = BillingServiceEntitlement::create(array_merge([
            'billing_customer_id' => $customer->id,
            'organization_id'     => $customer->organization_id,
            'user_id'             => $customer->user_id,
            'entitlement_key'     => $key,
            'name'                => 'Service',
            'status'              => BillingServiceEntitlement::STATUS_PENDING,
            'quantity'            => 1,
        ], $attributes, ['entitlement_key' => $key]));

        $this->recordEvent($entitlement, self::EVENT_CREATED, null, $entitlement->status, 'Created for customer', $actor);

        return BillingEntitlementResult::created($entitlement, 'Entitlement created.');
    }

    // -------------------------------------------------------------------------
    // Lifecycle transitions

    public function activate(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_ACTIVE, self::EVENT_ACTIVATED, $reason, $actor);
    }

    public function suspend(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_SUSPENDED, self::EVENT_SUSPENDED, $reason, $actor);
    }

    public function reactivate(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        if (! $entitlement->isSuspended()) {
            return BillingEntitlementResult::invalidTransition($entitlement, $entitlement->status, BillingServiceEntitlement::STATUS_ACTIVE);
        }

        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_ACTIVE, self::EVENT_REACTIVATED, $reason, $actor);
    }

    public function cancel(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_CANCELLED, self::EVENT_CANCELLED, $reason, $actor);
    }

    public function terminate(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_TERMINATED, self::EVENT_TERMINATED, $reason, $actor);
    }

    public function markProvisioningPending(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_PROVISIONING_PENDING, self::EVENT_PROVISIONING_PENDING, $reason, $actor);
    }

    public function markProvisioningFailed(BillingServiceEntitlement $entitlement, ?string $reason = null, ?Model $actor = null): BillingEntitlementResult
    {
        return $this->transition($entitlement, BillingServiceEntitlement::STATUS_PROVISIONING_FAILED, self::EVENT_PROVISIONING_FAILED, $reason, $actor);
    }

    // -------------------------------------------------------------------------

    /**
     * Apply a status transition if the allowed-transition map permits it,
     * stamp the relevant lifecycle date, and record an event.
     */
    private function transition(
        BillingServiceEntitlement $entitlement,
        string $newStatus,
        string $eventType,
        ?string $reason,
        ?Model $actor,
    ): BillingEntitlementResult {
        $previous = $entitlement->status;

        if ($previous === $newStatus) {
            return BillingEntitlementResult::unchanged($entitlement, "Entitlement already {$newStatus}.");
        }

        if (! $entitlement->canTransitionTo($newStatus)) {
            return BillingEntitlementResult::invalidTransition($entitlement, $previous, $newStatus);
        }

        $attributes = ['status' => $newStatus];

        switch ($newStatus) {
            case BillingServiceEntitlement::STATUS_ACTIVE:
                $attributes['suspended_at'] = null;
                if ($entitlement->starts_at === null) {
                    $attributes['starts_at'] = now();
                }
                break;
            case BillingServiceEntitlement::STATUS_SUSPENDED:
                $attributes['suspended_at'] = now();
                break;
            case BillingServiceEntitlement::STATUS_CANCELLED:
                $attributes['cancelled_at'] = now();
                break;
            case BillingServiceEntitlement::STATUS_TERMINATED:
                $attributes['terminated_at'] = now();
                break;
        }

        $entitlement->forceFill($attributes)->save();

        $this->recordEvent($entitlement, $eventType, $previous, $newStatus, $reason, $actor);

        return BillingEntitlementResult::transitioned($entitlement, $previous, $newStatus, $reason);
    }

    private function recordEvent(
        BillingServiceEntitlement $entitlement,
        string $eventType,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $reason,
        ?Model $actor,
    ): void {
        BillingServiceEntitlementEvent::create([
            'billing_service_entitlement_id' => $entitlement->id,
            'event_type'                     => $eventType,
            'previous_status'                => $previousStatus,
            'new_status'                     => $newStatus,
            'actor_type'                     => $actor !== null ? $actor::class : null,
            'actor_id'                       => $actor?->getKey(),
            'reason'                         => $reason,
            'metadata'                       => null,
        ]);
    }
}
