<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->foreignId('target_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('job_type', 32); // vm_move, disk_move, rebuild
            $table->string('status', 32)->default('pending');
            $table->json('payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_jobs');
    }
};
