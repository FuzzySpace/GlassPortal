<?php

namespace Tests\Feature;

use App\Models\BillingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 27 — public Stripe webhook endpoint. Disabled by default (404), fails
 * closed without a signing secret (500), rejects bad signatures/payloads (400),
 * and processes verified events idempotently (200). Public + no CSRF (it is an
 * /api route), and never leaks the signing secret.
 */
class StripeWebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/billing/stripe/webhook';

    private function enableWebhooks(string $secret = 'whsec_endpoint_test'): void
    {
        config([
            'billing.webhooks.enabled'      => true,
            'billing.stripe.webhook_secret' => $secret,
            'billing.webhooks.tolerance'    => 300,
        ]);
    }

    /** POST a raw body with an optional Stripe signature header. */
    private function postWebhook(string $payload, ?string $signature): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', self::URI, [], [], [], $server, $payload);
    }

    private function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $t   = $timestamp ?? time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", $secret);

        return "t={$t},v1={$sig}";
    }

    // -------------------------------------------------------------------------

    public function test_returns_404_when_webhooks_disabled(): void
    {
        config(['billing.webhooks.enabled' => false]);

        $this->postWebhook('{"id":"evt_x","type":"customer.created"}', 't=1,v1=abc')
            ->assertStatus(404);
    }

    public function test_fails_closed_with_500_when_secret_missing(): void
    {
        config([
            'billing.webhooks.enabled'      => true,
            'billing.stripe.webhook_secret' => '', // enabled but unconfigured
        ]);

        $this->postWebhook('{"id":"evt_x","type":"customer.created"}', 't=1,v1=abc')
            ->assertStatus(500);
    }

    public function test_rejects_invalid_signature_with_400(): void
    {
        $this->enableWebhooks();

        $this->postWebhook('{"id":"evt_x","type":"customer.created"}', 't=' . time() . ',v1=deadbeef')
            ->assertStatus(400);

        $this->assertSame(0, BillingEvent::count());
    }

    public function test_rejects_missing_signature_with_400(): void
    {
        $this->enableWebhooks();

        $this->postWebhook('{"id":"evt_x","type":"customer.created"}', null)
            ->assertStatus(400);
    }

    public function test_rejects_valid_signature_but_malformed_payload_with_400(): void
    {
        $secret  = 'whsec_endpoint_test';
        $this->enableWebhooks($secret);

        // Correctly signed, but missing id/type → payload validation fails.
        $payload = '{"foo":"bar"}';
        $this->postWebhook($payload, $this->sign($payload, $secret))
            ->assertStatus(400);
    }

    public function test_processes_verified_event_with_200_and_records_it(): void
    {
        $secret  = 'whsec_endpoint_test';
        $this->enableWebhooks($secret);

        $payload = json_encode([
            'id'   => 'evt_endpoint_ok',
            'type' => 'customer.created',
            'data' => ['object' => ['id' => 'cus_endpoint', 'email' => 'e@p.test']],
        ]);

        $this->postWebhook($payload, $this->sign($payload, $secret))
            ->assertStatus(200)
            ->assertJson(['received' => true, 'status' => 'processed']);

        $this->assertDatabaseHas('billing_events', [
            'provider_event_id' => 'evt_endpoint_ok',
            'status'            => BillingEvent::STATUS_PROCESSED,
        ]);
    }

    public function test_duplicate_delivery_returns_2xx(): void
    {
        $secret  = 'whsec_endpoint_test';
        $this->enableWebhooks($secret);

        $payload   = json_encode([
            'id'   => 'evt_endpoint_dup',
            'type' => 'customer.created',
            'data' => ['object' => ['id' => 'cus_dup_ep']],
        ]);
        $signature = $this->sign($payload, $secret);

        $this->postWebhook($payload, $signature)->assertStatus(200);
        $this->postWebhook($payload, $signature)
            ->assertStatus(200)
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, BillingEvent::where('provider_event_id', 'evt_endpoint_dup')->count());
    }

    public function test_endpoint_is_public_and_requires_no_auth(): void
    {
        $secret  = 'whsec_endpoint_test';
        $this->enableWebhooks($secret);

        $payload = json_encode([
            'id'   => 'evt_public',
            'type' => 'customer.created',
            'data' => ['object' => ['id' => 'cus_public']],
        ]);

        // No actingAs, no session, no CSRF token — still processed.
        $this->postWebhook($payload, $this->sign($payload, $secret))->assertStatus(200);
    }

    public function test_response_never_contains_webhook_secret(): void
    {
        $secret = 'whsec_endpoint_secret_MUST_NOT_LEAK';
        $this->enableWebhooks($secret);

        $payload  = json_encode(['id' => 'evt_leak', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_l']]]);
        $response = $this->postWebhook($payload, $this->sign($payload, $secret));

        $this->assertStringNotContainsString($secret, $response->getContent());
    }
}
