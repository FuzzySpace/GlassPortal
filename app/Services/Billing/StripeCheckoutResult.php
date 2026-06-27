<?php

namespace App\Services\Billing;

use App\Models\BillingCheckoutSession;

/**
 * Normalized result of a checkout-session creation attempt (Phase 27).
 * Carries no secrets.
 */
final readonly class StripeCheckoutResult
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_FAILED  = 'failed';

    public function __construct(
        public bool $ok,
        public string $status,
        public string $message = '',
        public ?BillingCheckoutSession $checkoutSession = null,
        public ?string $redirectUrl = null,
        public ?string $providerSessionId = null,
        public array $metadata = [],
    ) {}

    public static function created(BillingCheckoutSession $session, ?string $redirectUrl, string $message): self
    {
        return new self(true, self::OUTCOME_CREATED, $message, $session, $redirectUrl, $session->provider_session_id);
    }

    /** $status is a short machine code: disabled | unconfigured | plan_unavailable | no_price | stripe_error. */
    public static function failed(string $status, string $message): self
    {
        return new self(false, $status, $message);
    }

    public function isSuccessful(): bool
    {
        return $this->ok;
    }
}
