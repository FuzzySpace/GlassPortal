<?php

namespace App\Services\Billing;

use App\Models\BillingServiceEntitlement;

/**
 * Normalized result returned by BillingEntitlementService (Phase 25).
 *
 * Carries no secrets. `status` is the operation outcome (not the entitlement
 * status — that is in newStatus / the entitlement model).
 */
final readonly class BillingEntitlementResult
{
    public const OUTCOME_CREATED            = 'created';
    public const OUTCOME_ALREADY_EXISTS     = 'already_exists';
    public const OUTCOME_TRANSITIONED       = 'transitioned';
    public const OUTCOME_UNCHANGED          = 'unchanged';
    public const OUTCOME_INVALID_TRANSITION = 'invalid_transition';
    public const OUTCOME_FAILED             = 'failed';

    public function __construct(
        public bool $ok,
        public string $status,
        public string $message = '',
        public ?BillingServiceEntitlement $entitlement = null,
        public ?string $previousStatus = null,
        public ?string $newStatus = null,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}

    public static function created(BillingServiceEntitlement $entitlement, string $message): self
    {
        return new self(true, self::OUTCOME_CREATED, $message, $entitlement, null, $entitlement->status);
    }

    public static function alreadyExists(BillingServiceEntitlement $entitlement, string $message): self
    {
        return new self(true, self::OUTCOME_ALREADY_EXISTS, $message, $entitlement, $entitlement->status, $entitlement->status);
    }

    public static function transitioned(BillingServiceEntitlement $entitlement, string $previous, string $new, ?string $reason): self
    {
        return new self(true, self::OUTCOME_TRANSITIONED, "Entitlement {$previous} → {$new}.", $entitlement, $previous, $new, $reason);
    }

    public static function unchanged(BillingServiceEntitlement $entitlement, string $message): self
    {
        return new self(true, self::OUTCOME_UNCHANGED, $message, $entitlement, $entitlement->status, $entitlement->status);
    }

    public static function invalidTransition(BillingServiceEntitlement $entitlement, string $previous, string $attempted): self
    {
        return new self(
            false,
            self::OUTCOME_INVALID_TRANSITION,
            "Invalid entitlement transition: {$previous} → {$attempted}.",
            $entitlement,
            $previous,
            null,
        );
    }

    public static function failed(string $message, ?BillingServiceEntitlement $entitlement = null): self
    {
        return new self(false, self::OUTCOME_FAILED, $message, $entitlement);
    }

    public function isSuccessful(): bool
    {
        return $this->ok;
    }
}
