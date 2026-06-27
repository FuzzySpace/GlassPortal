<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ProvisioningRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Read-only customer view of their organization's provisioning requests (Phase 26).
 *
 * Strictly org-scoped — a customer can never see another organization's
 * requests, cannot mutate request state (no portal write routes), and never
 * sees payload/result/metadata (the view renders status fields only).
 */
class ProvisioningController extends Controller
{
    public function index(): View
    {
        $user  = Auth::user();
        $orgId = $user->organization_id;

        $requests = $orgId !== null
            ? ProvisioningRequest::forOrganization($orgId)->orderByDesc('created_at')->get()
            : new Collection();

        return view('portal.provisioning', [
            'requests' => $requests,
            'hasOrg'   => $orgId !== null,
        ]);
    }
}
