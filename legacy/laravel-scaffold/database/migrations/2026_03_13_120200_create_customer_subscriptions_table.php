// ... (same header)

Schema::create('customer_subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
    $table->foreignId('plan_id')->constrained('subscription_plans');
    $table->string('stripe_subscription_id', 128)->nullable();
    $table->string('status', 24)->default('active');
    $table->timestamp('current_period_start')->useCurrent();
    $table->timestamp('current_period_end');
    $table->timestamp('cancelled_at')->nullable();
    $table->timestamps();
});