<?php

namespace GlassHouse\PortalAuth\DTO;

/**
 * Typed value object returned on successful signed launch token verification.
 *
 * Contains all identity claims from the verified SLP token.
 * No signing secrets, raw token bytes, or HMAC keys are ever included.
 */
readonly class VerifiedLaunchContext
{
    public function __construct(
        public string $issuer,
        public string $audience,
        public string $userId,
        public string $orgId,
        public string $moduleLinkId,
        public string $email,
        public string $name,
        public string $role,
        public int    $issuedAt,
        public int    $expiresAt,
        public string $nonce,
        public string $jti,
        /** Full decoded payload — never includes signing secret. */
        public array  $rawClaims = [],
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            issuer:       (string) ($payload['iss'] ?? ''),
            audience:     (string) ($payload['aud'] ?? ''),
            userId:       (string) ($payload['sub'] ?? ''),
            orgId:        (string) ($payload['org'] ?? ''),
            moduleLinkId: (string) ($payload['mid'] ?? ''),
            email:        (string) ($payload['email'] ?? ''),
            name:         (string) ($payload['name'] ?? ''),
            role:         (string) ($payload['role'] ?? ''),
            issuedAt:     (int)    ($payload['iat'] ?? 0),
            expiresAt:    (int)    ($payload['exp'] ?? 0),
            nonce:        (string) ($payload['nonce'] ?? ''),
            jti:          (string) ($payload['jti'] ?? ''),
            rawClaims:    $payload,
        );
    }

    public function toArray(): array
    {
        return [
            'iss'   => $this->issuer,
            'aud'   => $this->audience,
            'sub'   => $this->userId,
            'org'   => $this->orgId,
            'mid'   => $this->moduleLinkId,
            'email' => $this->email,
            'name'  => $this->name,
            'role'  => $this->role,
            'iat'   => $this->issuedAt,
            'exp'   => $this->expiresAt,
            'nonce' => $this->nonce,
            'jti'   => $this->jti,
        ];
    }
}
