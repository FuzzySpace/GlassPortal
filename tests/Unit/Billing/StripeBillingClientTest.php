<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\Organization;
use App\Services\Billing\StripeBillingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 — StripeBillingClient: config detection, webhook verification,
 * safe payloads, idempotent intake. No real Stripe calls.
 */
class StripeBillingClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(): StripeBillingClient
    {
        return new StripeBillingClient();
    }

    private function configureStripe(string $secret = 'sk_test_abc', string $whsec = 'whsec_test_abc'): void
    {
        config([
            'billing.enabled'               => true,
            'billing.mode'                  => 'stripe',
            'billing.stripe.secret_key'     => $secret,
            'billing.stripe.webhook_secret' => $whsec,
        ]);
    }

    // -------------------------------------------------------------------------
    // Config detection
    // -------------------------------------------------------------------------

    public function test_is_configured_requires_enabled_mode_and_secret(): void
    {
        $this->configureStripe();
        $this->assertTrue($this->client()->isConfigured());

        config(['billing.stripe.secret_key' => '']);
        $this->assertFalse($this->client()->isConfigured());

        $this->configureStripe();
        config(['billing.enabled' => false]);
        $this->assertFalse($this->client()->isConfigured());

        $this->configureStripe();
        config(['billing.mode' => 'external']);
        $this->assertFalse($this->client()->isConfigured());
    }

    public function test_resolves_from_container_as_singleton(): void
    {
        $this->assertSame(app(StripeBillingClient::class), app(StripeBillingClient::class));
    }

    // -------------------------------------------------------------------------
    // No secret leakage
    // -------------------------------------------------------------------------

    public function test_safe_config_summary_never_includes_secret_values(): void
    {
        $secret = 'sk_live_secret_must_not_leak_2222';
        $whsec  = 'whsec_secret_must_not_leak_3333';
        $this->configureStripe($secret, $whsec);

        $summary = $this->client()->safeConfigSummary();
        $json    = (string) json_encode($summary);

        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString($whsec, $json);
        $this->assertTrue($summary['has_secret_key']);
        $this->assertTrue($summary['has_webhook_secret']);
        $this->assertTrue($summary['stripe_configured']);
    }

    public function test_publishable_key_is_exposable_but_secret_is_not_returned(): void
    {
        config([
            'billing.stripe.publishable_key' => 'pk_test_public',
            'billing.stripe.secret_key'      => 'sk_test_private',
        ]);

        $this->assertSame('pk_test_public', $this->client()->publishableKey());
        // There is no public accessor that returns the secret.
        $this->assertFalse(method_exists($this->client(), 'secretKeyValue'));
    }

    // -------------------------------------------------------------------------
    // Webhook signature verification
    // -------------------------------------------------------------------------

    public function test_verify_webhook_signature_accepts_valid_signature(): void
    {
        config(['billing.stripe.webhook_secret' => 'whsec_sig_test']);
        $payload = '{"id":"evt_1","object":"event"}';
        $t       = time();
        $sig     = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_sig_test');

        $this->assertTrue($this->client()->verifyWebhookSignature($payload, "t={$t},v1={$sig}"));
    }

    public function test_verify_webhook_signature_rejects_tampered_signature(): void
    {
        config(['billing.stripe.webhook_secret' => 'whsec_sig_test']);
        $t = time();

        $this->assertFalse($this->client()->verifyWebhookSignature('{"a":1}', "t={$t},v1=deadbeefdeadbeef"));
    }

    public function test_verify_webhook_signature_false_without_secret(): void
    {
        config(['billing.stripe.webhook_secret' => '']);
        $this->assertFalse($this->client()->verifyWebhookSignature('payload', 't=1,v1=abc'));
    }

    public function test_verify_webhook_signature_rejects_stale_timestamp(): void
    {
        config(['billing.stripe.webhook_secret' => 'whsec_sig_test']);
        $payload = 'p';
        $t       = time() - 10_000; // well outside the default 300s tolerance
        $sig     = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_sig_test');

        $this->assertFalse($this->client()->verifyWebhookSignature($payload, "t={$t},v1={$sig}"));
    }

    // -------------------------------------------------------------------------
    // Customer payload
    // -------------------------------------------------------------------------

    public function test_customer_payload_has_back_references_and_no_secret(): void
    {
        $secret = 'sk_live_payload_secret';
        config(['billing.stripe.secret_key' => $secret]);

        $org      = Organization::factory()->create();
        $customer = BillingCustomer::factory()->forOrganization($org)->create([
            'name'  => 'Acme Inc',
            'email' => 'billing@acme.test',
        ]);

        $payload = $this->client()->customerPayload($customer);

        $this->assertSame('Acme Inc', $payload['name']);
        $this->assertSame('billing@acme.test', $payload['email']);
        $this->assertSame((string) $customer->id, $payload['metadata']['glassportal_billing_customer_id']);
        $this->assertSame((string) $org->id, $payload['metadata']['glassportal_organization_id']);
        $this->assertStringNotContainsString($secret, (string) json_encode($payload));
    }

    // -------------------------------------------------------------------------
    // Idempotent event intake
    // -------------------------------------------------------------------------

    public function test_record_event_stores_provider_event_id(): void
    {
        $this->client()->recordEvent('payment_intent.succeeded', 'evt_intake_1', ['k' => 'v']);

        $this->assertDatabaseHas('billing_events', [
            'event_type'        => 'payment_intent.succeeded',
            'provider'          => 'stripe',
            'provider_event_id' => 'evt_intake_1',
            'status'            => 'pending',
        ]);
    }

    public function test_record_event_is_idempotent_on_duplicate_provider_event_id(): void
    {
        $first  = $this->client()->recordEvent('invoice.paid', 'evt_idem_1', ['v' => 1]);
        $second = $this->client()->recordEvent('invoice.paid', 'evt_idem_1', ['v' => 2]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BillingEvent::where('provider_event_id', 'evt_idem_1')->count());
    }
}
