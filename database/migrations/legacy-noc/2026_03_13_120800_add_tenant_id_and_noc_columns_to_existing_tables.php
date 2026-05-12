<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tenantTables = [
        'sites',
        'racks',
        'providers',
        'ip_pools',
        'automations',
        'ansible_scripts',
        'build_templates',
        'deployments',
        'provisioning_jobs',
        'ip_assignments',
        'vlans',
        'network_interfaces',
        'power_feeds',
        'node_groups',
        'automation_runs',
        'monitoring_targets',
        'migration_jobs',
        'clusters',
        'hardening_runs',
        'provider_credentials',
    ];

    public function up(): void
    {
        if (Schema::hasTable('nodes')) {
            Schema::table('nodes', function (Blueprint $table) {
                if (!Schema::hasColumn('nodes', 'tenant_id')) {
                    $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
                }
                if (!Schema::hasColumn('nodes', 'hardening_status')) {
                    $table->string('hardening_status', 24)->default('pending');
                }
                if (!Schema::hasColumn('nodes', 'last_hardening_run_at')) {
                    $table->timestamp('last_hardening_run_at')->nullable();
                }
                if (!Schema::hasColumn('nodes', 'compliance_score')) {
                    $table->unsignedTinyInteger('compliance_score')->nullable();
                }
                if (!Schema::hasColumn('nodes', 'monitoring_enabled')) {
                    $table->boolean('monitoring_enabled')->default(true);
                }
                if (!Schema::hasColumn('nodes', 'sla_agreement_id')) {
                    $table->foreignId('sla_agreement_id')->nullable()->constrained('sla_agreements')->nullOnDelete();
                }
                if (!Schema::hasColumn('nodes', 'noc_visibility')) {
                    $table->boolean('noc_visibility')->default(true);
                }
                if (!Schema::hasColumn('nodes', 'cluster_id')) {
                    $table->foreignId('cluster_id')->nullable()->constrained('clusters')->nullOnDelete();
                }
            });
        }

        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nodes')) {
            Schema::table('nodes', function (Blueprint $table) {
                if (Schema::hasColumn('nodes', 'tenant_id')) {
                    $table->dropConstrainedForeignId('tenant_id');
                }
                foreach (['sla_agreement_id', 'cluster_id'] as $fkColumn) {
                    if (Schema::hasColumn('nodes', $fkColumn)) {
                        $table->dropConstrainedForeignId($fkColumn);
                    }
                }
                foreach (['hardening_status', 'last_hardening_run_at', 'compliance_score', 'monitoring_enabled', 'noc_visibility'] as $column) {
                    if (Schema::hasColumn('nodes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        foreach ($this->tenantTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('tenant_id');
                });
            }
        }
    }
};
