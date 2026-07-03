<?php

namespace Tests\Feature\Commercial;

use App\Enums\UserRole;
use App\Models\BillingServiceEntitlement;
use App\Models\ProvisioningRequest;
use App\Models\User;
use App\Services\Provisioning\ProvisioningRequestService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 29D — boundary enforcement. Verifies the safety boundaries in
 * docs/architecture/commercial-v1-decision.md: role walls between customer and
 * staff surfaces, no self-service admin creation, no infrastructure execution
 * from provisioning transitions, and no direct billing mutation by customers.
 */
class BoundaryEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role->value]);
    }

    // -------------------------------------------------------------------------
    // Role walls
    // -------------------------------------------------------------------------

    public function test_customers_cannot_reach_staff_admin_surfaces(): void
    {
        $customer = $this->user(UserRole::Customer);

        foreach ([
            '/admin',
            '/admin/billing',
            '/admin/billing/customers',
            '/admin/billing/entitlements',
            '/admin/provisioning/requests',
            '/admin/billing/change-requests',
        ] as $uri) {
            $this->actingAs($customer)->get($uri)->assertForbidden();
        }
    }

    public function test_staff_and_support_cannot_reach_owner_admin_billing(): void
    {
        // The billing group narrows the staff group to owner/admin.
        foreach ([UserRole::Staff, UserRole::Support] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/admin/billing')->assertForbidden();
            $this->actingAs($user)->get('/admin/provisioning/requests')->assertForbidden();
        }
    }

    public function test_staff_roles_cannot_reach_customer_portal(): void
    {
        foreach ([UserRole::Owner, UserRole::Admin, UserRole::Staff, UserRole::Support] as $role) {
            $this->actingAs($this->user($role))->get('/portal/billing')->assertForbidden();
        }
    }

    public function test_guests_are_redirected_from_both_surfaces(): void
    {
        $this->get('/admin/billing')->assertRedirect('/login');
        $this->get('/portal/billing')->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // No self-service admin creation
    // -------------------------------------------------------------------------

    public function test_no_public_admin_registration_route_exists(): void
    {
        $routes = app('router')->getRoutes();

        foreach ($routes as $route) {
            $uri = $route->uri();
            $this->assertStringNotContainsString('admin/register', $uri, "Unexpected admin registration route: {$uri}");
        }

        // Admin creation is CLI-only.
        $this->assertArrayHasKey(
            'glassportal:create-admin',
            \Illuminate\Support\Facades\Artisan::all(),
        );
    }

    // -------------------------------------------------------------------------
    // Provisioning never executes infrastructure
    // -------------------------------------------------------------------------

    public function test_provisioning_lifecycle_transitions_make_no_network_calls(): void
    {
        Http::fake(); // any HTTP request would be recorded

        $admin       = $this->user(UserRole::Admin);
        $entitlement = BillingServiceEntitlement::factory()->create();
        $service     = app(ProvisioningRequestService::class);

        $result  = $service->createFromEntitlement($entitlement, ProvisioningRequest::ACTION_PROVISION);
        $request = $result->request;
        $this->assertNotNull($request);

        $service->approve($request->fresh(), $admin);
        $service->queue($request->fresh(), null, $admin);
        $service->start($request->fresh(), null, $admin);
        $service->complete($request->fresh(), [], null, $admin);

        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $request->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_no_provider_execution_clients_ship_in_the_portal(): void
    {
        // Guard against reintroduction of execution code without approval.
        $this->assertDirectoryDoesNotExist(app_path('Services/GlassPanel'));
        $this->assertFileDoesNotExist(app_path('Services/Provisioning/GlassPanelExecutor.php'));
        $this->assertFileDoesNotExist(app_path('Services/Provisioning/ProxmoxClient.php'));
        $this->assertFalse(
            class_exists('App\\Services\\Provisioning\\ProvisioningExecutor'),
            'A provisioning executor class exists — execution is approval-gated and out of commercial v1 scope',
        );
    }

    // -------------------------------------------------------------------------
    // Customers cannot mutate billing state directly
    // -------------------------------------------------------------------------

    public function test_customer_cannot_hit_admin_billing_mutation_endpoints(): void
    {
        $customer    = $this->user(UserRole::Customer);
        $entitlement = BillingServiceEntitlement::factory()->create(['status' => 'active']);

        $this->actingAs($customer)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/billing/entitlements/{$entitlement->id}/suspend")
            ->assertForbidden();

        $this->assertSame('active', $entitlement->fresh()->status);
    }

    public function test_customer_change_requests_are_workflow_records_not_mutations(): void
    {
        $customer = $this->user(UserRole::Customer);

        Http::fake();

        $this->actingAs($customer)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/portal/billing/change-requests', [
                'type'    => 'cancel',
                'subject' => 'Please cancel my plan',
                'message' => 'I would like to cancel at period end.',
            ]);

        // Whatever validation shape applies, no Stripe call and no entitlement
        // mutation may result from a customer-submitted change request.
        Http::assertNothingSent();
        $this->assertSame(0, BillingServiceEntitlement::where('status', 'cancelled')->count());
    }
}
