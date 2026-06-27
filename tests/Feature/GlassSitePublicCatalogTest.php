<?php

namespace Tests\Feature;

use App\Models\PublicProductCatalogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 — GlassSite public product catalog (unauthenticated front door).
 */
class GlassSitePublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Public index
    // -------------------------------------------------------------------------

    public function test_products_index_renders_without_auth(): void
    {
        $this->get('/products')->assertStatus(200)->assertSeeText('Products');
    }

    public function test_products_index_shows_only_published_entries(): void
    {
        PublicProductCatalogEntry::factory()->published()->create(['title' => 'Visible Hosting Plan']);
        PublicProductCatalogEntry::factory()->unpublished()->create(['title' => 'Hidden Draft Plan']);
        // Public flag on but no publish date → not yet published.
        PublicProductCatalogEntry::factory()->create([
            'title'        => 'Private Pending Plan',
            'is_public'    => true,
            'published_at' => null,
        ]);
        // Future-dated publish → not yet visible.
        PublicProductCatalogEntry::factory()->create([
            'title'        => 'Future Scheduled Plan',
            'is_public'    => true,
            'published_at' => now()->addWeek(),
        ]);

        $response = $this->get('/products');

        $response->assertStatus(200);
        $response->assertSeeText('Visible Hosting Plan');
        $response->assertDontSeeText('Hidden Draft Plan');
        $response->assertDontSeeText('Private Pending Plan');
        $response->assertDontSeeText('Future Scheduled Plan');
    }

    public function test_products_index_shows_price_and_links_to_detail(): void
    {
        PublicProductCatalogEntry::factory()->published()->create([
            'title'                => 'Priced Plan',
            'slug'                 => 'priced-plan',
            'starting_price_cents' => 1500,
            'currency'             => 'USD',
            'billing_interval'     => 'monthly',
        ]);

        $response = $this->get('/products');

        $response->assertStatus(200);
        $response->assertSeeText('from $15.00/mo');
        $response->assertSee('/products/priced-plan');
    }

    // -------------------------------------------------------------------------
    // Public detail
    // -------------------------------------------------------------------------

    public function test_product_detail_renders_for_published_slug(): void
    {
        PublicProductCatalogEntry::factory()->published()->create([
            'title'             => 'Detail Plan',
            'slug'              => 'detail-plan',
            'short_description' => 'A short blurb.',
            'description'       => 'A longer description body.',
            'cta_url'           => 'https://glasshouse.example/signup',
            'cta_label'         => 'Start now',
            'docs_url'          => 'https://docs.example/plan',
        ]);

        $response = $this->get('/products/detail-plan');

        $response->assertStatus(200);
        $response->assertSeeText('Detail Plan');
        $response->assertSeeText('A short blurb.');
        $response->assertSeeText('A longer description body.');
        $response->assertSeeText('Start now');
        $response->assertSee('https://glasshouse.example/signup');
        $response->assertSee('https://docs.example/plan');
    }

    public function test_product_detail_404_for_unpublished_slug(): void
    {
        PublicProductCatalogEntry::factory()->unpublished()->create(['slug' => 'hidden-plan']);

        $this->get('/products/hidden-plan')->assertNotFound();
    }

    public function test_product_detail_404_for_private_slug(): void
    {
        PublicProductCatalogEntry::factory()->create([
            'slug'         => 'private-plan',
            'is_public'    => false,
            'published_at' => now()->subDay(),
        ]);

        $this->get('/products/private-plan')->assertNotFound();
    }

    public function test_product_detail_404_for_future_published_slug(): void
    {
        PublicProductCatalogEntry::factory()->create([
            'slug'         => 'future-plan',
            'is_public'    => true,
            'published_at' => now()->addWeek(),
        ]);

        $this->get('/products/future-plan')->assertNotFound();
    }

    public function test_product_detail_404_for_missing_slug(): void
    {
        $this->get('/products/does-not-exist')->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Security — never leak sensitive metadata
    // -------------------------------------------------------------------------

    public function test_public_pages_never_render_sensitive_metadata(): void
    {
        $secrets = [
            'api_token'      => 'LEAK_API_TOKEN_VALUE_2222',
            'secret'         => 'LEAK_SECRET_VALUE_3333',
            'password'       => 'LEAK_PASSWORD_VALUE_4444',
            'signing_secret' => 'LEAK_SIGNING_SECRET_5555',
        ];

        PublicProductCatalogEntry::factory()->published()->create([
            'title'    => 'Metadata Plan',
            'slug'     => 'metadata-plan',
            'metadata' => $secrets,
        ]);

        $index  = $this->get('/products')->getContent();
        $detail = $this->get('/products/metadata-plan')->getContent();

        foreach ($secrets as $value) {
            $this->assertStringNotContainsString($value, $index);
            $this->assertStringNotContainsString($value, $detail);
        }
    }

    // -------------------------------------------------------------------------
    // Homepage featured section
    // -------------------------------------------------------------------------

    public function test_homepage_shows_featured_published_products(): void
    {
        PublicProductCatalogEntry::factory()->published()->featured()->create(['title' => 'Featured Homepage Plan']);
        PublicProductCatalogEntry::factory()->published()->create(['title' => 'Unfeatured Plan']);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSeeText('Featured Homepage Plan');
    }

    public function test_homepage_renders_without_featured_products(): void
    {
        $this->get('/')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Healthcheck
    // -------------------------------------------------------------------------

    public function test_healthcheck_includes_glasssite_checks(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('glasssite.catalog_table')
            ->expectsOutputToContain('glasssite.public_routes')
            ->expectsOutputToContain('glasssite.admin_routes')
            ->assertExitCode(0);
    }
}
