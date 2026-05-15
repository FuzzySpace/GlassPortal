<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // All admin routes should render without GlassBilling configured
        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
        ]);
    }

    public function test_admin_dashboard_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin');

        $response->assertStatus(200);
        $response->assertSeeText('Dashboard');
    }

    public function test_admin_services_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/services');

        $response->assertStatus(200);
        $response->assertSeeText('Customer Services');
    }

    public function test_admin_provisioning_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/provisioning');

        $response->assertStatus(200);
        $response->assertSeeText('Provisioning Requests');
    }

    public function test_admin_billing_approvals_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/billing-approvals');

        $response->assertStatus(200);
        $response->assertSeeText('Invoice Approvals');
    }

    public function test_admin_service_detail_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/services/svc-123');

        $response->assertStatus(200);
        $response->assertSeeText('Service Detail');
    }

    public function test_admin_provisioning_detail_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/provisioning/req-456');

        $response->assertStatus(200);
        $response->assertSeeText('Provisioning Request');
    }

    public function test_admin_billing_approval_detail_renders_without_glassbilling(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/billing-approvals/apv-789');

        $response->assertStatus(200);
        $response->assertSeeText('Invoice Approval');
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/services')->assertRedirect('/login');
        $this->get('/admin/provisioning')->assertRedirect('/login');
    }

    public function test_customer_cannot_access_admin_routes(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer->value]);

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_admin_customer_detail_renders_without_glassbilling(): void
    {
        $org = Organization::factory()->create();

        $response = $this->actingAs($this->staffUser())->get('/admin/customers/' . $org->id);

        $response->assertStatus(200);
        $response->assertSeeText($org->name);
        $response->assertSeeText('no GlassBilling link');
    }

    public function test_admin_customer_detail_shows_linked_state(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customers/gb_cust_test' => Http::response([
                'id' => 'gb_cust_test', 'name' => 'Acme Corp', 'email' => 'billing@acme.test', 'status' => 'active',
            ], 200),
            'billing.test/api/v1/admin/customer-services*'      => Http::response(['data' => [], 'meta' => []], 200),
            'billing.test/api/v1/admin/provisioning-requests*'  => Http::response(['data' => [], 'meta' => []], 200),
            'billing.test/api/v1/admin/invoice-approvals*'      => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $org = Organization::factory()->withGlassBillingId('gb_cust_test')->create();

        $response = $this->actingAs($this->staffUser())->get('/admin/customers/' . $org->id);

        $response->assertStatus(200);
        $response->assertSeeText('Acme Corp');
    }

    public function test_admin_customer_detail_returns_404_for_unknown_org(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/customers/99999');

        $response->assertStatus(404);
    }

    public function test_admin_dashboard_shows_real_data_when_glassbilling_online(): void
    {
        Http::fake([
            'billing.test/api/health'                    => Http::response(['status' => 'ok', 'version' => '2.0'], 200),
            'billing.test/api/v1/admin/dashboard-tiles'  => Http::response([
                ['label' => 'Active Subscriptions', 'value' => '42', 'sub' => 'total'],
            ], 200),
            'billing.test/api/v1/admin/customer-services*'   => Http::response(['data' => [], 'meta' => ['total' => 10]], 200),
            'billing.test/api/v1/admin/provisioning-requests*' => Http::response(['data' => [], 'meta' => ['total' => 3]], 200),
            'billing.test/api/v1/admin/invoice-approvals*'   => Http::response(['data' => [], 'meta' => ['total' => 1]], 200),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $response = $this->actingAs($this->staffUser())->get('/admin');

        $response->assertStatus(200);
        $response->assertSeeText('Active Subscriptions');
    }
}
