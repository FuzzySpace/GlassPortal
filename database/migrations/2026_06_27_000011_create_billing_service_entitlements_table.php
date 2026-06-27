<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 25 — billing service entitlements.
 *
 * An entitlement is GlassBilling's authoritative statement of *what a customer
 * is allowed to receive* based on billing/subscription state. It carries
 * lifecycle status only — it never mutates infrastructure. A future
 * provisioning request engine (Phase 26) consumes entitlements to fulfill them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_service_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('billing_customer_id')->constrained('billing_customers')->cascadeOnDelete();
            $table->foreignId('billing_subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->nullOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Stable key used for idempotent creation (one entitlement per
            // subscription+plan, or per explicit grant).
            $table->string('entitlement_key')->unique();

            $table->string('service_type', 64)->nullable()->index();
            $table->string('module_key', 64)->nullable()->index();
            $table->string('product_key', 64)->nullable()->index();

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('quantity')->default(1);

            // Lifecycle timestamps.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('terminated_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_subscription_id');
            $table->index('billing_product_id');
            $table->index('billing_plan_id');
            $table->index('organization_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_service_entitlements');
    }
};
