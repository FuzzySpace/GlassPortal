<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 27 — Stripe Checkout sessions.
 *
 * A local mirror of a Stripe Checkout Session created when a customer starts
 * checkout for a plan. The webhook (checkout.session.completed) later marks it
 * complete and links the resulting customer/subscription. No infrastructure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_checkout_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('billing_customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();
            $table->foreignId('billing_product_id')->nullable()->constrained('billing_products')->nullOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->foreignId('billing_subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider', 32)->default('stripe');
            $table->string('provider_session_id')->unique();
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->index();

            $table->string('mode', 32)->nullable()->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('payment_status', 32)->nullable()->index();
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('amount_total')->nullable();

            $table->text('success_url')->nullable();
            $table->text('cancel_url')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_customer_id');
            $table->index('billing_plan_id');
            $table->index('organization_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_checkout_sessions');
    }
};
