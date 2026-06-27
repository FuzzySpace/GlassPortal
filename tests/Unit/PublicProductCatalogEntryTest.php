<?php

namespace Tests\Unit;

use App\Models\PublicProductCatalogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 — PublicProductCatalogEntry model: scopes, slug, serialization.
 */
class PublicProductCatalogEntryTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // scopePublished
    // -------------------------------------------------------------------------

    public function test_published_scope_includes_only_public_dated_past_entries(): void
    {
        $visible = PublicProductCatalogEntry::factory()->published()->create();
        PublicProductCatalogEntry::factory()->unpublished()->create();
        PublicProductCatalogEntry::factory()->create(['is_public' => true, 'published_at' => null]);
        PublicProductCatalogEntry::factory()->create(['is_public' => true, 'published_at' => now()->addDay()]);
        PublicProductCatalogEntry::factory()->create(['is_public' => false, 'published_at' => now()->subDay()]);

        $ids = PublicProductCatalogEntry::published()->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($visible->id));
    }

    // -------------------------------------------------------------------------
    // scopeOrdered
    // -------------------------------------------------------------------------

    public function test_ordered_scope_puts_featured_first_then_sort_order(): void
    {
        $a = PublicProductCatalogEntry::factory()->create(['title' => 'A', 'featured' => false, 'sort_order' => 1]);
        $b = PublicProductCatalogEntry::factory()->create(['title' => 'B', 'featured' => true,  'sort_order' => 9]);
        $c = PublicProductCatalogEntry::factory()->create(['title' => 'C', 'featured' => false, 'sort_order' => 0]);

        $order = PublicProductCatalogEntry::ordered()->pluck('id')->all();

        // Featured (B) first; then by sort_order ascending: C(0) before A(1).
        $this->assertSame([$b->id, $c->id, $a->id], $order);
    }

    public function test_featured_scope_filters_featured(): void
    {
        PublicProductCatalogEntry::factory()->featured()->create();
        PublicProductCatalogEntry::factory()->create(['featured' => false]);

        $this->assertCount(1, PublicProductCatalogEntry::featured()->get());
    }

    // -------------------------------------------------------------------------
    // Slug normalization
    // -------------------------------------------------------------------------

    public function test_slug_is_derived_from_title_when_blank(): void
    {
        $entry = PublicProductCatalogEntry::create(['title' => 'My Great Product!!']);

        $this->assertSame('my-great-product', $entry->slug);
    }

    public function test_provided_slug_is_normalized(): void
    {
        $entry = PublicProductCatalogEntry::create(['title' => 'X', 'slug' => 'Custom Slug Value']);

        $this->assertSame('custom-slug-value', $entry->slug);
    }

    public function test_currency_defaults_to_usd(): void
    {
        $entry = PublicProductCatalogEntry::create(['title' => 'No Currency']);

        $this->assertSame('USD', $entry->currency);
    }

    // -------------------------------------------------------------------------
    // Price label
    // -------------------------------------------------------------------------

    public function test_price_label_formats_usd_monthly(): void
    {
        $entry = PublicProductCatalogEntry::factory()->make([
            'starting_price_cents' => 4900,
            'currency'             => 'USD',
            'billing_interval'     => 'monthly',
        ]);

        $this->assertSame('from $49.00/mo', $entry->priceLabel());
    }

    public function test_price_label_null_without_price(): void
    {
        $entry = PublicProductCatalogEntry::factory()->make(['starting_price_cents' => null]);

        $this->assertNull($entry->priceLabel());
    }

    public function test_price_label_non_usd_currency(): void
    {
        $entry = PublicProductCatalogEntry::factory()->make([
            'starting_price_cents' => 1000,
            'currency'             => 'EUR',
            'billing_interval'     => 'yearly',
        ]);

        $this->assertSame('from 10.00 EUR/yr', $entry->priceLabel());
    }

    // -------------------------------------------------------------------------
    // Safe serialization
    // -------------------------------------------------------------------------

    public function test_to_public_array_excludes_metadata_and_internal_fields(): void
    {
        $entry = PublicProductCatalogEntry::factory()->make([
            'metadata'  => ['api_token' => 'should-not-be-here'],
            'is_public' => true,
        ]);

        $public = $entry->toPublicArray();

        $this->assertArrayNotHasKey('metadata', $public);
        $this->assertArrayNotHasKey('is_public', $public);
        $this->assertArrayNotHasKey('id', $public);
        $this->assertArrayHasKey('title', $public);
        $this->assertArrayHasKey('price_label', $public);
        $this->assertStringNotContainsString('should-not-be-here', (string) json_encode($public));
    }

    public function test_is_published_helper(): void
    {
        $this->assertTrue(PublicProductCatalogEntry::factory()->published()->make()->isPublished());
        $this->assertFalse(PublicProductCatalogEntry::factory()->unpublished()->make()->isPublished());
    }

    // -------------------------------------------------------------------------
    // Homepage helper
    // -------------------------------------------------------------------------

    public function test_featured_for_homepage_returns_featured_published_only(): void
    {
        PublicProductCatalogEntry::factory()->published()->featured()->create(['title' => 'Feat']);
        PublicProductCatalogEntry::factory()->published()->create(['title' => 'NotFeat']);
        PublicProductCatalogEntry::factory()->featured()->unpublished()->create(['title' => 'FeatDraft']);

        $result = PublicProductCatalogEntry::featuredForHomepage();

        $this->assertCount(1, $result);
        $this->assertSame('Feat', $result->first()->title);
    }
}
