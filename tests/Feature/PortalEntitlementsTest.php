<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingServiceEntitlement;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 25 — customer portal entitlement visibility (read-only, org-scoped).
 */
class PortalEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private function customerForOrg(Organization $org): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org->id,
        ]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/portal/entitlements')->assertRedirect('/login');
    }

    public function test_customer_sees_own_org_entitlements(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerForOrg($org);
        BillingServiceEntitlement::factory()->create([
            'organization_id' => $org->id,
            'name'            => 'My Hosting Service',
            'status'          => 'active',
        ]);

        $this->actingAs($user)->get('/portal/entitlements')
            ->assertStatus(200)
            ->assertSeeText('My Hosting Service');
    }

    public function test_customer_cannot_see_other_orgs_entitlements(): void
    {
        $org      = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $user     = $this->customerForOrg($org);

        BillingServiceEntitlement::factory()->create([
            'organization_id' => $otherOrg->id,
            'name'            => 'Other Org Secret Service',
            'status'          => 'active',
        ]);

        $this->actingAs($user)->get('/portal/entitlements')
            ->assertStatus(200)
            ->assertDontSeeText('Other Org Secret Service');
    }

    public function test_terminated_and_cancelled_entitlements_are_hidden(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerForOrg($org);

        BillingServiceEntitlement::factory()->create(['organization_id' => $org->id, 'name' => 'Active One', 'status' => 'active']);
        BillingServiceEntitlement::factory()->status('terminated')->create(['organization_id' => $org->id, 'name' => 'Terminated One']);
        BillingServiceEntitlement::factory()->status('cancelled')->create(['organization_id' => $org->id, 'name' => 'Cancelled One']);

        $response = $this->actingAs($user)->get('/portal/entitlements');

        $response->assertSeeText('Active One');
        $response->assertDontSeeText('Terminated One');
        $response->assertDontSeeText('Cancelled One');
    }

    public function test_customer_without_org_sees_empty_state(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => null]);

        $this->actingAs($user)->get('/portal/entitlements')
            ->assertStatus(200)
            ->assertSeeText('not linked to an organization');
    }

    public function test_portal_has_no_lifecycle_write_route(): void
    {
        // The only lifecycle action route is admin-scoped; customers are forbidden.
        $org         = Organization::factory()->create();
        $user        = $this->customerForOrg($org);
        $entitlement = BillingServiceEntitlement::factory()->create(['organization_id' => $org->id, 'status' => 'active']);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->actingAs($user)
            ->post(route('admin.billing.entitlements.action', [$entitlement, 'suspend']))
            ->assertForbidden();

        $this->assertSame('active', $entitlement->fresh()->status);
    }

    public function test_portal_never_renders_sensitive_metadata(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerForOrg($org);
        BillingServiceEntitlement::factory()->create([
            'organization_id' => $org->id,
            'status'          => 'active',
            'metadata'        => ['api_token' => 'PORTAL_LEAK_TOKEN', 'stripe_secret' => 'PORTAL_LEAK_STRIPE'],
        ]);

        $content = $this->actingAs($user)->get('/portal/entitlements')->getContent();

        $this->assertStringNotContainsString('PORTAL_LEAK_TOKEN', $content);
        $this->assertStringNotContainsString('PORTAL_LEAK_STRIPE', $content);
    }
}
