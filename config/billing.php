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
    ],

    // Default ISO currency for billing records created locally.
    'currency' => env('GLASSBILLING_CURRENCY', 'USD'),

];
