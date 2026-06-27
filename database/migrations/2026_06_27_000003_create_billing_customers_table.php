<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — Stripe-first billing foundation.
 *
 * A billing customer is GlassBilling's account record, optionally mapped to a
 * portal organization and/or user and to a Stripe customer. No secrets stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_customers');
    }
};
