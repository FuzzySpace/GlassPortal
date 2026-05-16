<?php

namespace App\Services\Sso;

/**
 * Result of a back-channel launch code issuance or redemption.
 *
 * On success (ok=true):
 *   - issue: code is populated; user fields are null (caller persists nothing beyond the code)
 *   - redeem: user fields are populated; code is null (consumed — never returned again)
 *
 * On failure (ok=false):
 *   - All nullable fields are null; reason indicates the failure code.
 *
 * Security: the raw launch code must never be logged, stored in the DB, or echoed
 * back to the browser. The code field is present only in the issue result so the
 * handoff view can embed it in a POST form body.
 */
readonly class BackChannelLaunchCodeResult
{
    private function __construct(
        public bool $ok,
        public string $reason,

        // Populated on issue success only
        public ?string $code,
        public ?int $expiresAt,

        // Populated on redeem success only
        public ?string $moduleKey,
        public ?string $userId,
        public ?string $orgId,
        public ?string $moduleLinkId,
        public ?string $email,
        public ?string $name,
        public ?string $role,
    ) {}

    public static function issued(string $code, int $expiresAt): self
    {
        return new self(
            ok: true,
            reason: 'ok',
            code: $code,
            expiresAt: $expiresAt,
            moduleKey: null,
            userId: null,
            orgId: null,
            moduleLinkId: null,
            email: null,
            name: null,
            role: null,
        );
    }

    public static function redeemed(array $payload): self
    {
        return new self(
            ok: true,
            reason: 'ok',
            code: null,
            expiresAt: $payload['expires_at'] ?? null,
            moduleKey: $payload['module_key'] ?? null,
            userId: $payload['user_id'] ?? null,
            orgId: $payload['org_id'] ?? null,
            moduleLinkId: $payload['module_link_id'] ?? null,
            email: $payload['email'] ?? null,
            name: $payload['name'] ?? null,
            role: $payload['role'] ?? null,
        );
    }

    public static function failure(string $reason): self
    {
        return new self(
            ok: false,
            reason: $reason,
            code: null,
            expiresAt: null,
            moduleKey: null,
            userId: null,
            orgId: null,
            moduleLinkId: null,
            email: null,
            name: null,
            role: null,
        );
    }
}
