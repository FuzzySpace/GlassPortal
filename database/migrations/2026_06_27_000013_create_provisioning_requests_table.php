<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 26 — provisioning requests.
 *
 * An approval-gated request to fulfill (or change) a billing entitlement.
 * Billing determines entitlement; this engine records the *request* and its
 * lifecycle — it NEVER executes infrastructure. Future drivers (Phase 27+)
 * consume approved/queued requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_key')->unique();

            $table->foreignId('billing_service_entitlement_id')->nullable()->constrained('billing_service_entitlements')->nullOnDelete();
            $table->foreignId('billing_customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('module_key', 64)->nullable()->index();
            $table->string('product_key', 64)->nullable()->index();
            $table->string('service_type', 64)->nullable()->index();
            $table->string('driver_key', 64)->nullable()->index();

            $table->string('requested_action', 32)->default('provision')->index();
            $table->string('status', 32)->default('pending_approval')->index();
            $table->string('priority', 16)->default('normal');
            $table->boolean('requires_approval')->default(true);

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('idempotency_key')->nullable()->unique();
            $table->string('reason')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_service_entitlement_id');
            $table->index('billing_customer_id');
            $table->index('organization_id');
            $table->index('user_id');
            $table->index(['status', 'requested_action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_requests');
    }
};
