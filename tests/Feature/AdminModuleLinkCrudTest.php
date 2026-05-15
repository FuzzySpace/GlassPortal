<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationModuleLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class AdminModuleLinkCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function customerUser(): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value]);
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_index_renders(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/module-links')
            ->assertStatus(200)
            ->assertSeeText('Organization Module Links')
            ->assertSeeText('Create Link');
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/admin/module-links')->assertRedirect('/login');
    }

    public function test_customer_cannot_access_index(): void
    {
        $this->actingAs($this->customerUser())
            ->get('/admin/module-links')
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Create form
    // -------------------------------------------------------------------------

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/module-links/create')
            ->assertStatus(200)
            ->assertSeeText('Create Module Link');
    }

    public function test_create_form_requires_authentication(): void
    {
        $this->get('/admin/module-links/create')->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_store_creates_module_link(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->adminUser())
            ->post('/admin/module-links', [
                'organization_id' => $org->id,
                'module_key'      => 'dns',
                'display_name'    => 'DNS Manager',
                'auth_mode'       => 'standalone',
                'status'          => 'active',
                'external_url'    => 'https://dns.example.test',
            ])
            ->assertRedirect('/admin/module-links');

        $this->assertDatabaseHas('organization_module_links', [
            'organization_id' => $org->id,
            'module_key'      => 'dns',
            'display_name'    => 'DNS Manager',
            'external_url'    => 'https://dns.example.test',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->adminUser())
            ->post('/admin/module-links', [])
            ->assertSessionHasErrors(['organization_id', 'module_key', 'display_name', 'auth_mode', 'status']);
    }

    public function test_store_validates_module_key_against_registry(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->adminUser())
            ->post('/admin/module-links', [
                'organization_id' => $org->id,
                'module_key'      => 'not_a_real_module',
                'display_name'    => 'Fake',
                'auth_mode'       => 'standalone',
                'status'          => 'active',
            ])
            ->assertSessionHasErrors(['module_key']);
    }

    public function test_store_validates_auth_mode(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->adminUser())
            ->post('/admin/module-links', [
                'organization_id' => $org->id,
                'module_key'      => 'dns',
                'display_name'    => 'DNS',
                'auth_mode'       => 'invalid_mode',
                'status'          => 'active',
            ])
            ->assertSessionHasErrors(['auth_mode']);
    }

    public function test_store_validates_external_url_format(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->adminUser())
            ->post('/admin/module-links', [
                'organization_id' => $org->id,
                'module_key'      => 'dns',
                'display_name'    => 'DNS',
                'auth_mode'       => 'standalone',
                'status'          => 'active',
                'external_url'    => 'not-a-url',
            ])
            ->assertSessionHasErrors(['external_url']);
    }

    public function test_customer_cannot_store_module_link(): void
    {
        $org = Organization::factory()->create();

        $this->actingAs($this->customerUser())
            ->post('/admin/module-links', [
                'organization_id' => $org->id,
                'module_key'      => 'dns',
                'display_name'    => 'DNS',
                'auth_mode'       => 'standalone',
                'status'          => 'active',
            ])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Edit form
    // -------------------------------------------------------------------------

    public function test_edit_form_renders(): void
    {
        $link = OrganizationModuleLink::factory()->forModule('dns', 'DNS')->create();

        $this->actingAs($this->adminUser())
            ->get("/admin/module-links/{$link->id}/edit")
            ->assertStatus(200)
            ->assertSeeText('Edit Module Link')
            ->assertSee('dns');
    }

    public function test_edit_form_requires_authentication(): void
    {
        $link = OrganizationModuleLink::factory()->create();
        $this->get("/admin/module-links/{$link->id}/edit")->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_update_saves_changes(): void
    {
        $org  = Organization::factory()->create();
        $link = OrganizationModuleLink::factory()->forModule('dns', 'DNS')->create([
            'organization_id' => $org->id,
            'status'          => 'active',
        ]);

        $this->actingAs($this->adminUser())
            ->patch("/admin/module-links/{$link->id}", [
                'organization_id' => $org->id,
                'module_key'      => 'dns',
                'display_name'    => 'Updated DNS',
                'auth_mode'       => 'standalone',
                'status'          => 'inactive',
            ])
            ->assertRedirect('/admin/module-links');

        $this->assertDatabaseHas('organization_module_links', [
            'id'           => $link->id,
            'display_name' => 'Updated DNS',
            'status'       => 'inactive',
        ]);
    }

    public function test_update_validates_required_fields(): void
    {
        $link = OrganizationModuleLink::factory()->create();

        $this->actingAs($this->adminUser())
            ->patch("/admin/module-links/{$link->id}", [])
            ->assertSessionHasErrors(['organization_id', 'module_key', 'display_name', 'auth_mode', 'status']);
    }

    // -------------------------------------------------------------------------
    // Destroy (soft delete)
    // -------------------------------------------------------------------------

    public function test_destroy_soft_deletes_link(): void
    {
        $link = OrganizationModuleLink::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete("/admin/module-links/{$link->id}")
            ->assertRedirect('/admin/module-links');

        // Record still exists (soft-deleted)
        $this->assertSoftDeleted('organization_module_links', ['id' => $link->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $link = OrganizationModuleLink::factory()->create();
        $this->delete("/admin/module-links/{$link->id}")->assertRedirect('/login');
    }

    public function test_customer_cannot_destroy_module_link(): void
    {
        $link = OrganizationModuleLink::factory()->create();
        $this->actingAs($this->customerUser())
            ->delete("/admin/module-links/{$link->id}")
            ->assertForbidden();
    }

    public function test_disabled_link_does_not_appear_in_default_index(): void
    {
        $org   = Organization::factory()->create(['name' => 'SoftDeleteOrg']);
        $link  = OrganizationModuleLink::factory()->forModule('dns', 'DNS')->create([
            'organization_id' => $org->id,
        ]);

        // Soft-delete it
        $link->delete();

        $response = $this->actingAs($this->adminUser())->get('/admin/module-links');

        $response->assertStatus(200);
        $response->assertDontSeeText('SoftDeleteOrg');
    }
}
