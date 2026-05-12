<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oncall_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('schedule_name', 128);
            $table->foreignId('primary_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('secondary_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rotation_start');
            $table->unsignedInteger('rotation_hours')->default(24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oncall_schedules');
    }
};
