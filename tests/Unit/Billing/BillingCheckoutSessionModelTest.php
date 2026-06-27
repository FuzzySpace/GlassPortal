<?php

namespace Tests\Unit\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 27 — BillingCheckoutSession model: casts, relationships, status helpers,
 * and the shared secret-redaction trait used when rendering payloads.
 */
class BillingCheckoutSessionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_and_status_helpers(): void
    {
        $open = BillingCheckoutSession::factory()->create();
        $this->assertTrue($open->isOpen());
        $this->assertFalse($open->isComplete());
        $this->assertIsInt($open->amount_total);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $open->expires_at);
        $this->assertIsArray($open->payload);

        $complete = BillingCheckoutSession::factory()->completed()->create();
        $this->assertTrue($complete->isComplete());
        $this->assertFalse($complete->isOpen());
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $complete->completed_at);
    }

    public function test_is_expired_when_past_expiry_and_not_complete(): void
    {
        $expired = BillingCheckoutSession::factory()->create([
            'status'     => BillingCheckoutSession::STATUS_OPEN,
            'expires_at' => now()->subHour(),
        ]);
        $this->assertTrue($expired->isExpired());

        // A completed session is never "expired" even if past its expiry.
        $completedPastExpiry = BillingCheckoutSession::factory()->completed()->create([
            'expires_at' => now()->subHour(),
        ]);
        $this->assertFalse($completedPastExpiry->isExpired());
    }

    public function test_scopes_filter_open_and_completed(): void
    {
        BillingCheckoutSession::factory()->count(2)->create();
        BillingCheckoutSession::factory()->completed()->create();

        $this->assertSame(2, BillingCheckoutSession::open()->count());
        $this->assertSame(1, BillingCheckoutSession::completed()->count());
    }

    public function test_relationships_resolve(): void
    {
        $org      = Organization::factory()->create();
        $user     = User::factory()->create();
        $customer = BillingCustomer::factory()->create();
        $product  = BillingProduct::factory()->create();
        $plan     = BillingPlan::factory()->create(['billing_product_id' => $product->id]);
        $sub      = BillingSubscription::factory()->create(['billing_customer_id' => $customer->id]);

        $session = BillingCheckoutSession::factory()->create([
            'billing_customer_id'     => $customer->id,
            'billing_product_id'      => $product->id,
            'billing_plan_id'         => $plan->id,
            'billing_subscription_id' => $sub->id,
            'organization_id'         => $org->id,
            'user_id'                 => $user->id,
        ]);

        $this->assertTrue($session->customer->is($customer));
        $this->assertTrue($session->product->is($product));
        $this->assertTrue($session->plan->is($plan));
        $this->assertTrue($session->subscription->is($sub));
        $this->assertTrue($session->organization->is($org));
        $this->assertTrue($session->user->is($user));

        // Reverse relationships.
        $this->assertTrue($customer->checkoutSessions->contains($session));
        $this->assertTrue($plan->checkoutSessions->contains($session));
        $this->assertTrue($org->billingCheckoutSessions->contains($session));
        $this->assertTrue($user->billingCheckoutSessions->contains($session));
    }

    // -------------------------------------------------------------------------
    // Redaction trait — safe payload rendering
    // -------------------------------------------------------------------------

    public function test_safe_payload_redacts_secret_like_keys_recursively(): void
    {
        $session = BillingCheckoutSession::factory()->create([
            'payload' => [
                'id'            => 'cs_test_123',
                'client_secret' => 'cs_secret_MUST_NOT_LEAK',
                'api_key'       => 'sk_live_MUST_NOT_LEAK',
                'customer'      => 'cus_123',
                'nested'        => [
                    'access_token' => 'tok_MUST_NOT_LEAK',
                    'amount_total' => 4900,
                ],
            ],
        ]);

        $safe = $session->safePayload();
        $json = (string) json_encode($safe);

        // Non-sensitive values are preserved.
        $this->assertSame('cs_test_123', $safe['id']);
        $this->assertSame('cus_123', $safe['customer']);
        $this->assertSame(4900, $safe['nested']['amount_total']);

        // Sensitive values are redacted (recursively).
        $this->assertSame('[redacted]', $safe['client_secret']);
        $this->assertSame('[redacted]', $safe['api_key']);
        $this->assertSame('[redacted]', $safe['nested']['access_token']);

        $this->assertStringNotContainsString('cs_secret_MUST_NOT_LEAK', $json);
        $this->assertStringNotContainsString('sk_live_MUST_NOT_LEAK', $json);
        $this->assertStringNotContainsString('tok_MUST_NOT_LEAK', $json);
    }

    public function test_safe_payload_handles_null(): void
    {
        $session = BillingCheckoutSession::factory()->create(['payload' => null]);
        $this->assertSame([], $session->safePayload());
    }
}
