<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — billing events. An idempotent intake log for provider (Stripe)
 * webhook events. `provider_event_id` is unique so a replayed/duplicate event
 * is rejected at the database layer. No soft deletes — this is an event log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('provider', 32)->default('stripe');
            $table->string('provider_event_id')->nullable()->unique();
            // Lightweight polymorphic link to a local billing record (optional).
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 32)->default('pending'); // pending | processed | failed | ignored
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index('event_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
    }
};
