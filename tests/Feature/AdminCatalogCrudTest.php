<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PublicProductCatalogEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 — Admin management of GlassSite catalog entries (owner/admin only).
 */
class AdminCatalogCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function ownerUser(): User
    {
        return User::factory()->create(['role' => UserRole::Owner->value]);
    }

    private function staffUser(): User
    {
        return User::factory()->create(['role' => UserRole::Staff->value]);
    }

    private function customerUser(): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title'                => 'New Catalog Product',
            'short_description'    => 'Short blurb',
            'description'          => 'Longer body',
            'category'             => 'Hosting',
            'starting_price_cents' => 1999,
            'currency'             => 'usd',
            'billing_interval'     => 'monthly',
            'cta_label'            => 'Sign up',
            'cta_url'              => 'https://glasshouse.example/signup',
            'sort_order'           => 5,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_admin_can_view_index(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/site/catalog')
            ->assertStatus(200)
            ->assertSeeText('Product Catalog');
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_admin_can_create_entry(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/site/catalog', $this->validPayload())
            ->assertRedirect('/admin/site/catalog');

        $this->assertDatabaseHas('public_product_catalog_entries', [
            'title'    => 'New Catalog Product',
            'slug'     => 'new-catalog-product',
            'currency' => 'USD', // normalized to uppercase
        ]);
    }

    public function test_create_requires_title(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/site/catalog', $this->validPayload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    public function test_create_derives_slug_from_title_when_blank(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/site/catalog', $this->validPayload(['title' => 'Auto Slug Plan!']));

        $this->assertDatabaseHas('public_product_catalog_entries', ['slug' => 'auto-slug-plan']);
    }

    public function test_publishing_on_create_stamps_published_at(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/site/catalog', $this->validPayload(['title' => 'Live Plan', 'is_public' => '1']));

        $entry = PublicProductCatalogEntry::where('slug', 'live-plan')->first();
        $this->assertNotNull($entry);
        $this->assertTrue($entry->is_public);
        $this->assertNotNull($entry->published_at);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_admin_can_update_entry(): void
    {
        $entry = PublicProductCatalogEntry::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin())
            ->patch("/admin/site/catalog/{$entry->id}", $this->validPayload([
                'title' => 'Updated Title',
                'slug'  => $entry->slug,
            ]))
            ->assertRedirect('/admin/site/catalog');

        $this->assertDatabaseHas('public_product_catalog_entries', [
            'id'    => $entry->id,
            'title' => 'Updated Title',
        ]);
    }

    // -------------------------------------------------------------------------
    // Publish / feature toggles
    // -------------------------------------------------------------------------

    public function test_admin_can_publish_and_unpublish(): void
    {
        $entry = PublicProductCatalogEntry::factory()->unpublished()->create();

        $this->actingAs($this->admin())->post("/admin/site/catalog/{$entry->id}/publish");
        $entry->refresh();
        $this->assertTrue($entry->is_public);
        $this->assertNotNull($entry->published_at);

        $this->actingAs($this->admin())->post("/admin/site/catalog/{$entry->id}/publish");
        $entry->refresh();
        $this->assertFalse($entry->is_public);
    }

    public function test_admin_can_toggle_featured(): void
    {
        $entry = PublicProductCatalogEntry::factory()->create(['featured' => false]);

        $this->actingAs($this->admin())->post("/admin/site/catalog/{$entry->id}/feature");

        $this->assertTrue($entry->refresh()->featured);
    }

    // -------------------------------------------------------------------------
    // Delete (soft)
    // -------------------------------------------------------------------------

    public function test_admin_can_soft_delete_entry(): void
    {
        $entry = PublicProductCatalogEntry::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/site/catalog/{$entry->id}")
            ->assertRedirect('/admin/site/catalog');

        $this->assertSoftDeleted('public_product_catalog_entries', ['id' => $entry->id]);
    }

    public function test_owner_can_manage_catalog(): void
    {
        $this->actingAs($this->ownerUser())
            ->get('/admin/site/catalog')
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function test_customer_cannot_access_catalog_admin(): void
    {
        $customer = $this->customerUser();

        $this->actingAs($customer)->get('/admin/site/catalog')->assertForbidden();
        $this->actingAs($customer)->post('/admin/site/catalog', $this->validPayload())->assertForbidden();
    }

    public function test_staff_cannot_access_catalog_admin(): void
    {
        // Owner/admin only — staff are in the surrounding group but blocked here.
        $staff = $this->staffUser();

        $this->actingAs($staff)->get('/admin/site/catalog')->assertForbidden();
        $this->actingAs($staff)->post('/admin/site/catalog', $this->validPayload())->assertForbidden();
    }

    public function test_guest_cannot_access_catalog_admin(): void
    {
        $this->get('/admin/site/catalog')->assertRedirect('/login');
    }

    public function test_customer_cannot_create_entry_in_db(): void
    {
        $this->actingAs($this->customerUser())
            ->post('/admin/site/catalog', $this->validPayload(['title' => 'Sneaky Entry']));

        $this->assertDatabaseMissing('public_product_catalog_entries', ['title' => 'Sneaky Entry']);
    }
}
