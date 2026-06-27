<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingServiceEntitlement;
use App\Services\Billing\BillingEntitlementResult;
use App\Services\Billing\BillingEntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin entitlement visibility + controlled lifecycle actions (Phase 25).
 *
 * Owner/admin only (stacked `role:owner,admin` on the billing route group).
 * Lifecycle actions delegate to BillingEntitlementService, which enforces the
 * allowed-transition map and records an event. No infrastructure is touched.
 */
class EntitlementController extends Controller
{
    public function __construct(private BillingEntitlementService $entitlements) {}

    public function index(): View
    {
        return view('admin.billing.entitlements', [
            'entitlements' => BillingServiceEntitlement::with(['customer', 'plan', 'product'])
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }

    public function show(BillingServiceEntitlement $entitlement): View
    {
        $entitlement->load(['customer', 'subscription', 'product', 'plan', 'organization', 'user', 'events']);

        return view('admin.billing.entitlement-detail', ['entitlement' => $entitlement]);
    }

    public function action(Request $request, BillingServiceEntitlement $entitlement, string $action): RedirectResponse
    {
        $reason = ($request->input('reason') ?: null);
        $actor  = $request->user();

        $result = match ($action) {
            'suspend'              => $this->entitlements->suspend($entitlement, $reason, $actor),
            'reactivate'           => $this->entitlements->reactivate($entitlement, $reason, $actor),
            'cancel'               => $this->entitlements->cancel($entitlement, $reason, $actor),
            'terminate'            => $this->entitlements->terminate($entitlement, $reason, $actor),
            'provisioning-pending' => $this->entitlements->markProvisioningPending($entitlement, $reason, $actor),
            'provisioning-failed'  => $this->entitlements->markProvisioningFailed($entitlement, $reason, $actor),
            default                => BillingEntitlementResult::failed('Unknown action.'),
        };

        $back = redirect()->route('admin.billing.entitlements.show', $entitlement);

        return $result->ok
            ? $back->with('success', $result->message)
            : $back->with('error', $result->message);
    }
}
