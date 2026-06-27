<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BillingServiceEntitlement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Read-only customer view of their organization's service entitlements (Phase 25).
 *
 * Scoped strictly to the signed-in user's organization — a customer can never
 * see another organization's entitlements, and cannot mutate lifecycle state
 * (there are no write routes in the portal).
 */
class EntitlementsController extends Controller
{
    public function index(): View
    {
        $user  = Auth::user();
        $orgId = $user->organization_id;

        $entitlements = $orgId !== null
            ? BillingServiceEntitlement::forOrganization($orgId)
                ->whereIn('status', [
                    BillingServiceEntitlement::STATUS_ACTIVE,
                    BillingServiceEntitlement::STATUS_PENDING,
                    BillingServiceEntitlement::STATUS_SUSPENDED,
                ])
                ->orderByDesc('created_at')
                ->get()
            : new Collection();

        return view('portal.entitlements', [
            'entitlements' => $entitlements,
            'hasOrg'       => $orgId !== null,
        ]);
    }
}
