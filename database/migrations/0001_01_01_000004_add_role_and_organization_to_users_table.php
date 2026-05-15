<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Role controls what the user can access in GlassPortal.
            // owner/admin/staff/support are internal staff roles.
            // customer is a self-service portal role.
            $table->string('role', 24)->default('customer')->after('email');

            // Customers belong to an organization; staff users have null here.
            $table->foreignId('organization_id')
                ->nullable()
                ->after('role')
                ->constrained('organizations')
                ->nullOnDelete();

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['role', 'deleted_at']);
        });
    }
};
