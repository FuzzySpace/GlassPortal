<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminModulesTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);
    }

    public function test_admin_modules_renders(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/modules');

        $response->assertStatus(200);
        $response->assertSeeText('Ecosystem Modules');
        $response->assertSeeText('Connector Registry');
        $response->assertSeeText('Customer Launch Registry');
    }

    public function test_admin_modules_lists_all_connector_modules(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/modules');

        $response->assertStatus(200);
        foreach (array_keys(config('glasshouse.modules', [])) as $key) {
            $response->assertSee($key);
        }
    }

    public function test_admin_modules_lists_all_launch_modules(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/modules');

        $response->assertStatus(200);
        foreach (array_keys(config('glasshouse.launch_modules', [])) as $key) {
            $response->assertSee($key);
        }
    }

    public function test_admin_modules_shows_link_counts(): void
    {
        $org = Organization::factory()->create();
        OrganizationModuleLink::factory()->forModule('dns', 'DNS')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $response = $this->actingAs($this->staffUser())->get('/admin/modules');

        $response->assertStatus(200);
        $response->assertSeeText('1');
    }

    public function test_admin_module_links_renders(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/module-links');

        $response->assertStatus(200);
        $response->assertSeeText('Organization Module Links');
    }

    public function test_admin_module_links_shows_empty_state(): void
    {
        $response = $this->actingAs($this->staffUser())->get('/admin/module-links');

        $response->assertStatus(200);
        $response->assertSeeText('No module links recorded yet');
    }

    public function test_admin_module_links_shows_existing_links(): void
    {
        $org  = Organization::factory()->create(['name' => 'Acme Corp']);
        OrganizationModuleLink::factory()->withLaunchUrl()->forModule('glasspanel', 'GlassPanel')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $response = $this->actingAs($this->staffUser())->get('/admin/module-links');

        $response->assertStatus(200);
        $response->assertSeeText('Acme Corp');
        $response->assertSeeText('glasspanel');
    }

    public function test_admin_module_links_filters_by_module_key(): void
    {
        $dnsOrg  = Organization::factory()->create(['name' => 'DnsOrg']);
        $mailOrg = Organization::factory()->create(['name' => 'MailOrgUnique']);
        OrganizationModuleLink::factory()->forModule('dns', 'DNS')->create(['organization_id' => $dnsOrg->id]);
        OrganizationModuleLink::factory()->forModule('mail', 'Mail')->create(['organization_id' => $mailOrg->id]);

        $response = $this->actingAs($this->staffUser())->get('/admin/module-links?module_key=dns');

        $response->assertStatus(200);
        $response->assertSeeText('DnsOrg');
        $response->assertDontSeeText('MailOrgUnique');
    }

    public function test_admin_module_links_shows_sso_future_label(): void
    {
        $org = Organization::factory()->create();
        OrganizationModuleLink::factory()->ssoMode('oauth')->create(['organization_id' => $org->id]);

        $response = $this->actingAs($this->staffUser())->get('/admin/module-links');

        $response->assertStatus(200);
        $response->assertSeeText('Phase 7+');
    }

    public function test_admin_module_links_requires_authentication(): void
    {
        $this->get('/admin/module-links')->assertRedirect('/login');
    }

    public function test_customer_cannot_access_admin_module_links(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer->value]);
        $this->actingAs($customer)->get('/admin/module-links')->assertForbidden();
    }
}
