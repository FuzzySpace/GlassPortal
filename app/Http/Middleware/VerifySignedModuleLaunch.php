<?php

namespace App\Http\Middleware;

use App\Services\Sso\ModuleSignedLaunchVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a signed launch token posted to a module endpoint.
 *
 * Token field resolution — POST body only, checked in order:
 *   1. signed_launch_token  (Phase 10 canonical name)
 *   2. launch_token         (Phase 9 alias)
 *   3. slt                  (Phase 8 backward compat)
 *
 * Tokens in URL query strings are explicitly rejected (they appear in server logs).
 *
 * Module key resolution — in order:
 *   1. Route parameter named "moduleKey" or "module"
 *   2. Middleware argument: ->middleware('signed.launch:glasspanel')
 *
 * On success:
 *   Attaches VerifiedLaunchContext to request attributes under "signed_launch".
 *   Also attaches under "sso_context" for Phase 8/9 backward compatibility.
 *
 * HTTP status codes:
 *   400 — token submitted in URL query string (security violation)
 *   401 — missing, malformed, invalid, expired, replayed, or no secret configured
 *   403 — token is valid but audience does not match this module
 *   500 — module key is not resolvable (configuration error)
 *
 * Security: the raw token string is never logged, stored in DB, or echoed back.
 */
class VerifySignedModuleLaunch
{
    private const TOKEN_FIELDS = ['signed_launch_token', 'launch_token', 'slt'];

    public function __construct(private ModuleSignedLaunchVerifier $verifier) {}

    public function handle(Request $request, Closure $next, string $moduleKey = ''): Response
    {
        // Read token from POST body only — $request->post() never reads query string.
        $token = '';
        foreach (self::TOKEN_FIELDS as $field) {
            $value = (string) ($request->post($field) ?? '');
            if ($value !== '') {
                $token = $value;
                break;
            }
        }

        // Explicitly detect and reject tokens that arrive via URL query string.
        if ($token === '') {
            foreach (self::TOKEN_FIELDS as $field) {
                if ((string) $request->query($field, '') !== '') {
                    return $this->errorResponse(
                        $request,
                        'Token must be submitted in POST body, not URL — tokens in URLs appear in server logs.',
                        'query_string_token',
                        400
                    );
                }
            }
            return $this->errorResponse($request, 'Missing signed launch token.', 'missing_token', 401);
        }

        // Resolve module key: route param > middleware argument.
        $resolvedKey = (string) ($request->route('moduleKey') ?? $request->route('module') ?? '');
        if ($resolvedKey === '') {
            $resolvedKey = $moduleKey;
        }

        if ($resolvedKey === '') {
            return $this->errorResponse($request, 'Module key not configured.', 'config_error', 500);
        }

        $result = $this->verifier->verify($token, $resolvedKey);

        if (! $result->ok) {
            $status = $result->reason === 'wrong_audience' ? 403 : 401;
            return $this->errorResponse($request, 'Token verification failed.', $result->reason, $status);
        }

        $request->attributes->set('signed_launch', $result->safeContext);
        $request->attributes->set('sso_context', $result->safeContext); // Phase 8/9 compat

        return $next($request);
    }

    private function errorResponse(Request $request, string $message, string $reason, int $status): Response
    {
        // Always JSON for API/dev routes. Web modules can override this method or
        // use ModuleSignedLaunchVerifier directly for custom redirect logic.
        $body = ['error' => $message, 'reason' => $reason];
        return response()->json($body, $status);
    }
}
