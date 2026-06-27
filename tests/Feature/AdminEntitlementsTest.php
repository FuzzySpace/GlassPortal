<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingServiceEntitlement;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 25 — admin entitlement visibility + controlled lifecycle actions.
 */
class AdminEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

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

    public function test_admin_can_list_and_view_entitlements(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->create(['name' => 'Managed Hosting Plan']);
        $admin       = $this->admin();

        $this->actingAs($admin)->get('/admin/billing/entitlements')
            ->assertStatus(200)->assertSeeText('Managed Hosting Plan');

        $this->actingAs($admin)->get(route('admin.billing.entitlements.show', $entitlement))
            ->assertStatus(200)->assertSeeText('Event History');
    }

    public function test_customer_cannot_access_admin_entitlements(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->create();

        $this->actingAs($this->customer())->get('/admin/billing/entitlements')->assertForbidden();
        $this->actingAs($this->customer())->get(route('admin.billing.entitlements.show', $entitlement))->assertForbidden();
    }

    public function test_staff_cannot_access_admin_entitlements(): void
    {
        $this->actingAs($this->staff())->get('/admin/billing/entitlements')->assertForbidden();
    }

    public function test_guest_cannot_access_admin_entitlements(): void
    {
        $this->get('/admin/billing/entitlements')->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // Lifecycle actions
    // -------------------------------------------------------------------------

    public function test_admin_can_suspend_active_entitlement(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->create(['status' => 'active']);

        $this->actingAs($this->admin())
            ->post(route('admin.billing.entitlements.action', [$entitlement, 'suspend']), ['reason' => 'non-payment'])
            ->assertRedirect(route('admin.billing.entitlements.show', $entitlement));

        $this->assertSame('suspended', $entitlement->fresh()->status);
        $this->assertDatabaseHas('billing_service_entitlement_events', [
            'billing_service_entitlement_id' => $entitlement->id,
            'event_type'                     => 'suspended',
            'new_status'                     => 'suspended',
            'reason'                         => 'non-payment',
        ]);
    }

    public function test_invalid_action_redirects_with_error_and_leaves_status(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->status('terminated')->create();

        $this->actingAs($this->admin())
            ->post(route('admin.billing.entitlements.action', [$entitlement, 'reactivate']))
            ->assertSessionHas('error');

        $this->assertSame('terminated', $entitlement->fresh()->status);
    }

    public function test_customer_cannot_run_lifecycle_action(): void
    {
        $entitlement = BillingServiceEntitlement::factory()->create(['status' => 'active']);

        $this->actingAs($this->customer())
            ->post(route('admin.billing.entitlements.action', [$entitlement, 'suspend']))
            ->assertForbidden();

        $this->assertSame('active', $entitlement->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Secret hygiene
    // -------------------------------------------------------------------------

    public function test_admin_detail_never_renders_sensitive_metadata(): void
    {
        $secrets = [
            'api_token'      => 'LEAK_API_TOKEN_AAAA',
            'secret'         => 'LEAK_SECRET_BBBB',
            'password'       => 'LEAK_PASSWORD_CCCC',
            'stripe_secret'  => 'LEAK_STRIPE_SECRET_DDDD',
            'signing_secret' => 'LEAK_SIGNING_SECRET_EEEE',
        ];
        $entitlement = BillingServiceEntitlement::factory()->create(['metadata' => $secrets]);

        $content = $this->actingAs($this->admin())
            ->get(route('admin.billing.entitlements.show', $entitlement))
            ->getContent();

        foreach ($secrets as $value) {
            $this->assertStringNotContainsString($value, $content);
        }
    }
}
