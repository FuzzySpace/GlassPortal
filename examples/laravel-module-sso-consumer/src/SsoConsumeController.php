<?php

/**
 * EXAMPLE FILE — not a production controller.
 *
 * Shows how a module handles the signed launch SSO flow using
 * glasshouse/portal-auth. The middleware (registered below) does all
 * the cryptographic verification before this method is called.
 *
 * Route registration (routes/web.php):
 *
 *   Route::post('/sso/consume', [SsoConsumeController::class, 'handle'])
 *       ->middleware('portal.signed-launch:glasspanel');
 *
 * Middleware registration (bootstrap/app.php):
 *
 *   $middleware->alias([
 *       'portal.signed-launch' => \GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch::class,
 *   ]);
 */

namespace App\Http\Controllers;

use GlassHouse\PortalAuth\DTO\VerifiedLaunchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoConsumeController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        // The middleware has already verified the token.
        // $ctx contains identity claims — never trust raw POST data for identity.
        /** @var VerifiedLaunchContext $ctx */
        $ctx = $request->attributes->get('signed_launch');

        // Create a local session — scoped to this module only.
        session([
            'portal_user_id'  => $ctx->userId,      // authoritative from portal
            'portal_org_id'   => $ctx->orgId,        // authoritative from portal
            'portal_role'     => $ctx->role,
            'portal_jti'      => $ctx->jti,          // safe to store for audit purposes
            'portal_expires'  => $ctx->expiresAt,
        ]);

        // Never store: $ctx->email, $ctx->name in the session (PII in session store)
        // Use only what your module needs. Log jti + userId for audit, not email/name.

        return redirect()->route('dashboard');
    }
}
