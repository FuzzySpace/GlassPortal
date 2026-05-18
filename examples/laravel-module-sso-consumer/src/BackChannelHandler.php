<?php

/**
 * EXAMPLE FILE — not a production controller.
 *
 * Shows how a module handles the back-channel launch flow.
 * The browser POSTs a one-time launch_code here; this handler calls
 * GlassPortal server-to-server to exchange it for identity data.
 *
 * Route registration (routes/web.php):
 *
 *   Route::post('/sso/backchannel', [BackChannelHandler::class, 'handle']);
 *
 * Security notes:
 * - Never log or echo the launch_code.
 * - Only call GlassPortal over a trusted network path (mTLS or VPN in prod).
 * - The redeem URL should be an internal service URL, not public internet.
 */

namespace App\Http\Controllers;

use GlassHouse\PortalAuth\DTO\BackChannelRedeemResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackChannelHandler extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        // The launch_code arrives from the browser via POST body — never from URL.
        $launchCode = (string) $request->input('launch_code', '');

        if ($launchCode === '') {
            return redirect()->route('login')->withErrors(['sso' => 'Missing launch code.']);
        }

        // Exchange code with GlassPortal server-to-server.
        // Never expose this URL or the launch_code to the browser.
        $redeemUrl = config('glassportal.backchannel_redeem_url');

        try {
            $response = Http::timeout(5)
                ->asForm()
                ->post($redeemUrl, ['launch_code' => $launchCode]);
        } catch (\Throwable $e) {
            Log::error('Back-channel SSO: GlassPortal unreachable', ['error' => $e->getMessage()]);
            return redirect()->route('login')->withErrors(['sso' => 'SSO service unavailable.']);
        }

        $data = $response->json() ?? [];

        if (! $response->successful() || ! ($data['ok'] ?? false)) {
            // Parse the error reason for logging. NEVER return it to the browser as-is.
            $result = BackChannelRedeemResult::fromErrorResponse($data);
            Log::warning('Back-channel SSO: redeem failed', [
                'reason' => $result->reason,
                // Do NOT log launch_code, user email, or name
            ]);
            return redirect()->route('login')->withErrors(['sso' => 'Authentication failed.']);
        }

        $result = BackChannelRedeemResult::fromResponse($data);

        // Create local session with authoritative identity data from portal.
        session([
            'portal_user_id' => $result->userId,
            'portal_org_id'  => $result->orgId,
            'portal_role'    => $result->role,
            'portal_expires' => $result->expiresAt,
        ]);

        // Audit log — jti is not available in back-channel redeem response
        // but userId + orgId + timestamp are sufficient for audit trail.
        Log::info('Back-channel SSO: session created', [
            'user_id' => $result->userId,
            'org_id'  => $result->orgId,
            // Do NOT log email, name, or the launch_code
        ]);

        return redirect()->route('dashboard');
    }
}
