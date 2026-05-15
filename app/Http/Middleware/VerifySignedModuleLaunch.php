<?php

namespace App\Http\Middleware;

use App\Services\Sso\SignedLaunchVerifierService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for use by downstream modules receiving a signed launch POST.
 *
 * Usage in a module's routes file:
 *   Route::post('/sso/receive', SomeController::class)
 *       ->middleware('verify.signed.launch:module_key');
 *
 * On success: attaches a VerifiedLaunchContext to the request attributes
 *   under the key 'sso_context'. The next handler can retrieve it with:
 *   $context = $request->attributes->get('sso_context');
 *
 * On failure: returns 401 JSON — never 302, to avoid leaking destination URLs.
 *
 * Security: the signed token in $_POST['slt'] is consumed on verify().
 * A second request with the same token receives 401 (replay detected).
 * The signing secret never appears in any response.
 */
class VerifySignedModuleLaunch
{
    public function __construct(private SignedLaunchVerifierService $verifier) {}

    public function handle(Request $request, Closure $next, string $moduleKey = ''): Response
    {
        $token = (string) $request->input('slt', '');

        if ($token === '') {
            return response()->json(['error' => 'Missing signed launch token (slt).'], 401);
        }

        if ($moduleKey === '') {
            return response()->json(['error' => 'Module key not configured in middleware.'], 500);
        }

        try {
            $context = $this->verifier->verify($token, $moduleKey);
            $request->attributes->set('sso_context', $context);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error'  => 'Token verification failed.',
                'detail' => $e->getMessage(),
            ], 401);
        }

        return $next($request);
    }
}
