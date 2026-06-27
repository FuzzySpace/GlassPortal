<?php

namespace App\Services\Siona;

/**
 * Normalized result returned by SionaTenantProvisioningService.
 *
 * ok           — true when the org ends up provisioned + linked (or already was)
 * outcome      — provisioned | already_linked | unconfigured | failed
 * workspaceId  — the SIONA workspace/tenant id, when known
 * moduleLinkId — id of the organization_module_link, when one exists
 * message      — human-safe summary (never contains credentials)
 *
 * No tokens or secrets are ever carried in this object.
 */
final readonly class SionaTenantProvisioningResult
{
    public const OUTCOME_PROVISIONED   = 'provisioned';
    public const OUTCOME_ALREADY_LINKED = 'already_linked';
    public const OUTCOME_UNCONFIGURED  = 'unconfigured';
    public const OUTCOME_FAILED        = 'failed';

    public function __construct(
        public bool    $ok,
        public string  $outcome,
        public ?string $workspaceId  = null,
        public ?int    $moduleLinkId = null,
        public string  $message      = '',
    ) {}

    public static function provisioned(string $workspaceId, int $moduleLinkId, string $message): self
    {
        return new self(true, self::OUTCOME_PROVISIONED, $workspaceId, $moduleLinkId, $message);
    }

    public static function alreadyLinked(?string $workspaceId, ?int $moduleLinkId, string $message): self
    {
        return new self(true, self::OUTCOME_ALREADY_LINKED, $workspaceId, $moduleLinkId, $message);
    }

    public static function unconfigured(string $message): self
    {
        return new self(false, self::OUTCOME_UNCONFIGURED, null, null, $message);
    }

    public static function failed(string $message, ?string $workspaceId = null, ?int $moduleLinkId = null): self
    {
        return new self(false, self::OUTCOME_FAILED, $workspaceId, $moduleLinkId, $message);
    }

    public function isSuccessful(): bool
    {
        return $this->ok;
    }
}
