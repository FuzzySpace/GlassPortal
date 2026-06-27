<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Siona\SionaTenantProvisioningResult;
use App\Services\Siona\SionaTenantProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin-only (owner/admin) action that provisions a SIONA workspace for an
 * organization and links it. The route is additionally constrained to
 * owner/admin via stacked role middleware — staff/support cannot reach it.
 *
 * All orchestration, idempotency, and auditing live in the service; this
 * controller only translates the result into a redirect + flash message.
 */
class SionaProvisioningController extends Controller
{
    public function __construct(private SionaTenantProvisioningService $provisioning) {}

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $result = $this->provisioning->provisionForOrganization(
            $organization,
            $request->user(),
            [
                'ip'         => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ],
        );

        $back = redirect()->route('admin.customers.show', $organization->id);

        return match ($result->outcome) {
            SionaTenantProvisioningResult::OUTCOME_PROVISIONED
                => $back->with('success', "SIONA workspace provisioned and linked ({$result->workspaceId})."),
            SionaTenantProvisioningResult::OUTCOME_ALREADY_LINKED
                => $back->with('success', 'SIONA workspace is already provisioned for this organization.'),
            default
                => $back->with('error', $result->message),
        };
    }
}
