<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingChangeRequest;
use App\Services\Billing\BillingChangeRequestResult;
use App\Services\Billing\BillingChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin visibility + workflow for customer billing change requests (Phase 28).
 *
 * Owner/admin only (stacked `role:owner,admin` on the billing route group).
 * Workflow transitions delegate to BillingChangeRequestService, which enforces
 * the allowed-transition map. These are workflow records only: NO Stripe
 * mutation, NO subscription/entitlement/provisioning/infrastructure mutation.
 */
class ChangeRequestController extends Controller
{
    public function __construct(private BillingChangeRequestService $changeRequests) {}

    public function index(): View
    {
        return view('admin.billing.change-requests', [
            'requests' => BillingChangeRequest::with(['organization', 'user', 'subscription.plan', 'requestedPlan'])
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }

    public function show(BillingChangeRequest $changeRequest): View
    {
        $changeRequest->load([
            'organization', 'user', 'subscription.plan', 'plan', 'requestedPlan', 'reviewedBy',
        ]);

        return view('admin.billing.change-request-detail', ['changeRequest' => $changeRequest]);
    }

    public function action(Request $request, BillingChangeRequest $changeRequest, string $action): RedirectResponse
    {
        $notes = $request->input('admin_notes') ?: null;
        $actor = $request->user();

        $result = match ($action) {
            'under-review' => $this->changeRequests->markUnderReview($changeRequest, $actor, $notes),
            'approve'      => $this->changeRequests->approve($changeRequest, $actor, $notes),
            'reject'       => $this->changeRequests->reject($changeRequest, $actor, $notes),
            'complete'     => $this->changeRequests->complete($changeRequest, $actor, $notes),
            'cancel'       => $this->changeRequests->cancel($changeRequest, $actor, $notes),
            default        => BillingChangeRequestResult::failed('Unknown action.'),
        };

        $back = redirect()->route('admin.billing.change-requests.show', $changeRequest);

        return $result->ok
            ? $back->with('success', $result->message)
            : $back->with('error', $result->message);
    }
}
