<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
        ]);
    }

    public function test_portal_dashboard_renders_without_org(): void
    {
        $user     = $this->customerUser();
        $response = $this->actingAs($user)->get('/portal');

        $response->assertStatus(200);
        $response->assertSeeText($user->name);
    }

    public function test_portal_dashboard_shows_no_linked_customer_state(): void
    {
        $org  = Organization::factory()->create(); // no glassbilling_customer_id
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertStatus(200);
        // New customers with no local billing record see the onboarding flow.
        $response->assertSeeText('Welcome to');
    }

    public function test_portal_dashboard_shows_services_when_linked(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customer-services*' => Http::response([
                'data' => [
                    ['id' => 'svc-1', 'product_name' => 'VPS Pro', 'status' => 'active', 'created_at' => '2025-01-01'],
                ],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $org  = Organization::factory()->withGlassBillingId('gb_cust_portal')->create();
        $user = $this->customerUser($org);
        // Create a local billing customer so the onboarding check passes.
        \App\Models\BillingCustomer::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertStatus(200);
        $response->assertSeeText('VPS Pro');
    }

    public function test_portal_services_shows_no_linked_customer_state(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/services');

        $response->assertStatus(200);
        $response->assertSeeText('not yet linked');
    }

    public function test_portal_services_shows_services_when_linked(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customer-services*' => Http::response([
                'data' => [
                    ['id' => 'svc-2', 'product_name' => 'DNS Zone', 'plan_name' => 'Basic', 'status' => 'active',
                     'billing_status' => 'paid', 'billing_cycle' => 'monthly', 'created_at' => '2025-01-01'],
                ],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $org  = Organization::factory()->withGlassBillingId('gb_cust_svc')->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/services');

        $response->assertStatus(200);
        $response->assertSeeText('DNS Zone');
    }

    public function test_portal_support_shows_customer_context(): void
    {
        $org  = Organization::factory()->create(['billing_email' => 'acme@test.test']);
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/support');

        $response->assertStatus(200);
        $response->assertSeeText($org->name);
        $response->assertSeeText('not linked');
    }

    public function test_portal_support_shows_billing_linked_state(): void
    {
        $org  = Organization::factory()->withGlassBillingId('gb_cust_supp')->create();
        $user = $this->customerUser($org);

        $response = $this->actingAs($user)->get('/portal/support');

        $response->assertStatus(200);
        $response->assertSeeText('linked');
        $response->assertSeeText('gb_cust_supp');
    }

    public function test_portal_routes_require_authentication(): void
    {
        $this->get('/portal')->assertRedirect('/login');
        $this->get('/portal/services')->assertRedirect('/login');
        $this->get('/portal/support')->assertRedirect('/login');
    }

    public function test_staff_cannot_access_portal_routes(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Admin->value]);

        $this->actingAs($staff)->get('/portal')->assertForbidden();
    }

    public function test_portal_dashboard_degrades_gracefully_when_glassbilling_offline(): void
    {
        Http::fake([
            'billing.test/*' => Http::response([], 503),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $org  = Organization::factory()->withGlassBillingId('gb_cust_down')->create();
        $user = $this->customerUser($org);
        \App\Models\BillingCustomer::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertStatus(200);
    }
}
