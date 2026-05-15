<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalModuleLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function customerUser(?Organization $org = null): User
    {
        return User::factory()->create([
            'role'            => UserRole::Customer->value,
            'organization_id' => $org?->id,
        ]);
    }

    private function activeLink(Organization $org, string $url = 'https://module.test'): OrganizationModuleLink
    {
        return OrganizationModuleLink::factory()
            ->withLaunchUrl($url)
            ->forModule('dns', 'DNS')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
    }

    // -------------------------------------------------------------------------
    // Happy path — allowed redirect
    // -------------------------------------------------------------------------

    public function test_launch_redirects_for_active_standalone_module(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->activeLink($org, 'https://dns.example.test');
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('https://dns.example.test');
    }

    public function test_allowed_launch_creates_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = $this->activeLink($org);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'module_link_id'  => $link->id,
            'module_key'      => 'dns',
            'event_type'      => 'allowed',
        ]);
    }

    // -------------------------------------------------------------------------
    // Denied — wrong organization
    // -------------------------------------------------------------------------

    public function test_launch_denied_for_wrong_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $link = $this->activeLink($org1);
        $user = $this->customerUser($org2);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertForbidden();
    }

    public function test_wrong_org_launch_does_not_create_audit_event(): void
    {
        // The HTTP-layer 403 fires before the service records anything
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $link = $this->activeLink($org1);
        $user = $this->customerUser($org2);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseCount('module_launch_events', 0);
    }

    // -------------------------------------------------------------------------
    // Denied — inactive link
    // -------------------------------------------------------------------------

    public function test_launch_denied_for_inactive_link(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl()
            ->inactive()
            ->create(['organization_id' => $org->id]);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');
    }

    public function test_inactive_link_denial_creates_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->withLaunchUrl()
            ->inactive()
            ->create(['organization_id' => $org->id]);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'module_link_id'  => $link->id,
            'event_type'      => 'denied',
        ]);
    }

    // -------------------------------------------------------------------------
    // Stubbed — SSO modes
    // -------------------------------------------------------------------------

    public function test_sso_launch_returns_stub_page(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->ssoMode('signed_launch')
            ->forModule('glasspanel', 'GlassPanel')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertStatus(200)
            ->assertSeeText('Single Sign-On Coming Soon');
    }

    public function test_sso_launch_creates_stubbed_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()
            ->ssoMode('oauth')
            ->forModule('aria', 'Aria')
            ->create(['organization_id' => $org->id, 'status' => 'active']);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'module_link_id'  => $link->id,
            'event_type'      => 'stubbed',
        ]);
    }

    public function test_all_sso_modes_return_stub_page(): void
    {
        $org = Organization::factory()->create();
        $user = $this->customerUser($org);

        foreach (['shared_session', 'signed_launch', 'oauth'] as $mode) {
            $link = OrganizationModuleLink::factory()
                ->ssoMode($mode)
                ->forModule('dns', 'DNS')
                ->create(['organization_id' => $org->id, 'status' => 'active']);

            $this->actingAs($user)
                ->get("/portal/modules/{$link->id}/launch")
                ->assertStatus(200)
                ->assertSeeText('Single Sign-On Coming Soon');
        }
    }

    // -------------------------------------------------------------------------
    // Denied — no external URL
    // -------------------------------------------------------------------------

    public function test_launch_denied_when_no_external_url(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'standalone',
            'external_url'    => null,
            'status'          => 'active',
        ]);
        $user = $this->customerUser($org);

        $this->actingAs($user)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertRedirect('/portal/modules');
    }

    public function test_no_url_denial_creates_audit_event(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->create([
            'organization_id' => $org->id,
            'auth_mode'       => 'standalone',
            'external_url'    => null,
            'status'          => 'active',
        ]);
        $user = $this->customerUser($org);

        $this->actingAs($user)->get("/portal/modules/{$link->id}/launch");

        $this->assertDatabaseHas('module_launch_events', [
            'event_type' => 'denied',
            'user_id'    => $user->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth & role guards
    // -------------------------------------------------------------------------

    public function test_launch_requires_authentication(): void
    {
        $link = OrganizationModuleLink::factory()->create();
        $this->get("/portal/modules/{$link->id}/launch")->assertRedirect('/login');
    }

    public function test_staff_cannot_access_portal_launch(): void
    {
        $link  = OrganizationModuleLink::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::Admin->value]);

        $this->actingAs($staff)
            ->get("/portal/modules/{$link->id}/launch")
            ->assertForbidden();
    }
}
