<?php

namespace App\Services\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingEvent;

/**
 * Stripe-first billing client wrapper (Phase 24).
 *
 * This phase is foundation-only: the wrapper handles configuration detection,
 * safe payload building, webhook signature verification, and idempotent event
 * intake. It does NOT make real Stripe API calls (checkout sessions, customer
 * creation, etc. are later phases) and requires no Stripe SDK.
 *
 * Security invariants:
 * - The Stripe SECRET key and WEBHOOK secret are read from config only and are
 *   NEVER returned, logged, or echoed. Only presence booleans / the publishable
 *   key are ever exposed.
 * - Reads config at call-time so test config overrides take effect.
 */
class StripeBillingClient
{
    /** Billing foundation master switch. */
    public function isEnabled(): bool
    {
        return (bool) config('billing.enabled', false);
    }

    public function mode(): string
    {
        return (string) config('billing.mode', 'stripe');
    }

    /**
     * True when Stripe is usable: billing enabled, mode = stripe, and a secret
     * key is present. (Never reveals the key itself.)
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && $this->mode() === 'stripe'
            && $this->hasSecretKey();
    }

    public function hasSecretKey(): bool
    {
        return $this->secretKey() !== '';
    }

    public function hasWebhookSecret(): bool
    {
        return (string) config('billing.stripe.webhook_secret', '') !== '';
    }

    /** Publishable key is browser-safe (pk_...). May be empty. */
    public function publishableKey(): string
    {
        return (string) config('billing.stripe.publishable_key', '');
    }

    /**
     * Presence-only configuration summary for healthcheck / admin display.
     * NEVER includes secret values.
     *
     * @return array{enabled: bool, mode: string, stripe_configured: bool, has_secret_key: bool, has_webhook_secret: bool, has_publishable_key: bool}
     */
    public function safeConfigSummary(): array
    {
        return [
            'enabled'             => $this->isEnabled(),
            'mode'                => $this->mode(),
            'stripe_configured'   => $this->isConfigured(),
            'has_secret_key'      => $this->hasSecretKey(),
            'has_webhook_secret'  => $this->hasWebhookSecret(),
            'has_publishable_key' => $this->publishableKey() !== '',
        ];
    }

    /**
     * Build a safe Stripe customer payload from a local billing customer.
     * Contains no secrets — only name/email and back-reference metadata.
     *
     * @return array{name: ?string, email: ?string, metadata: array<string, string>}
     */
    public function customerPayload(BillingCustomer $customer): array
    {
        $metadata = array_filter([
            'glassportal_billing_customer_id' => (string) $customer->id,
            'glassportal_organization_id'     => $customer->organization_id ? (string) $customer->organization_id : null,
            'glassportal_user_id'             => $customer->user_id ? (string) $customer->user_id : null,
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'name'     => $customer->name,
            'email'    => $customer->email,
            'metadata' => $metadata,
        ];
    }

    /**
     * Verify a Stripe webhook signature header (`t=...,v1=...`) against the raw
     * payload using the configured webhook secret. Pure PHP — no SDK required.
     *
     * @param int $tolerance Max age (seconds) of the signed timestamp; 0 disables the check.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader, int $tolerance = 300): bool
    {
        $secret = (string) config('billing.stripe.webhook_secret', '');
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $timestamp  = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit((string) $timestamp) || $signatures === []) {
            return false;
        }

        if ($tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Idempotently record a provider event. A duplicate `provider_event_id`
     * returns the existing row (no second insert) — safe webhook intake.
     */
    public function recordEvent(string $eventType, ?string $providerEventId, array $payload = [], string $provider = 'stripe'): BillingEvent
    {
        if ($providerEventId !== null && $providerEventId !== '') {
            $existing = BillingEvent::query()
                ->where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return BillingEvent::create([
            'event_type'        => $eventType,
            'provider'          => $provider,
            'provider_event_id' => $providerEventId,
            'payload'           => $payload,
            'status'            => BillingEvent::STATUS_PENDING,
        ]);
    }

    /** Read the secret key without ever exposing it beyond this class. */
    private function secretKey(): string
    {
        return (string) config('billing.stripe.secret_key', '');
    }
}
