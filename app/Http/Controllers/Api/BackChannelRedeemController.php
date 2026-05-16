<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sso\BackChannelLaunchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles server-to-server back-channel launch code redemption.
 *
 * POST /api/sso/backchannel/redeem/{moduleKey}
 *
 * The module sends the one-time launch code (received from the browser via
 * POST form body) and GlassPortal exchanges it for identity data.
 *
 * Security:
 * - The raw code is never logged or stored beyond the duration of this request.
 * - The response contains identity data only — no signing secrets or raw codes.
 * - Rate-limited to prevent brute-force attempts.
 */
class BackChannelRedeemController extends Controller
{
    public function __construct(private BackChannelLaunchService $service) {}

    public function redeem(Request $request, string $moduleKey): JsonResponse
    {
        $code = (string) ($request->input('launch_code') ?? '');

        $result = $this->service->redeemCode($moduleKey, $code);

        if (! $result->ok) {
            $status = match ($result->reason) {
                'wrong_module'          => 403,
                'inactive_module_link'  => 403,
                'organization_mismatch' => 403,
                default                 => 401,
            };

            return response()->json([
                'ok'     => false,
                'error'  => 'Code redemption failed.',
                'reason' => $result->reason,
            ], $status);
        }

        return response()->json([
            'ok'          => true,
            'module_key'  => $result->moduleKey,
            'user_id'     => $result->userId,
            'org_id'      => $result->orgId,
            'email'       => $result->email,
            'name'        => $result->name,
            'role'        => $result->role,
            'expires_at'  => $result->expiresAt,
        ]);
    }
}
