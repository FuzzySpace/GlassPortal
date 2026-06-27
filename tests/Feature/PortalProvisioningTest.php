<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProvisioningRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 — customer portal provisioning visibility (read-only, org-scoped).
 */
class PortalProvisioningTest extends TestCase
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
        $this->get('/portal/provisioning')->assertRedirect('/login');
    }

    public function test_customer_sees_own_org_requests(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerForOrg($org);
        ProvisioningRequest::factory()->create([
            'organization_id' => $org->id,
            'service_type'    => 'my-hosting-service',
        ]);

        $this->actingAs($user)->get('/portal/provisioning')
            ->assertStatus(200)
            ->assertSeeText('my-hosting-service');
    }

    public function test_customer_cannot_see_other_org_requests(): void
    {
        $org      = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $user     = $this->customerForOrg($org);

        ProvisioningRequest::factory()->create([
            'organization_id' => $otherOrg->id,
            'service_type'    => 'other-org-service-xyz',
        ]);

        $this->actingAs($user)->get('/portal/provisioning')
            ->assertStatus(200)
            ->assertDontSeeText('other-org-service-xyz');
    }

    public function test_customer_without_org_sees_empty_state(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer->value, 'organization_id' => null]);

        $this->actingAs($user)->get('/portal/provisioning')
            ->assertStatus(200)
            ->assertSeeText('not linked to an organization');
    }

    public function test_customer_cannot_mutate_requests(): void
    {
        $org     = Organization::factory()->create();
        $user    = $this->customerForOrg($org);
        $request = ProvisioningRequest::factory()->create(['organization_id' => $org->id, 'status' => 'pending_approval']);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($user)
            ->post(route('admin.provisioning.requests.action', [$request, 'approve']))
            ->assertForbidden();

        $this->assertSame('pending_approval', $request->fresh()->status);
    }

    public function test_portal_never_renders_payload_secrets(): void
    {
        $org  = Organization::factory()->create();
        $user = $this->customerForOrg($org);
        ProvisioningRequest::factory()->create([
            'organization_id' => $org->id,
            'payload'         => ['api_token' => 'PORTAL_PROV_LEAK_TOKEN', 'secret' => 'PORTAL_PROV_LEAK_SECRET'],
        ]);

        $content = $this->actingAs($user)->get('/portal/provisioning')->getContent();

        $this->assertStringNotContainsString('PORTAL_PROV_LEAK_TOKEN', $content);
        $this->assertStringNotContainsString('PORTAL_PROV_LEAK_SECRET', $content);
    }
}
