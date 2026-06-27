<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — billing invoices. Mirrors a Stripe invoice. Amounts in cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_customer_id')->constrained('billing_customers')->cascadeOnDelete();
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->string('status', 32)->default('draft'); // draft | open | paid | void | uncollectible
            $table->unsignedBigInteger('amount_due_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
