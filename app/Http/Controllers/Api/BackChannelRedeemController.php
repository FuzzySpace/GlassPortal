<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModuleLaunchEvent;
use App\Services\Sso\BackChannelLaunchCodeResult;
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
 * - Audit events never contain raw code, raw secret, email, or name.
 */
class BackChannelRedeemController extends Controller
{
    public function __construct(private BackChannelLaunchService $service) {}

    public function redeem(Request $request, string $moduleKey): JsonResponse
    {
        $code = (string) ($request->input('launch_code') ?? '');

        $result = $this->service->redeemCode($moduleKey, $code);

        $this->recordAudit($request, $moduleKey, $result);

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

    /**
     * Record an audit event for auditable redemption outcomes.
     *
     * Skipped for format errors (missing_code, malformed_code, code_not_found)
     * to avoid log flooding and timing oracles.
     *
     * Security: never includes raw code, raw secret, email, or name.
     */
    private function recordAudit(Request $request, string $moduleKey, BackChannelLaunchCodeResult $result): void
    {
        // Determine event type; skip non-auditable reasons
        if ($result->ok) {
            $eventType = 'backchannel_redeem_success';
        } elseif ($result->reason === 'code_replayed') {
            $eventType = 'backchannel_replay_blocked';
        } elseif (in_array($result->reason, [
            'wrong_module',
            'inactive_module_link',
            'organization_mismatch',
            'user_not_found',
            'mtls_required',
            'backchannel_disabled',
        ], true)) {
            $eventType = 'backchannel_redeem_failed';
        } else {
            // Non-auditable format error — skip to avoid flooding
            return;
        }

        try {
            ModuleLaunchEvent::create([
                'organization_id' => $result->orgId !== null ? (int) $result->orgId : null,
                'user_id'         => $result->userId !== null ? (int) $result->userId : null,
                'module_link_id'  => $result->moduleLinkId !== null ? (int) $result->moduleLinkId : null,
                'module_key'      => $moduleKey,
                'auth_mode'       => 'backchannel_launch',
                'event_type'      => $eventType,
                'reason'          => $result->ok ? null : $result->reason,
                'ip_address'      => $request->ip(),
                'user_agent'      => $request->userAgent(),
                'metadata'        => $result->ok ? ['expires_at' => $result->expiresAt] : null,
            ]);
        } catch (\Throwable) {
            // Audit failure must never break the response
        }
    }
}
