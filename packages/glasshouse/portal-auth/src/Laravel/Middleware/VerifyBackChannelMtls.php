<?php

namespace GlassHouse\PortalAuth\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces mTLS client-certificate verification for back-channel SSO endpoints.
 *
 * Reads from glasshouse_sso.backchannel config. When require_mtls is false
 * (default), this middleware is a no-op — safe for dev/staging.
 *
 * In production: set GLASSPORTAL_BACKCHANNEL_REQUIRE_MTLS=true and configure
 * your reverse proxy to forward the certificate verification result header.
 */
class VerifyBackChannelMtls
{
    public function handle(Request $request, Closure $next): Response
    {
        $cfg = (array) config('glasshouse_sso.backchannel', []);

        if (! (bool) ($cfg['require_mtls'] ?? false)) {
            return $next($request);
        }

        $header   = (string) ($cfg['mtls_verified_header'] ?? 'X-Client-Cert-Verified');
        $expected = (string) ($cfg['mtls_verified_value']  ?? 'SUCCESS');

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
