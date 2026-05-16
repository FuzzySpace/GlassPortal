<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\OrganizationModuleLink;
use App\Services\ModuleLaunchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class ModuleLaunchController extends Controller
{
    public function __construct(private ModuleLaunchService $launcher) {}

    /**
     * Process a module launch attempt for the authenticated customer.
     *
     * Security:
     * - HTTP-layer org ownership check fires first (403 before any service call).
     * - Service re-checks ownership as defense in depth.
     * - Every outcome (allowed, denied, stubbed, signed_launch_issued, failed)
     *   is recorded as a ModuleLaunchEvent.
     * - For signed_launch: the POST handoff view receives the token so it can
     *   be submitted via form. The signing secret never appears in any response.
     */
    public function launch(Request $request, OrganizationModuleLink $moduleLink): RedirectResponse|View
    {
        $user = Auth::user();

        // HTTP-layer ownership check: hard 403 before service runs
        if ((int) $moduleLink->organization_id !== (int) $user->organization_id) {
            abort(403, 'You do not have access to this module link.');
        }

        // Rate limit: max N launches per user per link per minute
        $rateLimitKey = 'module-launch:' . $user->id . ':' . $moduleLink->id;
        $maxAttempts  = (int) config('glasshouse_sso.rate_limit_per_minute', 20);

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $this->launcher->recordRateLimited(
                $moduleLink,
                $user,
                $request->ip() ?? '',
                $request->userAgent() ?? '',
            );
            return redirect()->route('portal.modules')
                ->with('error', 'Too many launch attempts. Please wait a moment before trying again.');
        }

        RateLimiter::hit($rateLimitKey, 60);

        $result = $this->launcher->attemptLaunch(
            $moduleLink,
            $user,
            $request->ip() ?? '',
            $request->userAgent() ?? '',
        );

        $outcome = $result['outcome'];

        if ($outcome === 'allowed') {
            return redirect()->away($result['redirect_url']);
        }

        if ($outcome === 'signed_launch') {
            // POST-form handoff: token in form body, not in URL
            return view('portal.module-launch-handoff', [
                'link'                => $moduleLink,
                'launchUrl'           => $result['redirect_url'],
                'expiresAt'           => $result['expires_at'],
                '_token_for_handoff'  => $result['token'],
            ]);
        }

        if ($outcome === 'backchannel_launch') {
            // POST-form handoff: launch_code in form body, never in URL
            return view('portal.module-launch-backchannel-handoff', [
                'link'         => $moduleLink,
                'launchUrl'    => $result['redirect_url'],
                'expiresAt'    => $result['expires_at'],
                '_launch_code' => $result['launch_code'],
            ]);
        }

        if ($outcome === 'stubbed') {
            return view('portal.module-launch-stub', [
                'link'   => $moduleLink,
                'reason' => $result['reason'],
            ]);
        }

        // denied or failed
        return redirect()->route('portal.modules')
            ->with('error', $result['reason'] ?? 'Launch unavailable.');
    }
}
