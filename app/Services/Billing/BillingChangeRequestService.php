<?php

namespace App\Services\Billing;

use App\Models\BillingChangeRequest;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Customer billing change-request workflow (Phase 28).
 *
 * Records and transitions customer-submitted billing change requests. These are
 * **workflow records only**:
 *  - NEVER calls Stripe or mutates subscriptions/invoices/payments.
 *  - NEVER mutates entitlements, provisioning, or infrastructure.
 *  - NEVER bypasses the Phase 26 provisioning engine.
 * Staff review and act on the request through the existing approval layers; an
 * approved/completed request here is purely a status change.
 *
 * Ownership is enforced on submit and customer-cancel so a customer can only
 * act on their own (or their organization's) records.
 */
class BillingChangeRequestService
{
    public function __construct(private BillingSelfServiceService $scope) {}

    /**
     * Submit a new change request on behalf of a customer.
     *
     * @param array{billing_subscription_id?: int|null, requested_plan_id?: int|null, reason?: string|null, customer_message?: string|null} $data
     */
    public function submit(User $user, string $type, array $data = []): BillingChangeRequestResult
    {
        if (! in_array($type, BillingChangeRequest::TYPES, true)) {
            return BillingChangeRequestResult::failed('Unknown change request type.');
        }

        // Resolve + authorize an optional referenced subscription.
        $subscription = null;
        $subscriptionId = $data['billing_subscription_id'] ?? null;
        if ($subscriptionId) {
            $subscription = BillingSubscription::find($subscriptionId);
            if ($subscription === null || ! $this->scope->ownsSubscription($user, $subscription)) {
                return BillingChangeRequestResult::forbidden('You can only request changes to your own subscriptions.');
            }
        }

        // cancel_subscription / change_plan must reference a subscription.
        if (in_array($type, [BillingChangeRequest::TYPE_CANCEL_SUBSCRIPTION, BillingChangeRequest::TYPE_CHANGE_PLAN], true)
            && $subscription === null) {
            return BillingChangeRequestResult::failed('This request type requires a subscription.');
        }

        // change_plan must reference a valid, active target plan.
        $requestedPlan = null;
        $requestedPlanId = $data['requested_plan_id'] ?? null;
        if ($type === BillingChangeRequest::TYPE_CHANGE_PLAN) {
            $requestedPlan = $requestedPlanId ? BillingPlan::where('status', 'active')->find($requestedPlanId) : null;
            if ($requestedPlan === null) {
                return BillingChangeRequestResult::failed('Please choose a valid plan to change to.');
            }
        }

        $request = BillingChangeRequest::create([
            'request_key'             => $this->generateKey(),
            'organization_id'         => $user->organization_id,
            'user_id'                 => $user->getKey(),
            'billing_subscription_id' => $subscription?->id,
            'billing_plan_id'         => $subscription?->billing_plan_id,
            'requested_plan_id'       => $requestedPlan?->id,
            'request_type'            => $type,
            'status'                  => BillingChangeRequest::STATUS_SUBMITTED,
            'reason'                  => $this->clean($data['reason'] ?? null),
            'customer_message'        => $this->clean($data['customer_message'] ?? null),
            'requested_at'            => now(),
        ]);

        return BillingChangeRequestResult::created($request, 'Your billing request has been submitted.');
    }

    /**
     * A customer withdraws their own request (only while still `submitted`).
     */
    public function customerCancel(User $user, BillingChangeRequest $request): BillingChangeRequestResult
    {
        if (! $this->scope->ownsChangeRequest($user, $request)) {
            return BillingChangeRequestResult::forbidden('You can only cancel your own requests.', $request);
        }

        if (! $request->isCustomerCancellable()) {
            return BillingChangeRequestResult::forbidden('This request can no longer be cancelled.', $request);
        }

        return $this->transition($request, BillingChangeRequest::STATUS_CANCELLED, null, ['cancelled_at' => now()]);
    }

    // -------------------------------------------------------------------------
    // Admin workflow transitions.

    public function markUnderReview(BillingChangeRequest $request, User $actor, ?string $notes = null): BillingChangeRequestResult
    {
        return $this->transition($request, BillingChangeRequest::STATUS_UNDER_REVIEW, $actor, [
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ], $notes);
    }

    public function approve(BillingChangeRequest $request, User $actor, ?string $notes = null): BillingChangeRequestResult
    {
        return $this->transition($request, BillingChangeRequest::STATUS_APPROVED, $actor, [
            'reviewed_by' => $request->reviewed_by ?? $actor->getKey(),
            'reviewed_at' => $request->reviewed_at ?? now(),
        ], $notes);
    }

    public function reject(BillingChangeRequest $request, User $actor, ?string $notes = null): BillingChangeRequestResult
    {
        return $this->transition($request, BillingChangeRequest::STATUS_REJECTED, $actor, [
            'reviewed_by' => $request->reviewed_by ?? $actor->getKey(),
            'reviewed_at' => $request->reviewed_at ?? now(),
        ], $notes);
    }

    public function complete(BillingChangeRequest $request, User $actor, ?string $notes = null): BillingChangeRequestResult
    {
        return $this->transition($request, BillingChangeRequest::STATUS_COMPLETED, $actor, [
            'completed_at' => now(),
        ], $notes);
    }

    public function cancel(BillingChangeRequest $request, User $actor, ?string $notes = null): BillingChangeRequestResult
    {
        return $this->transition($request, BillingChangeRequest::STATUS_CANCELLED, $actor, [
            'cancelled_at' => now(),
        ], $notes);
    }

    // -------------------------------------------------------------------------

    /**
     * Apply a lifecycle transition if the allowed-transition map permits it,
     * stamp the relevant columns, optionally append an admin note. No external
     * side effects — this only writes to the change-request row.
     *
     * @param array<string, mixed> $stamps
     */
    private function transition(
        BillingChangeRequest $request,
        string $newStatus,
        ?User $actor,
        array $stamps = [],
        ?string $notes = null,
    ): BillingChangeRequestResult {
        $previous = $request->status;

        if ($previous === $newStatus) {
            return BillingChangeRequestResult::invalidTransition($request, $previous, $newStatus);
        }

        if (! $request->canTransitionTo($newStatus)) {
            return BillingChangeRequestResult::invalidTransition($request, $previous, $newStatus);
        }

        $attributes = array_merge(['status' => $newStatus], $stamps);

        $note = $this->clean($notes);
        if ($note !== null) {
            $attributes['admin_notes'] = $request->admin_notes
                ? $request->admin_notes . "\n" . $note
                : $note;
        }

        $request->forceFill($attributes)->save();

        return BillingChangeRequestResult::transitioned($request, $previous, $newStatus, "Request {$previous} → {$newStatus}.");
    }

    private function generateKey(): string
    {
        return 'bcr_' . Str::lower(Str::random(20));
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
