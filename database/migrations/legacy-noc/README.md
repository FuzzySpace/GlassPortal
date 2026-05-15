# Legacy NOC Migrations

These 15 migrations were imported from the original GlassMineCraft provisioning
portal scaffold. They define the multi-tenant NOC/monitoring schema.

They are isolated here because they reference base tables (`nodes`, `sites`,
`racks`, `providers`, `ip_pools`, `automations`, and others) that do not yet
have their own migrations in GlassPortal.

## Status

**Do not run these directly.** They will fail on a fresh database because the
base-table migrations are missing.

## Plan

Phase 4+ will define the base tables and re-integrate these migrations in
dependency order. At that point this directory's contents will be incorporated
into the main `database/migrations/` tree and this README removed.

## Tables defined here (once base tables are available)

- `subscription_plans`
- `tenants`
- `customer_subscriptions`
- `usage_records`
- `alerts`
- `incidents`
- `oncall_schedules`
- `sla_agreements`
- `monitoring_targets`
- `migration_jobs`
- `provider_credentials`
- `clusters`
- `hardening_runs`
- NOC-column additions to: `sites`, `racks`, `providers`, `ip_pools`,
  `automations`, `ansible_scripts`, `build_templates`, `deployments`,
  `provisioning_jobs`, `ip_assignments`, `vlans`
