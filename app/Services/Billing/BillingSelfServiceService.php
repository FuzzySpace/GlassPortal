<?php

namespace App\Services\Billing;

use App\Models\BillingChangeRequest;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentMethod;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\ProvisioningRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves a signed-in customer's billing scope and returns only the billing
 * records they are authorized to see (Phase 28).
 *
 * The portal is read-/request-only: this service never mutates billing,
 * entitlement, provisioning, or infrastructure state, never calls Stripe, and
 * never exposes secrets. Every query is constrained to billing customers owned
 * by the user's organization or the user themselves, so a customer can never
 * reach another organization's data.
 */
class BillingSelfServiceService
{
    /**
     * The set of billing-customer IDs this user may see: any customer mapped to
     * their organization, plus any customer mapped directly to them.
     *
     * @return array<int>
     */
    public function billingCustomerIds(User $user): array
    {
        $orgId  = $user->organization_id;
        $userId = $user->getKey();

        return BillingCustomer::query()
            ->where(function (Builder $q) use ($orgId, $userId) {
                if ($orgId !== null) {
                    $q->where('organization_id', $orgId);
                }
                $q->orWhere('user_id', $userId);
            })
            ->pluck('id')
            ->all();
    }

    public function hasBillingScope(User $user): bool
    {
        return $this->billingCustomerIds($user) !== [];
    }

    // -------------------------------------------------------------------------
    // Scoped queries — each constrained to the user's billing customers.

    public function subscriptionsQuery(User $user): Builder
    {
        return BillingSubscription::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user))
            ->with(['plan.product', 'customer']);
    }

    public function invoicesQuery(User $user): Builder
    {
        return BillingInvoice::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user))
            ->with('customer');
    }

    public function paymentsQuery(User $user): Builder
    {
        return BillingPayment::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user))
            ->with('invoice');
    }

    public function checkoutSessionsQuery(User $user): Builder
    {
        return BillingCheckoutSession::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user))
            ->with(['plan', 'product']);
    }

    public function paymentMethods(User $user): Collection
    {
        return BillingPaymentMethod::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user))
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    public function entitlementsQuery(User $user): Builder
    {
        return BillingServiceEntitlement::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user))
            ->with(['plan', 'product']);
    }

    public function provisioningRequestsQuery(User $user): Builder
    {
        return ProvisioningRequest::query()
            ->whereIn('billing_customer_id', $this->billingCustomerIds($user));
    }

    public function changeRequestsQuery(User $user): Builder
    {
        return BillingChangeRequest::query()
            ->where('user_id', $user->getKey())
            ->when($user->organization_id !== null, fn (Builder $q) => $q->orWhere('organization_id', $user->organization_id))
            ->with(['subscription.plan', 'plan', 'requestedPlan']);
    }

    // -------------------------------------------------------------------------
    // Ownership checks (for route-model-bound detail pages).

    public function ownsCustomerRecord(User $user, ?int $billingCustomerId): bool
    {
        return $billingCustomerId !== null
            && in_array($billingCustomerId, $this->billingCustomerIds($user), true);
    }

    public function ownsSubscription(User $user, BillingSubscription $subscription): bool
    {
        return $this->ownsCustomerRecord($user, $subscription->billing_customer_id);
    }

    public function ownsInvoice(User $user, BillingInvoice $invoice): bool
    {
        return $this->ownsCustomerRecord($user, $invoice->billing_customer_id);
    }

    public function ownsCheckoutSession(User $user, BillingCheckoutSession $session): bool
    {
        return $this->ownsCustomerRecord($user, $session->billing_customer_id);
    }

    /** A customer owns a change request if it is theirs or their org's. */
    public function ownsChangeRequest(User $user, BillingChangeRequest $request): bool
    {
        if ($request->user_id === $user->getKey()) {
            return true;
        }

        return $user->organization_id !== null
            && $request->organization_id === $user->organization_id;
    }

    // -------------------------------------------------------------------------
    // Dashboard summary.

    /**
     * Build the customer billing dashboard summary. Collections are capped for
     * display; nothing here mutates state.
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $activeSubscriptions  = (clone $this->subscriptionsQuery($user))
            ->whereIn('status', BillingSubscription::LIVE_STATUSES)
            ->orderByDesc('created_at')->get();

        $pastDueSubscriptions = (clone $this->subscriptionsQuery($user))
            ->where('status', 'past_due')
            ->orderByDesc('created_at')->get();

        $recentInvoices         = $this->invoicesQuery($user)->orderByDesc('created_at')->limit(5)->get();
        $recentPayments         = $this->paymentsQuery($user)->orderByDesc('created_at')->limit(5)->get();
        $recentCheckoutSessions = $this->checkoutSessionsQuery($user)->orderByDesc('created_at')->limit(5)->get();

        $entitlements = $this->entitlementsQuery($user)
            ->whereIn('status', [
                BillingServiceEntitlement::STATUS_ACTIVE,
                BillingServiceEntitlement::STATUS_PENDING,
                BillingServiceEntitlement::STATUS_PROVISIONING_PENDING,
            ])
            ->orderByDesc('created_at')->get();

        $pendingProvisioning = $this->provisioningRequestsQuery($user)
            ->whereNotIn('status', ProvisioningRequest::TERMINAL_STATUSES)
            ->orderByDesc('created_at')->get();

        $openChangeRequests = $this->changeRequestsQuery($user)
            ->whereNotIn('status', BillingChangeRequest::TERMINAL_STATUSES)
            ->orderByDesc('created_at')->get();

        return [
            'hasOrg'                 => $user->organization_id !== null,
            'hasBillingScope'        => $this->hasBillingScope($user),
            'activeSubscriptions'    => $activeSubscriptions,
            'pastDueSubscriptions'   => $pastDueSubscriptions,
            'recentInvoices'         => $recentInvoices,
            'recentPayments'         => $recentPayments,
            'recentCheckoutSessions' => $recentCheckoutSessions,
            'entitlements'           => $entitlements,
            'pendingProvisioning'    => $pendingProvisioning,
            'openChangeRequests'     => $openChangeRequests,
            'paymentMethods'         => $this->paymentMethods($user),
            'warnings'               => $this->warnings($pastDueSubscriptions, $pendingProvisioning, $recentInvoices),
        ];
    }

    /**
     * Plain, customer-safe "next action" strings. No secrets, no IDs.
     *
     * @return array<int, string>
     */
    private function warnings(Collection $pastDue, Collection $pendingProvisioning, Collection $recentInvoices): array
    {
        $warnings = [];

        if ($pastDue->isNotEmpty()) {
            $warnings[] = "You have {$pastDue->count()} past-due subscription(s). Please update your billing to avoid interruption.";
        }

        $openInvoices = $recentInvoices->where('status', 'open')->count();
        if ($openInvoices > 0) {
            $warnings[] = "You have {$openInvoices} open invoice(s) awaiting payment.";
        }

        if ($pendingProvisioning->isNotEmpty()) {
            $warnings[] = "{$pendingProvisioning->count()} service request(s) are in progress and awaiting review.";
        }

        return $warnings;
    }
}
