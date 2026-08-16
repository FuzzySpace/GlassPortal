<?php

namespace App\Services\Billing;

use App\Models\BillingCustomer;
use App\Models\User;

/**
 * Creates Stripe Customer Portal sessions so customers can manage payment
 * methods, view invoices, and cancel subscriptions through Stripe's hosted UI.
 * No local state mutation — Stripe webhooks handle the downstream effects.
 */
class StripePortalService
{
    public function __construct(private StripeBillingClient $stripe) {}

    /**
     * @return array{ok: bool, url: string|null, error: string|null}
     */
    public function createSession(User $user, ?string $returnUrl = null): array
    {
        if (! $this->stripe->isConfigured()) {
            return ['ok' => false, 'url' => null, 'error' => 'Stripe is not configured.'];
        }

        $customer = BillingCustomer::where('user_id', $user->id)->first()
            ?? BillingCustomer::where('organization_id', $user->organization_id)->first();

        if ($customer === null || blank($customer->stripe_customer_id)) {
            return ['ok' => false, 'url' => null, 'error' => 'No linked Stripe customer found. Please complete a purchase first.'];
        }

        $response = $this->stripe->post('/v1/billing_portal/sessions', [
            'customer'   => $customer->stripe_customer_id,
            'return_url' => $returnUrl ?: url('/portal/billing'),
        ]);

        if (! ($response['ok'] ?? false) || empty($response['url'])) {
            return ['ok' => false, 'url' => null, 'error' => 'Could not create portal session. Please try again.'];
        }

        return ['ok' => true, 'url' => $response['url'], 'error' => null];
    }
}
