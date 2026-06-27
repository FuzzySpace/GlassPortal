<?php

namespace App\Services\Provisioning;

use App\Models\ProvisioningRequest;

/**
 * Normalized result returned by ProvisioningRequestService (Phase 26).
 * Carries no secrets.
 */
final readonly class ProvisioningRequestResult
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
        public ?ProvisioningRequest $request = null,
        public ?string $previousStatus = null,
        public ?string $newStatus = null,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}

    public static function created(ProvisioningRequest $request, string $message): self
    {
        return new self(true, self::OUTCOME_CREATED, $message, $request, null, $request->status);
    }

    public static function alreadyExists(ProvisioningRequest $request, string $message): self
    {
        return new self(true, self::OUTCOME_ALREADY_EXISTS, $message, $request, $request->status, $request->status);
    }

    public static function transitioned(ProvisioningRequest $request, string $previous, string $new, ?string $reason): self
    {
        return new self(true, self::OUTCOME_TRANSITIONED, "Request {$previous} → {$new}.", $request, $previous, $new, $reason);
    }

    public static function unchanged(ProvisioningRequest $request, string $message): self
    {
        return new self(true, self::OUTCOME_UNCHANGED, $message, $request, $request->status, $request->status);
    }

    public static function invalidTransition(ProvisioningRequest $request, string $previous, string $attempted): self
    {
        return new self(
            false,
            self::OUTCOME_INVALID_TRANSITION,
            "Invalid provisioning request transition: {$previous} → {$attempted}.",
            $request,
            $previous,
            null,
        );
    }

    public static function failed(string $message, ?ProvisioningRequest $request = null): self
    {
        return new self(false, self::OUTCOME_FAILED, $message, $request);
    }

    public function isSuccessful(): bool
    {
        return $this->ok;
    }
}
