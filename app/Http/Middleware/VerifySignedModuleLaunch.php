<?php

namespace App\Http\Middleware;

use App\Services\Sso\SignedLaunchVerifierService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a signed launch token posted to a module endpoint.
 *
 * Token field resolution (in order):
 *   1. POST body field "launch_token" (Phase 9 standard)
 *   2. POST body field "slt" (Phase 8 backward compatibility)
 *
 * Module key resolution (in order):
 *   1. Route parameter "moduleKey" (parameterized routes)
 *   2. Middleware argument, e.g. ->middleware('signed.launch:glasspanel')
 *
 * On success:
 *   Attaches VerifiedLaunchContext to request attributes under "signed_launch".
 *   Also attaches under "sso_context" for Phase 8 backward compatibility.
 *
 * On failure:
 *   401 — missing or invalid token (bad signature, expired, replayed, malformed)
 *   403 — audience mismatch (valid token but wrong module)
 *   500 — module key not resolvable (configuration error)
 *
 * Security: the raw token is never logged, stored, or echoed in any response.
 */
class VerifySignedModuleLaunch
{
    public function __construct(private SignedLaunchVerifierService $verifier) {}

    public function handle(Request $request, Closure $next, string $moduleKey = ''): Response
    {
        // Token: prefer launch_token (Phase 9), fall back to slt (Phase 8)
        $token = (string) $request->input('launch_token', '');
        if ($token === '') {
            $token = (string) $request->input('slt', '');
        }

        if ($token === '') {
            return response()->json(['error' => 'Missing signed launch token.'], 401);
        }

        // Module key: prefer route parameter, fall back to middleware argument
        $resolvedKey = (string) ($request->route('moduleKey') ?? '');
        if ($resolvedKey === '') {
            $resolvedKey = $moduleKey;
        }

        if ($resolvedKey === '') {
            return response()->json(['error' => 'Module key not configured.'], 500);
        }

        try {
            $context = $this->verifier->verify($token, $resolvedKey);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'audience')) {
                return response()->json(['error' => 'Token audience mismatch.'], 403);
            }
            return response()->json(['error' => 'Token verification failed.'], 401);
        }

        $request->attributes->set('signed_launch', $context);
        $request->attributes->set('sso_context', $context); // Phase 8 compat

        return $next($request);
    }
}
