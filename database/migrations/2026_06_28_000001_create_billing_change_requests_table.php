<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 28 — customer billing change requests.
 *
 * A workflow record only: a customer's *request* to change billing state
 * (cancel, change plan, billing support, pause/resume, update details). It does
 * NOT mutate Stripe, subscriptions, entitlements, provisioning, or
 * infrastructure — staff review and act through the existing approval layers.
 * Cross-DB safe (no after()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_change_requests', function (Blueprint $table) {
            $table->id();

            $table->string('request_key')->unique();

            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('billing_subscription_id')->nullable()->constrained('billing_subscriptions')->nullOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->foreignId('requested_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();

            $table->string('request_type', 48)->index();
            $table->string('status', 32)->default('submitted')->index();

            $table->text('reason')->nullable();
            $table->text('customer_message')->nullable();
            $table->text('admin_notes')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('user_id');
            $table->index('billing_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_change_requests');
    }
};
