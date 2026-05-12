// ... (same header)

Schema::create('usage_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
    $table->string('resource_type', 32);
    $table->bigInteger('resource_id');
    $table->integer('quantity')->default(1);
    $table->decimal('billed_amount_usd', 10, 2)->default(0);
    $table->timestamp('recorded_at')->useCurrent();
    $table->index(['tenant_id', 'recorded_at']);
});