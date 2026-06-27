<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Siona\SionaTenantProvisioningService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SionaTenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Keep GlassBilling out of the picture for these tests.
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function adminUser(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function staffUser(): User
    {
        return User::factory()->create(['role' => UserRole::Staff->value]);
    }

    private function customerUser(): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value]);
    }

    private function enableProvisioning(string $token = 'siona-test-token'): void
    {
        config([
            'siona.enabled'              => true,
            'siona.api_url'              => 'http://siona.test',
            'siona.api_token'            => $token,
            'siona.launch_url'           => 'https://siona.example.test/app',
            'siona.provisioning.enabled' => true,
            'siona.provisioning.path'    => '/api/tenants',
        ]);
    }

    private function fakeTenantCreate(string $workspaceId = 'ws-new-123'): void
    {
        Http::fake(['siona.test/*' => Http::response(['workspace_id' => $workspaceId], 201)]);
    }

    private function provisionUrl(Organization $org): string
    {
        return "/admin/customers/{$org->id}/siona/provision";
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function test_admin_can_provision_siona_workspace(): void
    {
        $this->enableProvisioning();
        $this->fakeTenantCreate('ws-admin-1');

        $org = Organization::factory()->create();

        $response = $this->actingAs($this->adminUser())->post($this->provisionUrl($org));

        $response->assertRedirect(route('admin.customers.show', $org->id));
        $response->assertSessionHas('success');

        $this->assertSame('ws-admin-1', $org->fresh()->siona_workspace_id);
        $this->assertDatabaseHas('organization_module_links', [
            'organization_id'     => $org->id,
            'module_key'          => 'siona',
            'external_account_id' => 'ws-admin-1',
            'status'              => 'active',
        ]);
    }

    public function test_customer_cannot_provision(): void
    {
        $this->enableProvisioning();
        $this->fakeTenantCreate();

        $org = Organization::factory()->create();

        $this->actingAs($this->customerUser())
            ->post($this->provisionUrl($org))
            ->assertForbidden();

        $this->assertNull($org->fresh()->siona_workspace_id);
        Http::assertNothingSent();
    }

    public function test_staff_cannot_provision_admin_only_action(): void
    {
        $this->enableProvisioning();
        $this->fakeTenantCreate();

        $org = Organization::factory()->create();

        // Staff are in the surrounding admin group but the stacked role
        // middleware narrows this action to owner/admin only.
        $this->actingAs($this->staffUser())
            ->post($this->provisionUrl($org))
            ->assertForbidden();

        $this->assertNull($org->fresh()->siona_workspace_id);
        Http::assertNothingSent();
    }

    public function test_provision_requires_authentication(): void
    {
        $org = Organization::factory()->create();

        $this->post($this->provisionUrl($org))->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_provision_is_idempotent_when_already_linked(): void
    {
        $this->enableProvisioning();
        $this->fakeTenantCreate();

        $org = Organization::factory()->withSionaWorkspace('ws-existing')->create();
        OrganizationModuleLink::factory()->forModule('siona', 'SIONA')->create([
            'organization_id'     => $org->id,
            'status'              => 'active',
            'external_account_id' => 'ws-existing',
        ]);

        $response = $this->actingAs($this->adminUser())->post($this->provisionUrl($org));

        $response->assertRedirect(route('admin.customers.show', $org->id));
        $response->assertSessionHas('success');

        // No outbound provisioning call, no duplicate link, workspace unchanged.
        Http::assertNothingSent();
        $this->assertSame('ws-existing', $org->fresh()->siona_workspace_id);
        $this->assertSame(1, OrganizationModuleLink::where('organization_id', $org->id)->where('module_key', 'siona')->count());
        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_ALREADY_LINKED,
        ]);
    }

    // -------------------------------------------------------------------------
    // Missing config
    // -------------------------------------------------------------------------

    public function test_provision_with_missing_config_fails_gracefully(): void
    {
        // Provisioning feature disabled.
        config([
            'siona.enabled'              => true,
            'siona.api_url'              => 'http://siona.test',
            'siona.api_token'            => 'tok',
            'siona.provisioning.enabled' => false,
        ]);
        Http::fake(['siona.test/*' => Http::response([], 201)]);

        $org = Organization::factory()->create();

        $response = $this->actingAs($this->adminUser())->post($this->provisionUrl($org));

        $response->assertRedirect(route('admin.customers.show', $org->id));
        $response->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertNull($org->fresh()->siona_workspace_id);
        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_FAILED,
            'reason'          => 'unconfigured',
        ]);
    }

    // -------------------------------------------------------------------------
    // Module link creation / update
    // -------------------------------------------------------------------------

    public function test_provision_creates_module_link_and_records_event(): void
    {
        $this->enableProvisioning();
        $this->fakeTenantCreate('ws-create-1');

        $org = Organization::factory()->create();

        $this->actingAs($this->adminUser())->post($this->provisionUrl($org));

        $link = OrganizationModuleLink::where('organization_id', $org->id)->where('module_key', 'siona')->first();
        $this->assertNotNull($link);
        $this->assertSame('active', $link->status);
        $this->assertSame('ws-create-1', $link->external_account_id);
        $this->assertSame('ws-create-1', $link->metadata['siona_workspace_id']);

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_LINK_CREATED,
        ]);
        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_SUCCEEDED,
        ]);
    }

    public function test_provision_updates_existing_link_without_duplicating(): void
    {
        $this->enableProvisioning();
        $this->fakeTenantCreate('ws-update-1');

        // Existing inactive link, org has no workspace id yet (Phase 19 leftover).
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->forModule('siona', 'SIONA')->create([
            'organization_id'     => $org->id,
            'status'              => 'inactive',
            'external_account_id' => null,
        ]);

        $this->actingAs($this->adminUser())->post($this->provisionUrl($org));

        // Same link, now repaired to active and mapped.
        $this->assertSame(1, OrganizationModuleLink::where('organization_id', $org->id)->where('module_key', 'siona')->count());
        $fresh = $link->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('ws-update-1', $fresh->external_account_id);
        $this->assertSame('ws-update-1', $org->fresh()->siona_workspace_id);

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_LINK_UPDATED,
        ]);
        $this->assertDatabaseMissing('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_LINK_CREATED,
        ]);
    }

    // -------------------------------------------------------------------------
    // Token leakage guards
    // -------------------------------------------------------------------------

    public function test_provision_never_leaks_token_in_response_or_audit(): void
    {
        $secret = 'siona-provision-token-must-not-leak-phase20';
        $this->enableProvisioning($secret);
        $this->fakeTenantCreate('ws-secure-1');

        $org = Organization::factory()->create();

        $response = $this->actingAs($this->adminUser())
            ->followingRedirects()
            ->post($this->provisionUrl($org));

        $response->assertStatus(200);
        $this->assertStringNotContainsString($secret, $response->getContent());

        // Audit metadata must never carry the token.
        foreach (ModuleLaunchEvent::all() as $event) {
            $this->assertStringNotContainsString($secret, (string) json_encode($event->metadata));
            $this->assertStringNotContainsString($secret, (string) $event->reason);
        }
    }

    // -------------------------------------------------------------------------
    // Customer detail view surface
    // -------------------------------------------------------------------------

    public function test_customer_detail_shows_siona_section_and_button_for_admin(): void
    {
        $this->enableProvisioning();

        $org = Organization::factory()->create();

        $response = $this->actingAs($this->adminUser())->get(route('admin.customers.show', $org->id));

        $response->assertStatus(200);
        $response->assertSeeText('SIONA Workspace');
        $response->assertSeeText('Provision SIONA workspace');
    }

    public function test_customer_detail_hides_provision_button_for_staff(): void
    {
        $this->enableProvisioning();

        $org = Organization::factory()->create();

        $response = $this->actingAs($this->staffUser())->get(route('admin.customers.show', $org->id));

        $response->assertStatus(200);
        $response->assertSeeText('SIONA Workspace');
        $response->assertDontSeeText('Provision SIONA workspace');
    }

    // -------------------------------------------------------------------------
    // Healthcheck — Phase 20 checks present and non-blocking
    // -------------------------------------------------------------------------

    public function test_healthcheck_includes_phase20_siona_checks(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('siona.tenant_provisioning_config')
            ->expectsOutputToContain('siona.workspace_mapping_column')
            ->expectsOutputToContain('siona.backchannel_ready')
            ->assertExitCode(0);
    }

    public function test_healthcheck_exits_zero_when_provisioning_unconfigured(): void
    {
        config(['siona.provisioning.enabled' => false]);

        $this->artisan('glassportal:healthcheck')->assertExitCode(0);
    }
}
