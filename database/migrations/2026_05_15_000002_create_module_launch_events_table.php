<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_launch_events', function (Blueprint $table) {
            $table->id();
            // Nullable FKs so events survive soft-deleted parent records
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('module_link_id')->nullable()->constrained('organization_module_links')->nullOnDelete();
            // Denormalized fields preserved for audit integrity
            $table->string('module_key', 64);
            $table->string('auth_mode', 32);
            // Event outcome: allowed | denied | stubbed | failed
            $table->string('event_type', 32);
            $table->string('reason')->nullable();
            $table->string('ip_address', 45)->nullable();   // IPv6 max = 45 chars
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            // Immutable audit log — no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'module_key']);
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_launch_events');
    }
};
