<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 — GlassSite Lite CMS / Public Product Catalog.
 *
 * Stores intentionally-published marketing/catalog entries that GlassSite
 * renders on the public, unauthenticated front door (/products). Every column
 * here is safe-by-design public metadata — no secrets, credentials, customer
 * data, tenant IDs, or infrastructure inventory is ever stored in this table.
 * The `metadata` JSON is admin-authored and is NEVER rendered on public pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_product_catalog_entries', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('short_description')->nullable();
            $table->text('description')->nullable();

            // Optional classification / cross-references to the module registry.
            $table->string('category')->nullable();
            $table->string('product_key', 64)->nullable()->index();
            $table->string('module_key', 64)->nullable()->index();

            // Pricing is display-only marketing data (never a billing source of truth).
            $table->unsignedInteger('starting_price_cents')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('billing_interval', 32)->nullable(); // monthly | yearly | one_time | ...

            // Public call-to-action and helpful links.
            $table->string('cta_label')->nullable();
            $table->string('cta_url', 2048)->nullable();
            $table->string('docs_url', 2048)->nullable();
            $table->string('support_url', 2048)->nullable();
            $table->string('status_url', 2048)->nullable();

            $table->string('icon', 16)->nullable();

            // Publication controls.
            $table->boolean('featured')->default(false);
            $table->boolean('is_public')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();

            // Admin-authored extra fields — never surfaced publicly.
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Listing queries filter by publication state and order by featured/sort.
            $table->index(['is_public', 'published_at']);
            $table->index(['featured', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_product_catalog_entries');
    }
};
