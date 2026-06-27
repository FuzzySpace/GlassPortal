<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingCheckoutSession;
use App\Models\BillingEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 27 — admin visibility for Stripe checkout sessions + event detail.
 * Owner/admin only; payloads are rendered through the redaction trait.
 */
class AdminBillingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff->value]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value]);
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/billing/checkout-sessions')->assertRedirect('/login');
    }

    public function test_customer_and_staff_are_forbidden(): void
    {
        $this->actingAs($this->customer())->get('/admin/billing/checkout-sessions')->assertForbidden();
        // Owner/admin only — staff are in the surrounding group but blocked here.
        $this->actingAs($this->staff())->get('/admin/billing/checkout-sessions')->assertForbidden();
    }

    public function test_admin_can_list_checkout_sessions(): void
    {
        BillingCheckoutSession::factory()->create(['provider_session_id' => 'cs_admin_list_1']);

        $this->actingAs($this->admin())
            ->get('/admin/billing/checkout-sessions')
            ->assertStatus(200)
            ->assertSeeText('cs_admin_list_1');
    }

    public function test_admin_can_view_checkout_session_detail(): void
    {
        $session = BillingCheckoutSession::factory()->create(['provider_session_id' => 'cs_admin_detail_1']);

        $this->actingAs($this->admin())
            ->get(route('admin.billing.checkout-sessions.show', $session))
            ->assertStatus(200)
            ->assertSeeText('cs_admin_detail_1');
    }

    public function test_admin_can_view_event_detail(): void
    {
        $event = BillingEvent::create([
            'event_type'        => 'customer.subscription.created',
            'provider'          => 'stripe',
            'provider_event_id' => 'evt_admin_detail',
            'payload'           => ['id' => 'evt_admin_detail'],
            'status'            => BillingEvent::STATUS_PROCESSED,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.billing.events.show', $event))
            ->assertStatus(200)
            ->assertSeeText('customer.subscription.created');
    }

    // -------------------------------------------------------------------------
    // Secret hygiene — payloads are redacted on render
    // -------------------------------------------------------------------------

    public function test_checkout_session_detail_redacts_payload_secrets(): void
    {
        $session = BillingCheckoutSession::factory()->create([
            'payload' => [
                'id'            => 'cs_redact',
                'client_secret' => 'cs_secret_ADMIN_MUST_NOT_LEAK',
                'api_key'       => 'sk_live_ADMIN_MUST_NOT_LEAK',
            ],
        ]);

        $content = $this->actingAs($this->admin())
            ->get(route('admin.billing.checkout-sessions.show', $session))
            ->getContent();

        $this->assertStringNotContainsString('cs_secret_ADMIN_MUST_NOT_LEAK', $content);
        $this->assertStringNotContainsString('sk_live_ADMIN_MUST_NOT_LEAK', $content);
        $this->assertStringContainsString('[redacted]', $content);
    }

    public function test_event_detail_redacts_payload_secrets(): void
    {
        $event = BillingEvent::create([
            'event_type'        => 'payment_method.attached',
            'provider'          => 'stripe',
            'provider_event_id' => 'evt_redact',
            'payload'           => ['id' => 'evt_redact', 'secret' => 'EVENT_SECRET_MUST_NOT_LEAK'],
            'status'            => BillingEvent::STATUS_PROCESSED,
        ]);

        $content = $this->actingAs($this->admin())
            ->get(route('admin.billing.events.show', $event))
            ->getContent();

        $this->assertStringNotContainsString('EVENT_SECRET_MUST_NOT_LEAK', $content);
    }
}
