<?php

namespace GlassHouse\PortalAuth\Laravel\Middleware;

use Closure;
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel middleware that verifies a signed launch token posted to a module endpoint.
 *
 * Token field resolution — POST body only, checked in order:
 *   1. signed_launch_token  (canonical)
 *   2. launch_token         (alias)
 *   3. slt                  (backward compat)
 *
 * Tokens in URL query strings are explicitly rejected (they appear in server logs).
 *
 * Module key resolution — in order:
 *   1. Route parameter "moduleKey" or "module"
 *   2. Middleware argument: ->middleware('portal.signed-launch:glasspanel')
 *
 * On success, attaches VerifiedLaunchContext to request attributes under "signed_launch".
 *
 * HTTP status codes:
 *   400 — token submitted via query string (security violation)
 *   401 — missing, invalid, expired, replayed, or no secret configured
 *   403 — wrong audience
 *   500 — module key not resolvable
 *
 * Security: the raw token string is never logged, stored, or echoed back.
 */
class VerifySignedModuleLaunch
{
    private const TOKEN_FIELDS = ['signed_launch_token', 'launch_token', 'slt'];

    public function __construct(private readonly SignedLaunchVerifier $verifier) {}

    public function handle(Request $request, Closure $next, string $moduleKey = ''): Response
    {
        // POST body only — $request->post() never reads query string.
        $token = '';
        foreach (self::TOKEN_FIELDS as $field) {
            $value = (string) ($request->post($field) ?? '');
            if ($value !== '') {
                $token = $value;
                break;
            }
        }

        // Explicitly detect and reject query-string tokens.
        if ($token === '') {
            foreach (self::TOKEN_FIELDS as $field) {
                if ((string) $request->query($field, '') !== '') {
                    return response()->json([
                        'error'  => 'Token must be submitted in POST body, not URL — tokens in URLs appear in server logs.',
                        'reason' => 'query_string_token',
                    ], 400);
                }
            }
            return response()->json(['error' => 'Missing signed launch token.', 'reason' => 'missing_token'], 401);
        }

        // Resolve module key: route param > middleware argument.
        $resolvedKey = (string) ($request->route('moduleKey') ?? $request->route('module') ?? '');
        if ($resolvedKey === '') {
            $resolvedKey = $moduleKey;
        }

        if ($resolvedKey === '') {
            return response()->json(['error' => 'Module key not configured.', 'reason' => 'config_error'], 500);
        }

        $result = $this->verifier->verify($token, $resolvedKey);

        if (! $result->ok) {
            $status = $result->reason === 'wrong_audience' ? 403 : 401;
            return response()->json(['error' => 'Token verification failed.', 'reason' => $result->reason], $status);
        }

        $request->attributes->set('signed_launch', $result->context);

        return $next($request);
    }
}
