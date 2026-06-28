<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 28 — customer billing self-service visibility. Strictly org/user scoped,
 * read-only, cross-organization access denied, no secrets rendered.
 */
class PortalBillingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function customer(Organization $org): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => $org->id]);
    }

    /** @return array{0: User, 1: BillingCustomer} */
    private function customerWithBilling(): array
    {
        $org      = Organization::factory()->create();
        $user     = $this->customer($org);
        $customer = BillingCustomer::factory()->forOrganization($org)->create();

        return [$user, $customer];
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function indexPaths(): array
    {
        return [
            '/portal/billing',
            '/portal/billing/subscriptions',
            '/portal/billing/invoices',
            '/portal/billing/payments',
            '/portal/billing/checkout-sessions',
            '/portal/billing/change-requests',
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        foreach ($this->indexPaths() as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_non_customer_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        foreach ($this->indexPaths() as $path) {
            $this->actingAs($admin)->get($path)->assertForbidden();
        }
    }

    public function test_customer_can_view_all_billing_index_pages(): void
    {
        [$user] = $this->customerWithBilling();
        foreach ($this->indexPaths() as $path) {
            $this->actingAs($user)->get($path)->assertStatus(200);
        }
    }

    // -------------------------------------------------------------------------
    // Ownership / cross-org isolation
    // -------------------------------------------------------------------------

    public function test_customer_sees_only_own_subscriptions(): void
    {
        [$user, $customer] = $this->customerWithBilling();
        $plan = BillingPlan::factory()->create(['name' => 'My Plan ABC']);
        BillingSubscription::factory()->create(['billing_customer_id' => $customer->id, 'billing_plan_id' => $plan->id]);

        $otherCust = BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create();
        $otherPlan = BillingPlan::factory()->create(['name' => 'Other Plan XYZ']);
        BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id, 'billing_plan_id' => $otherPlan->id]);

        $this->actingAs($user)->get('/portal/billing/subscriptions')
            ->assertStatus(200)
            ->assertSeeText('My Plan ABC')
            ->assertDontSeeText('Other Plan XYZ');
    }

    public function test_customer_cannot_view_another_orgs_subscription_detail(): void
    {
        [$user] = $this->customerWithBilling();
        $otherCust = BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create();
        $otherSub  = BillingSubscription::factory()->create(['billing_customer_id' => $otherCust->id]);

        $this->actingAs($user)->get(route('portal.billing.subscriptions.show', $otherSub))->assertNotFound();
    }

    public function test_customer_can_view_own_subscription_detail(): void
    {
        [$user, $customer] = $this->customerWithBilling();
        $sub = BillingSubscription::factory()->create(['billing_customer_id' => $customer->id]);

        $this->actingAs($user)->get(route('portal.billing.subscriptions.show', $sub))->assertStatus(200);
    }

    public function test_customer_cannot_view_another_orgs_invoice_detail(): void
    {
        [$user] = $this->customerWithBilling();
        $otherCust = BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create();
        $otherInv  = BillingInvoice::factory()->create(['billing_customer_id' => $otherCust->id]);

        $this->actingAs($user)->get(route('portal.billing.invoices.show', $otherInv))->assertNotFound();
    }

    public function test_customer_cannot_view_another_orgs_checkout_session_detail(): void
    {
        [$user] = $this->customerWithBilling();
        $otherCust = BillingCustomer::factory()->forOrganization(Organization::factory()->create())->create();
        $otherSession = BillingCheckoutSession::factory()->create(['billing_customer_id' => $otherCust->id]);

        $this->actingAs($user)->get(route('portal.billing.checkout-sessions.show', $otherSession))->assertNotFound();
    }

    public function test_payment_methods_show_safe_summary_only(): void
    {
        [$user, $customer] = $this->customerWithBilling();
        \App\Models\BillingPaymentMethod::create([
            'billing_customer_id' => $customer->id,
            'type'                => 'card',
            'brand'               => 'visa',
            'last4'               => '4242',
            'exp_month'           => 12,
            'exp_year'            => 2030,
            'is_default'          => true,
        ]);

        $this->actingAs($user)->get('/portal/billing/payments')
            ->assertStatus(200)
            ->assertSeeText('4242');
    }

    // -------------------------------------------------------------------------
    // Secret hygiene
    // -------------------------------------------------------------------------

    public function test_billing_views_never_render_secret_metadata(): void
    {
        [$user, $customer] = $this->customerWithBilling();
        $sub = BillingSubscription::factory()->create([
            'billing_customer_id' => $customer->id,
            'metadata'            => ['stripe_secret' => 'SK_PORTAL_LEAK', 'api_token' => 'TOK_PORTAL_LEAK'],
        ]);
        BillingCheckoutSession::factory()->create([
            'billing_customer_id' => $customer->id,
            'payload'             => ['client_secret' => 'CS_PORTAL_LEAK'],
        ]);

        $detail = $this->actingAs($user)->get(route('portal.billing.subscriptions.show', $sub))->getContent();
        $this->assertStringNotContainsString('SK_PORTAL_LEAK', $detail);
        $this->assertStringNotContainsString('TOK_PORTAL_LEAK', $detail);

        $sessions = $this->actingAs($user)->get('/portal/billing/checkout-sessions')->getContent();
        $this->assertStringNotContainsString('CS_PORTAL_LEAK', $sessions);
    }
}
