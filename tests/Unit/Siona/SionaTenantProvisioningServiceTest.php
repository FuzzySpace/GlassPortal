<?php

namespace Tests\Unit\Siona;

use App\Enums\UserRole;
use App\Models\ModuleLaunchEvent;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use App\Services\Siona\SionaTenantProvisioningResult;
use App\Services\Siona\SionaTenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SionaTenantProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SionaTenantProvisioningService
    {
        return app(SionaTenantProvisioningService::class);
    }

    private function actor(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function enableProvisioning(string $token = 'unit-token'): void
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

    // -------------------------------------------------------------------------

    public function test_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(SionaTenantProvisioningService::class, $this->service());
    }

    public function test_provisions_workspace_and_creates_link(): void
    {
        $this->enableProvisioning();
        Http::fake(['siona.test/*' => Http::response(['workspace_id' => 'ws-1'], 201)]);

        $org = Organization::factory()->create();

        $result = $this->service()->provisionForOrganization($org, $this->actor());

        $this->assertTrue($result->ok);
        $this->assertSame(SionaTenantProvisioningResult::OUTCOME_PROVISIONED, $result->outcome);
        $this->assertSame('ws-1', $result->workspaceId);
        $this->assertSame('ws-1', $org->fresh()->siona_workspace_id);

        $link = OrganizationModuleLink::where('organization_id', $org->id)->where('module_key', 'siona')->first();
        $this->assertNotNull($link);
        $this->assertSame('active', $link->status);
        $this->assertSame('ws-1', $link->external_account_id);
        $this->assertSame('https://siona.example.test/app', $link->external_url);

        foreach ([
            SionaTenantProvisioningService::EVENT_REQUESTED,
            SionaTenantProvisioningService::EVENT_LINK_CREATED,
            SionaTenantProvisioningService::EVENT_SUCCEEDED,
        ] as $eventType) {
            $this->assertDatabaseHas('module_launch_events', [
                'organization_id' => $org->id,
                'event_type'      => $eventType,
            ]);
        }
    }

    public function test_already_linked_is_a_noop(): void
    {
        $this->enableProvisioning();
        Http::fake(['siona.test/*' => Http::response(['workspace_id' => 'ws-should-not-be-used'], 201)]);

        $org = Organization::factory()->withSionaWorkspace('ws-existing')->create();
        OrganizationModuleLink::factory()->forModule('siona', 'SIONA')->create([
            'organization_id'     => $org->id,
            'status'              => 'active',
            'external_account_id' => 'ws-existing',
        ]);

        $result = $this->service()->provisionForOrganization($org, $this->actor());

        $this->assertTrue($result->ok);
        $this->assertSame(SionaTenantProvisioningResult::OUTCOME_ALREADY_LINKED, $result->outcome);
        $this->assertSame('ws-existing', $result->workspaceId);

        Http::assertNothingSent();
        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_ALREADY_LINKED,
        ]);
    }

    public function test_unconfigured_returns_failure_and_audits(): void
    {
        config([
            'siona.enabled'              => true,
            'siona.api_url'              => 'http://siona.test',
            'siona.api_token'            => 'tok',
            'siona.provisioning.enabled' => false, // feature off
        ]);
        Http::fake(['siona.test/*' => Http::response([], 201)]);

        $org = Organization::factory()->create();

        $result = $this->service()->provisionForOrganization($org, $this->actor());

        $this->assertFalse($result->ok);
        $this->assertSame(SionaTenantProvisioningResult::OUTCOME_UNCONFIGURED, $result->outcome);

        Http::assertNothingSent();
        $this->assertNull($org->fresh()->siona_workspace_id);
        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_FAILED,
            'reason'          => 'unconfigured',
        ]);
    }

    public function test_remote_failure_returns_failed_and_persists_nothing(): void
    {
        $this->enableProvisioning();
        Http::fake(['siona.test/*' => Http::response(['error' => 'boom'], 500)]);

        $org = Organization::factory()->create();

        $result = $this->service()->provisionForOrganization($org, $this->actor());

        $this->assertFalse($result->ok);
        $this->assertSame(SionaTenantProvisioningResult::OUTCOME_FAILED, $result->outcome);
        $this->assertNull($org->fresh()->siona_workspace_id);
        $this->assertSame(0, OrganizationModuleLink::where('organization_id', $org->id)->count());

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_FAILED,
        ]);
    }

    public function test_reuses_known_workspace_id_from_metadata_without_calling_siona(): void
    {
        $this->enableProvisioning();
        Http::fake(['siona.test/*' => Http::response(['workspace_id' => 'ws-from-remote'], 201)]);

        // Phase 19 leftover: workspace id only in link metadata, link inactive,
        // org column not yet populated.
        $org = Organization::factory()->create();
        OrganizationModuleLink::factory()->forModule('siona', 'SIONA')->create([
            'organization_id' => $org->id,
            'status'          => 'inactive',
            'metadata'        => ['siona_workspace_id' => 'ws-meta'],
        ]);

        $result = $this->service()->provisionForOrganization($org, $this->actor());

        $this->assertTrue($result->ok);
        $this->assertSame(SionaTenantProvisioningResult::OUTCOME_PROVISIONED, $result->outcome);
        $this->assertSame('ws-meta', $result->workspaceId);
        $this->assertSame('ws-meta', $org->fresh()->siona_workspace_id);

        // The known id was reused — no tenant-create call.
        Http::assertNothingSent();
        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_LINK_UPDATED,
        ]);
    }

    public function test_audit_metadata_never_contains_token(): void
    {
        $secret = 'unit-service-token-must-not-leak';
        $this->enableProvisioning($secret);
        Http::fake(['siona.test/*' => Http::response(['workspace_id' => 'ws-x'], 201)]);

        $org = Organization::factory()->create();

        $this->service()->provisionForOrganization($org, $this->actor());

        foreach (ModuleLaunchEvent::all() as $event) {
            $this->assertStringNotContainsString($secret, (string) json_encode($event->metadata));
        }
    }

    public function test_requested_event_recorded_even_when_already_linked(): void
    {
        $this->enableProvisioning();
        Http::fake(['siona.test/*' => Http::response([], 201)]);

        $org = Organization::factory()->withSionaWorkspace('ws-existing')->create();
        OrganizationModuleLink::factory()->forModule('siona', 'SIONA')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $this->service()->provisionForOrganization($org, $this->actor());

        $this->assertDatabaseHas('module_launch_events', [
            'organization_id' => $org->id,
            'event_type'      => SionaTenantProvisioningService::EVENT_REQUESTED,
        ]);
    }
}
