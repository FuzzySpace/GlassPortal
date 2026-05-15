<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\OrganizationModuleLink;
use App\Services\ModuleLaunchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ModuleLaunchController extends Controller
{
    public function __construct(private ModuleLaunchService $launcher) {}

    /**
     * Process a module launch attempt for the authenticated customer.
     *
     * Security: verifies the link belongs to the user's organization before
     * delegating to ModuleLaunchService (which also re-checks ownership).
     * Every attempt — allowed, denied, or stubbed — is recorded as a
     * ModuleLaunchEvent by the service.
     */
    public function launch(Request $request, OrganizationModuleLink $moduleLink): RedirectResponse|View
    {
        $user = Auth::user();

        // HTTP-layer ownership check: deny before the service even runs
        if ((int) $moduleLink->organization_id !== (int) $user->organization_id) {
            abort(403, 'You do not have access to this module link.');
        }

        $result = $this->launcher->attemptLaunch(
            $moduleLink,
            $user,
            $request->ip() ?? '',
            $request->userAgent() ?? '',
        );

        return match ($result['outcome']) {
            'allowed'  => redirect()->away($result['redirect_url']),
            'stubbed'  => view('portal.module-launch-stub', [
                'link'   => $moduleLink,
                'reason' => $result['reason'],
            ]),
            default    => redirect()->route('portal.modules')
                ->with('error', $result['reason'] ?? 'Launch unavailable.'),
        };
    }
}
