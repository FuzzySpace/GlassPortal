<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BillingChangeRequest;
use App\Models\BillingPlan;
use App\Services\Billing\BillingChangeRequestService;
use App\Services\Billing\BillingSelfServiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer billing change requests (Phase 28).
 *
 * Lets a customer submit a billing change *request* (cancel, change plan,
 * billing support, etc.) and withdraw their own request while it is still
 * pending. These are workflow records only — submitting one never calls Stripe
 * or mutates billing/subscription/entitlement/provisioning state. Staff act on
 * them through the admin workflow.
 */
class BillingChangeRequestController extends Controller
{
    public function __construct(
        private BillingSelfServiceService $self,
        private BillingChangeRequestService $changeRequests,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.billing.change-requests', [
            'requests' => $this->self->changeRequestsQuery($request->user())
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();

        return view('portal.billing.change-request-create', [
            'subscriptions' => $this->self->subscriptionsQuery($user)->orderByDesc('created_at')->get(),
            'plans'         => BillingPlan::active()->with('product')->orderBy('amount_cents')->get(),
            'types'         => BillingChangeRequest::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'request_type'            => ['required', 'string', 'in:' . implode(',', BillingChangeRequest::TYPES)],
            'billing_subscription_id' => ['nullable', 'integer'],
            'requested_plan_id'       => ['nullable', 'integer'],
            'customer_message'        => ['nullable', 'string', 'max:2000'],
            'reason'                  => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->changeRequests->submit($request->user(), $validated['request_type'], [
            'billing_subscription_id' => $validated['billing_subscription_id'] ?? null,
            'requested_plan_id'       => $validated['requested_plan_id'] ?? null,
            'customer_message'        => $validated['customer_message'] ?? null,
            'reason'                  => $validated['reason'] ?? null,
        ]);

        if (! $result->ok) {
            return redirect()->route('portal.billing.change-requests.create')
                ->withInput()
                ->with('error', $result->message);
        }

        return redirect()->route('portal.billing.change-requests.show', $result->changeRequest)
            ->with('success', $result->message);
    }

    public function show(Request $request, BillingChangeRequest $changeRequest): View
    {
        abort_unless($this->self->ownsChangeRequest($request->user(), $changeRequest), 404);

        $changeRequest->load(['subscription.plan', 'plan', 'requestedPlan']);

        return view('portal.billing.change-request-detail', ['changeRequest' => $changeRequest]);
    }

    public function cancel(Request $request, BillingChangeRequest $changeRequest): RedirectResponse
    {
        // Ownership is also enforced inside the service; abort early on a miss
        // so we never reveal another organization's record exists.
        abort_unless($this->self->ownsChangeRequest($request->user(), $changeRequest), 404);

        $result = $this->changeRequests->customerCancel($request->user(), $changeRequest);

        $back = redirect()->route('portal.billing.change-requests.show', $changeRequest);

        return $result->ok
            ? $back->with('success', 'Your request has been cancelled.')
            : $back->with('error', $result->message);
    }
}
