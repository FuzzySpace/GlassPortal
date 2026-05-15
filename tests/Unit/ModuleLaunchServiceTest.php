<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Services\ModuleLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleLaunchServiceTest extends TestCase
{
    use RefreshDatabase;

    private ModuleLaunchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ModuleLaunchService();
    }

    public function test_returns_launch_url_for_standalone_mode(): void
    {
        $link = OrganizationModuleLink::factory()->withLaunchUrl('https://panel.example.test')->create([
            'module_key'   => 'glasspanel',
            'display_name' => 'GlassPanel',
            'auth_mode'    => 'standalone',
            'status'       => 'active',
        ]);

        $data = $this->service->getLaunchData($link);

        $this->assertSame('https://panel.example.test', $data['launch_url']);
        $this->assertFalse($data['setup_required']);
        $this->assertEmpty($data['warnings']);
    }

    public function test_returns_no_launch_url_for_sso_modes(): void
    {
        foreach (['shared_session', 'signed_launch', 'oauth'] as $mode) {
            $link = OrganizationModuleLink::factory()->ssoMode($mode)->create([
                'status' => 'active',
            ]);

            $data = $this->service->getLaunchData($link);

            $this->assertNull($data['launch_url'], "Expected null launch_url for auth_mode={$mode}");
            $this->assertTrue($data['setup_required']);
            $this->assertNotEmpty($data['warnings']);
        }
    }

    public function test_returns_no_launch_url_when_inactive(): void
    {
        $link = OrganizationModuleLink::factory()->withLaunchUrl()->inactive()->create();

        $data = $this->service->getLaunchData($link);

        $this->assertNull($data['launch_url']);
        $this->assertNotEmpty($data['warnings']);
    }

    public function test_setup_required_when_no_external_url_for_standalone(): void
    {
        $link = OrganizationModuleLink::factory()->create([
            'auth_mode'    => 'standalone',
            'external_url' => null,
            'status'       => 'active',
        ]);

        $data = $this->service->getLaunchData($link);

        $this->assertNull($data['launch_url']);
        $this->assertTrue($data['setup_required']);
    }

    public function test_local_mode_with_no_url_is_not_setup_required(): void
    {
        $link = OrganizationModuleLink::factory()->create([
            'auth_mode'    => 'local',
            'external_url' => null,
            'status'       => 'active',
        ]);

        $data = $this->service->getLaunchData($link);

        $this->assertNull($data['launch_url']);
        $this->assertFalse($data['setup_required']);
    }

    public function test_get_launch_data_for_all_returns_all_links(): void
    {
        $org   = Organization::factory()->create();
        $links = OrganizationModuleLink::factory()->count(3)->create(['organization_id' => $org->id]);

        $results = $this->service->getLaunchDataForAll($links);

        $this->assertCount(3, $results);
        foreach ($results as $r) {
            $this->assertArrayHasKey('module_key', $r);
            $this->assertArrayHasKey('launch_url', $r);
            $this->assertArrayHasKey('setup_required', $r);
            $this->assertArrayHasKey('warnings', $r);
        }
    }

    public function test_merge_with_registry_fills_all_registered_modules(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->withLaunchUrl()->forModule('dns', 'DNS')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $merged = $this->service->mergeWithRegistry([$link]);

        // Every key from the launch_modules config should be present
        $registryKeys = array_keys(config('glasshouse.launch_modules', []));
        foreach ($registryKeys as $key) {
            $this->assertArrayHasKey($key, $merged, "Missing module key: {$key}");
        }

        // The linked module should have its real data
        $this->assertSame('active', $merged['dns']['status']);
        $this->assertSame('https://module.example.test', $merged['dns']['launch_url']);
    }

    public function test_merge_with_registry_shows_not_linked_for_unlinked(): void
    {
        $merged = $this->service->mergeWithRegistry([]);

        foreach ($merged as $key => $data) {
            $this->assertSame('not_linked', $data['status'], "Expected not_linked for {$key}");
            $this->assertNull($data['launch_url']);
            $this->assertTrue($data['setup_required']);
        }
    }

    public function test_is_sso_mode_returns_true_for_sso_auth_modes(): void
    {
        foreach (['shared_session', 'signed_launch', 'oauth'] as $mode) {
            $link = OrganizationModuleLink::factory()->ssoMode($mode)->create();
            $this->assertTrue($link->isSsoMode());
        }
    }

    public function test_is_sso_mode_returns_false_for_non_sso_modes(): void
    {
        foreach (['local', 'standalone', 'api_token'] as $mode) {
            $link = OrganizationModuleLink::factory()->create(['auth_mode' => $mode]);
            $this->assertFalse($link->isSsoMode());
        }
    }
}
