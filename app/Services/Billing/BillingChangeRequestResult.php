<?php

namespace App\Services\Billing;

use App\Models\BillingChangeRequest;

/**
 * Normalized result returned by BillingChangeRequestService (Phase 28).
 *
 * Carries no secrets. `status` is the operation outcome (not the request's
 * lifecycle status — that lives on the model / in newStatus).
 */
final readonly class BillingChangeRequestResult
{
    public const OUTCOME_CREATED            = 'created';
    public const OUTCOME_TRANSITIONED       = 'transitioned';
    public const OUTCOME_INVALID_TRANSITION = 'invalid_transition';
    public const OUTCOME_FORBIDDEN          = 'forbidden';
    public const OUTCOME_FAILED             = 'failed';

    public function __construct(
        public bool $ok,
        public string $status,
        public string $message = '',
        public ?BillingChangeRequest $changeRequest = null,
        public ?string $previousStatus = null,
        public ?string $newStatus = null,
    ) {}

    public static function created(BillingChangeRequest $request, string $message): self
    {
        return new self(true, self::OUTCOME_CREATED, $message, $request, null, $request->status);
    }

    public static function transitioned(BillingChangeRequest $request, string $previous, string $new, string $message): self
    {
        return new self(true, self::OUTCOME_TRANSITIONED, $message, $request, $previous, $new);
    }

    public static function invalidTransition(BillingChangeRequest $request, string $previous, string $attempted): self
    {
        return new self(
            false,
            self::OUTCOME_INVALID_TRANSITION,
            "Invalid change-request transition: {$previous} → {$attempted}.",
            $request,
            $previous,
            null,
        );
    }

    public static function forbidden(string $message, ?BillingChangeRequest $request = null): self
    {
        return new self(false, self::OUTCOME_FORBIDDEN, $message, $request);
    }

    public static function failed(string $message, ?BillingChangeRequest $request = null): self
    {
        return new self(false, self::OUTCOME_FAILED, $message, $request);
    }

    public function isSuccessful(): bool
    {
        return $this->ok;
    }
}
