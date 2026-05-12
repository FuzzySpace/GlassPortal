<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('subdomain', 64)->unique();
            $table->string('status', 24)->default('active');
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans');
            $table->integer('max_nodes')->default(100);
            $table->integer('max_providers')->default(5);
            $table->string('billing_email', 190);
            $table->string('stripe_customer_id', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};