<?php

namespace GlassHouse\PortalAuth\DTO;

/**
 * Result of a back-channel launch code redemption response from GlassPortal.
 *
 * Modules use this after calling the /api/sso/backchannel/redeem/{moduleKey}
 * endpoint and parsing the JSON response.
 *
 * Security: the raw launch code must never be included in this object.
 * Populate only from the already-decoded JSON response body.
 */
readonly class BackChannelRedeemResult
{
    private function __construct(
        public bool    $ok,
        public string  $reason,
        public ?string $moduleKey,
        public ?string $userId,
        public ?string $orgId,
        public ?string $email,
        public ?string $name,
        public ?string $role,
        public ?int    $expiresAt,
    ) {}

    /**
     * Build from a successful GlassPortal redemption response payload.
     *
     * @param array $data  Decoded JSON body of a successful 200 response.
     */
    public static function fromResponse(array $data): self
    {
        return new self(
            ok:        (bool) ($data['ok'] ?? false),
            reason:    'ok',
            moduleKey: isset($data['module_key']) ? (string) $data['module_key'] : null,
            userId:    isset($data['user_id'])    ? (string) $data['user_id']    : null,
            orgId:     isset($data['org_id'])     ? (string) $data['org_id']     : null,
            email:     isset($data['email'])      ? (string) $data['email']      : null,
            name:      isset($data['name'])       ? (string) $data['name']       : null,
            role:      isset($data['role'])       ? (string) $data['role']       : null,
            expiresAt: isset($data['expires_at']) ? (int)    $data['expires_at'] : null,
        );
    }

    /**
     * Build from a failed GlassPortal redemption response payload.
     *
     * @param array $data  Decoded JSON body of a 401/403 response.
     */
    public static function fromErrorResponse(array $data): self
    {
        return new self(
            ok:        false,
            reason:    (string) ($data['reason'] ?? 'unknown'),
            moduleKey: null,
            userId:    null,
            orgId:     null,
            email:     null,
            name:      null,
            role:      null,
            expiresAt: null,
        );
    }

    public static function failure(string $reason): self
    {
        return new self(
            ok:        false,
            reason:    $reason,
            moduleKey: null,
            userId:    null,
            orgId:     null,
            email:     null,
            name:      null,
            role:      null,
            expiresAt: null,
        );
    }
}
