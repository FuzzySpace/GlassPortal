<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — billing plans (a priced offering of a product, mapped to a Stripe
 * price). Amounts are stored in integer minor units (cents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_product_id')->constrained('billing_products')->cascadeOnDelete();
            $table->string('plan_key', 64)->unique();
            $table->string('stripe_price_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('interval', 32)->nullable(); // month | year | week | day | one_time
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
