<?php

namespace App\Http\Controllers\Dev;

use App\Data\Sso\VerifiedLaunchContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Local/testing SSO consumer endpoint.
 *
 * Simulates a downstream module receiving a signed launch POST. The
 * signed.launch middleware verifies the token before this controller runs,
 * attaching a VerifiedLaunchContext to request attributes under "signed_launch".
 *
 * Security:
 * - Only registered under local/testing or GLASSPORTAL_ENABLE_DEV_SSO_CONSUME=true.
 * - Raw token and signing secret are never returned — only safe identity fields.
 * - Replay protection is enforced by the middleware (JTI consumed on first use).
 */
class SsoConsumeController extends Controller
{
    public function consume(Request $request, string $moduleKey): JsonResponse
    {
        /** @var VerifiedLaunchContext $context */
        $context = $request->attributes->get('signed_launch');

        return response()->json([
            'ok'              => true,
            'module_key'      => $context->audience,
            'organization_id' => $context->orgId,
            'user_id'         => $context->userId,
            'user_email'      => $context->email,
            'user_name'       => $context->name,
            'role'            => $context->role,
            'jti'             => $context->jti,
            'expires_at'      => $context->expiresAt,
        ]);
    }
}
