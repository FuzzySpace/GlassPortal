<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — billing payments. Mirrors a Stripe PaymentIntent; the invoice is
 * nullable (a payment may not map to a single invoice). Amounts in cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_customer_id')->constrained('billing_customers')->cascadeOnDelete();
            $table->foreignId('billing_invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('status', 32)->default('pending'); // pending | succeeded | failed | canceled
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
