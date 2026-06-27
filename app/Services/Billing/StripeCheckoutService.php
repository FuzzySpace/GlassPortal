<?php

namespace App\Services\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\Organization;
use App\Models\User;

/**
 * Starts Stripe Checkout for a billing plan (Phase 27).
 *
 * Creates a Stripe Checkout Session (via StripeBillingClient — no SDK, faked in
 * tests) and stores a local BillingCheckoutSession. It NEVER creates
 * subscriptions, entitlements, provisioning requests, or infrastructure — those
 * happen only after Stripe confirms via webhook. Fails safe when checkout/Stripe
 * is not configured or the plan has no Stripe price.
 */
class StripeCheckoutService
{
    public function __construct(private StripeBillingClient $stripe) {}

    public function createSessionForPlan(
        BillingPlan $plan,
        User $user,
        ?Organization $organization = null,
        array $options = [],
    ): StripeCheckoutResult {
        if (! (bool) config('billing.checkout.enabled', false)) {
            return StripeCheckoutResult::failed('disabled', 'Checkout is not enabled.');
        }

        if (! $this->stripe->isConfigured()) {
            return StripeCheckoutResult::failed('unconfigured', 'Stripe is not configured.');
        }

        if ($plan->status !== 'active') {
            return StripeCheckoutResult::failed('plan_unavailable', 'This plan is not available for checkout.');
        }

        if (blank($plan->stripe_price_id)) {
            return StripeCheckoutResult::failed('no_price', 'This plan has no Stripe price configured.');
        }

        $organization ??= $user->organization;
        $customer = $this->resolveCustomer($user, $organization);

        $mode       = (string) ($options['mode'] ?? config('billing.checkout.mode', 'subscription'));
        $successUrl = (string) ($options['success_url'] ?? config('billing.checkout.success_url', ''));
        $cancelUrl  = (string) ($options['cancel_url'] ?? config('billing.checkout.cancel_url', ''));

        $params = array_filter([
            'mode'                => $mode,
            'success_url'         => $successUrl ?: url('/portal/billing'),
            'cancel_url'          => $cancelUrl ?: url('/portal/billing'),
            'line_items'          => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
            'client_reference_id' => (string) $customer->id,
            'customer'            => $customer->stripe_customer_id ?: null,
            'metadata'            => array_filter([
                'glassportal_billing_customer_id' => (string) $customer->id,
                'glassportal_billing_plan_id'     => (string) $plan->id,
                'glassportal_organization_id'     => $organization?->id ? (string) $organization->id : null,
                'glassportal_user_id'             => (string) $user->id,
            ], fn ($v) => $v !== null && $v !== ''),
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->stripe->createCheckoutSession($params);

        if (! ($result['ok'] ?? false) || empty($result['id'])) {
            return StripeCheckoutResult::failed('stripe_error', 'Could not start checkout. Please try again later.');
        }

        $session = BillingCheckoutSession::create([
            'billing_customer_id' => $customer->id,
            'billing_product_id'  => $plan->billing_product_id,
            'billing_plan_id'     => $plan->id,
            'organization_id'     => $organization?->id,
            'user_id'             => $user->id,
            'provider'            => 'stripe',
            'provider_session_id' => $result['id'],
            'mode'                => $mode,
            'status'              => BillingCheckoutSession::STATUS_OPEN,
            'currency'            => $plan->currency,
            'amount_total'        => $plan->amount_cents,
            'success_url'         => $successUrl ?: null,
            'cancel_url'          => $cancelUrl ?: null,
            'payload'             => $result['data'] ?? null,
        ]);

        return StripeCheckoutResult::created($session, $result['url'] ?? null, 'Checkout session created.');
    }

    /**
     * Find an existing billing customer for the org/user, or create one. Never
     * stores secrets — only name/email/back-references.
     */
    private function resolveCustomer(User $user, ?Organization $organization): BillingCustomer
    {
        if ($organization !== null) {
            $existing = BillingCustomer::where('organization_id', $organization->id)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $byUser = BillingCustomer::where('user_id', $user->id)->first();
        if ($byUser !== null) {
            return $byUser;
        }

        return BillingCustomer::create([
            'organization_id' => $organization?->id,
            'user_id'         => $user->id,
            'name'            => $organization?->name ?? $user->name,
            'email'           => $user->email,
            'status'          => 'active',
        ]);
    }
}
