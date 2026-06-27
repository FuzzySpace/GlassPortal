<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 25 — immutable audit log of entitlement lifecycle transitions. Records
 * previous/new status, the acting party, and a reason for every transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_service_entitlement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_service_entitlement_id')
                ->constrained('billing_service_entitlements')
                ->cascadeOnDelete();

            $table->string('event_type', 64)->index();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();

            // Lightweight actor reference (e.g. user / system).
            $table->string('actor_type', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();

            // Immutable log — created_at only.
            $table->timestamp('created_at')->useCurrent();

            $table->index('billing_service_entitlement_id');
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_service_entitlement_events');
    }
};
