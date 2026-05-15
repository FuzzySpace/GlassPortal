<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Sso\SignedLaunchVerifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Local/testing SSO consumer endpoint.
 *
 * Simulates a downstream module receiving a signed launch POST. Verifies the
 * SLP token and returns the verified identity claims as JSON so developers can
 * inspect what a real module would receive.
 *
 * Security:
 * - Only registered under APP_ENV=local or APP_ENV=testing (see routes/web.php).
 * - Never returns the raw token or signing secret.
 * - Returns 401 on any token failure so integration tests can assert the error path.
 * - Consumes the JTI on successful verification — a second call with the same token
 *   will return 401 (replay detected), exactly as a real module would behave.
 */
class SsoConsumeController extends Controller
{
    public function __construct(private SignedLaunchVerifierService $verifier) {}

    public function consume(Request $request, string $moduleKey): JsonResponse
    {
        $token = (string) $request->input('slt', '');

        if ($token === '') {
            return response()->json(['error' => 'Missing slt parameter.'], 422);
        }

        try {
            $context = $this->verifier->verify($token, $moduleKey);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error'  => 'Token verification failed.',
                'detail' => $e->getMessage(),
            ], 401);
        }

        return response()->json([
            'verified' => true,
            'context'  => $context->toArray(),
        ]);
    }
}
