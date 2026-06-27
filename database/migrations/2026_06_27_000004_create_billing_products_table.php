<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — billing products. A sellable product, optionally linked to a
 * GlassSite public catalog entry for marketing display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('public_catalog_entry_id')
                ->nullable()
                ->constrained('public_product_catalog_entries')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_products');
    }
};
