<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 — SIONA tenant provisioning.
 *
 * Adds a dedicated, nullable, indexed column to map a GlassPortal
 * organization to its SIONA workspace/tenant. Phase 19 stored this in the
 * organization_module_links.metadata JSON; Phase 20 promotes it to a
 * first-class indexed column so the mapping can be looked up directly and
 * enforced as the source of truth for provisioning idempotency.
 *
 * No credentials or tokens are stored here — only the opaque workspace ID
 * returned by SIONA's tenant-creation API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Nullable: organizations exist before SIONA is provisioned.
            // Indexed: provisioning + reverse lookups query by workspace ID.
            $table->string('siona_workspace_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['siona_workspace_id']);
            $table->dropColumn('siona_workspace_id');
        });
    }
};
