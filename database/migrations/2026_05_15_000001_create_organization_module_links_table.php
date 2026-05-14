<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_module_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            // Module key matches config/glasshouse.php module registry keys
            // (glassbilling, glasspanel, aria, dns, mail, support, infrastructure, ...)
            $table->string('module_key', 64);

            $table->string('display_name');

            // External account identifier in the linked module, e.g. a server ID or customer ID.
            // Never stores credentials or tokens.
            $table->string('external_account_id')->nullable();

            // Read-only launch URL for standalone/local auth modes.
            // SSO-driven URLs are generated at launch time, never stored.
            $table->text('external_url')->nullable();

            // How authentication is handled when a user launches this module.
            // Supported values: local, standalone, api_token, shared_session, signed_launch, oauth
            // Phase 6: only local/standalone/api_token produce a real launch URL.
            // shared_session/signed_launch/oauth are reserved for future SSO work.
            $table->string('auth_mode', 32)->default('standalone');

            // active, inactive, pending, error
            $table->string('status', 32)->default('active');

            // When the module last confirmed this link was alive (e.g. via health probe).
            $table->timestamp('last_seen_at')->nullable();

            // Arbitrary per-link metadata (region, plan tier, etc.) — no secrets.
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'module_key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_module_links');
    }
};
