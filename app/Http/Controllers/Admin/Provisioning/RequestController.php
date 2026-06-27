<?php

namespace App\Http\Controllers\Admin\Provisioning;

use App\Http\Controllers\Controller;
use App\Models\ProvisioningRequest;
use App\Services\Provisioning\ProvisioningRequestResult;
use App\Services\Provisioning\ProvisioningRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin provisioning request visibility + controlled lifecycle actions (Phase 26).
 *
 * Owner/admin only (stacked `role:owner,admin` on the route group). Actions
 * delegate to ProvisioningRequestService, which enforces the allowed-transition
 * map and records events. No infrastructure is executed here — completing a
 * request only updates request + billing entitlement state.
 */
class RequestController extends Controller
{
    public function __construct(private ProvisioningRequestService $requests) {}

    public function index(): View
    {
        return view('admin.provisioning.requests', [
            'requests' => ProvisioningRequest::with(['customer', 'organization', 'entitlement'])
                ->orderByDesc('created_at')
                ->paginate(25),
            'drivers'  => array_keys((array) config('provisioning.drivers', [])),
        ]);
    }

    public function show(ProvisioningRequest $provisioningRequest): View
    {
        $provisioningRequest->load(['customer', 'organization', 'user', 'entitlement', 'approvedBy', 'rejectedBy', 'assignedTo', 'events']);

        return view('admin.provisioning.request-detail', ['request' => $provisioningRequest]);
    }

    public function action(Request $request, ProvisioningRequest $provisioningRequest, string $action): RedirectResponse
    {
        $reason = ($request->input('reason') ?: null);
        $actor  = $request->user();

        $result = match ($action) {
            'approve'  => $this->requests->approve($provisioningRequest, $actor, $reason),
            'reject'   => $this->requests->reject($provisioningRequest, $actor, $reason),
            'queue'    => $this->requests->queue($provisioningRequest, $reason, $actor),
            'start'    => $this->requests->start($provisioningRequest, $reason, $actor),
            'complete' => $this->requests->complete($provisioningRequest, [], $reason, $actor),
            'fail'     => $this->requests->fail($provisioningRequest, $reason, $actor),
            'cancel'   => $this->requests->cancel($provisioningRequest, $reason, $actor),
            default    => ProvisioningRequestResult::failed('Unknown action.'),
        };

        $back = redirect()->route('admin.provisioning.requests.show', $provisioningRequest);

        return $result->ok
            ? $back->with('success', $result->message)
            : $back->with('error', $result->message);
    }
}
