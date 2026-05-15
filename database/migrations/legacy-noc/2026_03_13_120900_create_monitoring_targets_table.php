<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->string('name', 128);
            $table->string('target_type', 32); // ping, http, tcp, icmp, custom
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('check_interval_seconds')->default(60);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index(['node_id', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_targets');
    }
};
