<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces mTLS client-certificate verification for back-channel SSO endpoints.
 *
 * When glasshouse_sso.backchannel.require_mtls is true, this middleware checks
 * that the reverse proxy has verified the client certificate and forwarded the
 * configured header with the expected value (default: X-Client-Cert-Verified: SUCCESS).
 *
 * When require_mtls is false (default), the middleware is a no-op.
 *
 * Phase 12 — SSO trust hardening.
 */
class VerifyBackChannelMtls
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('glasshouse_sso.backchannel.require_mtls', false)) {
            return $next($request);
        }

        $header   = (string) config('glasshouse_sso.backchannel.mtls_verified_header', 'X-Client-Cert-Verified');
        $expected = (string) config('glasshouse_sso.backchannel.mtls_verified_value', 'SUCCESS');

        if ($request->header($header, '') !== $expected) {
            return response()->json([
                'ok'     => false,
                'error'  => 'mTLS client certificate verification required.',
                'reason' => 'mtls_required',
            ], 401);
        }

        return $next($request);
    }
}
