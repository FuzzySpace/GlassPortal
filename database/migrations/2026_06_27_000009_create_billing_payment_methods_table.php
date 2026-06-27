<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — billing payment methods. Stores only safe, non-sensitive card
 * display data (brand, last4, expiry). NEVER stores full card numbers/CVC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_customer_id')->constrained('billing_customers')->cascadeOnDelete();
            $table->string('stripe_payment_method_id')->nullable()->unique();
            $table->string('type', 32)->nullable();   // card | sepa_debit | ...
            $table->string('brand', 32)->nullable();  // visa | mastercard | ...
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_methods');
    }
};
