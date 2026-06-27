<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 26 — immutable audit log of provisioning request transitions. Records
 * previous/new status, the acting party, and a message for every transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_request_id')
                ->constrained('provisioning_requests')
                ->cascadeOnDelete();

            $table->string('event_type', 64)->index();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();

            $table->string('actor_type', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('message')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('provisioning_request_id');
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_request_events');
    }
};
