<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 — admin billing visibility access control + rendering.
 */
class AdminBillingTest extends TestCase
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

    /** @return list<string> */
    private function billingPaths(): array
    {
        return [
            '/admin/billing',
            '/admin/billing/customers',
            '/admin/billing/products',
            '/admin/billing/plans',
            '/admin/billing/subscriptions',
            '/admin/billing/events',
        ];
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        foreach ($this->billingPaths() as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_customer_cannot_access_admin_billing(): void
    {
        $customer = $this->customer();
        foreach ($this->billingPaths() as $path) {
            $this->actingAs($customer)->get($path)->assertForbidden();
        }
    }

    public function test_staff_cannot_access_admin_billing(): void
    {
        // Owner/admin only — staff are in the surrounding group but blocked here.
        $staff = $this->staff();
        foreach ($this->billingPaths() as $path) {
            $this->actingAs($staff)->get($path)->assertForbidden();
        }
    }

    public function test_admin_can_access_all_billing_pages(): void
    {
        $admin = $this->admin();
        foreach ($this->billingPaths() as $path) {
            $this->actingAs($admin)->get($path)->assertStatus(200);
        }
    }

    public function test_admin_overview_renders_and_lists_show_records(): void
    {
        $product = BillingProduct::factory()->create(['name' => 'Hosting Pro']);
        BillingPlan::factory()->create(['billing_product_id' => $product->id, 'name' => 'Pro Monthly']);
        $sub = BillingSubscription::factory()->create();

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/billing')->assertStatus(200)->assertSeeText('GlassBilling');
        $this->actingAs($admin)->get('/admin/billing/products')->assertSeeText('Hosting Pro');
        $this->actingAs($admin)->get('/admin/billing/plans')->assertSeeText('Pro Monthly');
        $this->actingAs($admin)->get('/admin/billing/customers')->assertStatus(200);

        $this->actingAs($admin)
            ->get(route('admin.billing.customers.show', $sub->customer))
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Secret hygiene
    // -------------------------------------------------------------------------

    public function test_admin_billing_overview_never_renders_stripe_secret(): void
    {
        $secret = 'sk_live_admin_view_secret_must_not_leak';
        $whsec  = 'whsec_admin_view_secret_must_not_leak';
        config([
            'billing.enabled'               => true,
            'billing.mode'                  => 'stripe',
            'billing.stripe.secret_key'     => $secret,
            'billing.stripe.webhook_secret' => $whsec,
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/billing');

        $response->assertStatus(200);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($whsec, $response->getContent());
    }
}
