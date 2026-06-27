<?php

/*
|--------------------------------------------------------------------------
| GlassBilling — Stripe-first Billing Foundation (Phase 24)
|--------------------------------------------------------------------------
|
| Configuration for the in-portal GlassBilling foundation. GlassBilling is the
| billing/account/subscription/payment source of truth (see
| docs/architecture/billing-source-of-truth.md). Stripe is the payment target.
|
| Security: STRIPE_SECRET_KEY and STRIPE_WEBHOOK_SECRET are server-side only and
| must NEVER appear in views, logs, healthcheck output, errors, or tests. Only
| the publishable key may ever reach the browser.
|
| NOTE: this file is distinct from config/glassbilling.php, which is the legacy
| read-only HTTP bridge to an external GlassBilling service.
|
*/

return [

    // Master switch for the billing foundation. Reuses the existing
    // GLASSBILLING_ENABLED env convention. Off by default.
    'enabled' => filter_var(env('GLASSBILLING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Operating mode: 'stripe' (target) | 'external' (legacy bridge) | 'off'.
    'mode' => env('GLASSBILLING_MODE', 'stripe'),

    'stripe' => [
        // Server-side only — never exposed.
        'secret_key'      => env('STRIPE_SECRET_KEY', ''),
        // Webhook signing secret (whsec_...) — server-side only, never exposed.
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET', ''),
        // Publishable key (pk_...) — safe for the browser.
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
        // Stripe REST base. Configurable so tests can Http::fake() it; never the SDK.
        'api_base'        => env('STRIPE_API_BASE', 'https://api.stripe.com'),
    ],

    // Default ISO currency for billing records created locally.
    'currency' => env('GLASSBILLING_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Checkout (Phase 27)
    |--------------------------------------------------------------------------
    */
    'checkout' => [
        'enabled'     => filter_var(env('GLASSBILLING_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Default Checkout mode for plan subscriptions.
        'mode'        => env('STRIPE_CHECKOUT_MODE', 'subscription'),
        'success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL', ''),
        'cancel_url'  => env('STRIPE_CHECKOUT_CANCEL_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Webhooks (Phase 27)
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled'   => filter_var(env('GLASSBILLING_WEBHOOKS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Max age (seconds) of the signed webhook timestamp.
        'tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        // Event types this phase processes; anything else is recorded + ignored.
        'allowed_events' => [
            'checkout.session.completed',
            'customer.created',
            'customer.updated',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.paid',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
            'payment_method.attached',
        ],
    ],

];
