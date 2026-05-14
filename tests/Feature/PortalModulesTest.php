<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalModulesTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    public function test_portal_modules_renders_for_customer_without_org(): void
    {
        $user     = $this->customerUser();
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('My Modules');
    }

    public function test_portal_modules_shows_all_registry_entries(): void
    {
        $user     = $this->customerUser();
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        // All launch modules from registry should appear
        foreach (array_keys(config('glasshouse.launch_modules', [])) as $key) {
            $response->assertSee($key);
        }
    }

    public function test_portal_modules_shows_not_linked_for_unlinked_org(): void
    {
        $org      = Organization::factory()->create();
        $user     = $this->customerUser($org);
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('not linked');
    }

    public function test_portal_modules_shows_launch_button_for_linked_module(): void
    {
        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()->withLaunchUrl('https://panel.test')->forModule('glasspanel', 'GlassPanel')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $user     = $this->customerUser($org);
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSee('https://panel.test');
        $response->assertSeeText('Launch');
    }

    public function test_portal_modules_shows_setup_required_for_sso_link(): void
    {
        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()->ssoMode('signed_launch')->forModule('aria', 'Aria')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $user     = $this->customerUser($org);
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Setup required');
    }

    public function test_portal_modules_shows_sso_future_warning(): void
    {
        $org  = Organization::factory()->create();
        OrganizationModuleLink::factory()->ssoMode('oauth')->forModule('glasspanel', 'GlassPanel')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $user     = $this->customerUser($org);
        $response = $this->actingAs($user)->get('/portal/modules');

        $response->assertStatus(200);
        // Should mention Phase 7 or not yet implemented
        $response->assertSee('Phase 7', false);
    }

    public function test_portal_modules_requires_authentication(): void
    {
        $this->get('/portal/modules')->assertRedirect('/login');
    }

    public function test_staff_cannot_access_portal_modules(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($staff)->get('/portal/modules')->assertForbidden();
    }
}
