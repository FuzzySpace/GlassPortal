# Phase 6: Unified Identity + Module Launchpad

## Overview

Phase 6 adds the `organization_module_links` table and the **Module Launchpad** — the customer-facing view that shows all registered ecosystem modules and their per-org link status, plus the admin views for managing those links.

No SSO token exchange is implemented. SSO auth modes (`shared_session`, `signed_launch`, `oauth`) are recognised but treated as Phase 7+ placeholders.

---

## Database

### `organization_module_links`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `organization_id` | FK → organizations | cascadeOnDelete |
| `module_key` | varchar(64) | matches `glasshouse.launch_modules` key |
| `display_name` | varchar | human label |
| `external_account_id` | varchar nullable | upstream account reference |
| `external_url` | text nullable | direct launch URL (standalone only) |
| `auth_mode` | varchar(32) | see below |
| `status` | varchar(32) | `active` / `inactive` / `suspended` |
| `last_seen_at` | timestamp nullable | last sync from external system |
| `metadata` | json nullable | arbitrary per-module data |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp nullable | soft delete |

Indexes: `(organization_id, module_key)`, `(status)`.

### Auth modes

| Mode | Category | Launch behaviour |
|---|---|---|
| `local` | Safe | No external URL needed; internal only |
| `standalone` | Safe | Uses `external_url` directly |
| `api_token` | Safe | API-key based; URL from config |
| `shared_session` | Phase 7+ | Placeholder; no launch URL issued |
| `signed_launch` | Phase 7+ | Placeholder; no launch URL issued |
| `oauth` | Phase 7+ | Placeholder; no launch URL issued |

---

## Module Registry (`config/glasshouse.php`)

Two sections:

- **`modules`** — connector-level integrations (used by GlassBillingClient etc.). Env-driven credentials. Keys: `glassbilling`, `glasspanel`, `aria`, `dns`, `mail`, `support`, `infrastructure`, and others.
- **`launch_modules`** — customer-facing launchpad registry. Each entry has `display_name`, `description`, `icon`. Keys: `glassbilling`, `glasspanel`, `aria`, `dns`, `mail`, `support`, `infrastructure`.

The `launch_modules` keys must match `organization_module_links.module_key` values.

---

## Services

### `App\Services\ModuleLaunchService`

Safe launch-data resolver. Never issues credentials to the browser.

```
getLaunchData(OrganizationModuleLink): array
  → module_key, display_name, status, auth_mode,
     launch_url (null for SSO/inactive/missing URL),
     setup_required (bool), warnings (string[])

getLaunchDataForAll(iterable $links): array
  → indexed array of getLaunchData() results

mergeWithRegistry(iterable $links): array
  → keyed by module_key, merges org links with glasshouse.launch_modules
  → unlinked modules: status='not_linked', launch_url=null, setup_required=true
```

**Safety invariants:**
- SSO modes (`shared_session`, `signed_launch`, `oauth`) always return `launch_url = null`.
- Inactive links always return `launch_url = null`.
- `standalone` without `external_url` sets `setup_required = true`.
- `local` without `external_url` is not an error (`setup_required = false`).

---

## Routes

```
GET /admin/modules          admin.modules          Admin\ModulesController@index
GET /admin/module-links     admin.module-links     Admin\ModuleLinksController@index
GET /portal/modules         portal.modules         Portal\ModulesController@index
```

### Admin: `/admin/module-links`

- Lists all `OrganizationModuleLink` records with pagination (25/page).
- Filters: `?module_key=` and `?status=`.
- Shows SSO-mode links with **Phase 7+** badge.
- Requires `Admin` role.

### Admin: `/admin/modules`

- Updated to show both connector registry (env/config driven) and launch registry.
- Per-key link counts (total / active).

### Portal: `/portal/modules`

- Customer-facing. Shows all `launch_modules` from registry.
- Per-module state: **Launch** (safe mode with URL), **Setup required** (SSO/no URL), **Not linked** (no OML row), **Phase 7+** warning (SSO modes).
- Requires `Customer` role.

---

## Models

### `App\Models\OrganizationModuleLink`

- `HasFactory`, `SoftDeletes`
- `organization(): BelongsTo`
- `isActive(): bool`
- `isSsoMode(): bool` — true for `shared_session`, `signed_launch`, `oauth`

### `App\Models\Organization`

- Added `moduleLinks(): HasMany`

---

## Healthcheck

`php artisan glassportal:healthcheck` now checks:

- `db.module_links` — `organization_module_links` table present
- `config.modules` — reports N connector modules and N launch modules from `config/glasshouse.php`

---

## Tests

| Suite | Count |
|---|---|
| `Unit\ModuleLaunchServiceTest` | 10 tests |
| `Feature\PortalModulesTest` | 8 tests |
| `Feature\AdminModulesTest` | 11 tests |
| `Feature\HealthCheckCommandTest` | updated (+2 checks) |

All 80 tests pass.

---

## What is NOT implemented (Phase 7+)

- SSO token exchange (signed_launch, shared_session, oauth)
- Per-module credential provisioning
- Live sync from external modules into `organization_module_links`
- Module-level write actions (create account, deprovision, etc.)
- SCIM or directory sync
