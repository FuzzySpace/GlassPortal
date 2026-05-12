# Phase 3 — Auth, Module Shell & GlassBilling Connector

This document describes what was added in Phase 3 to establish GlassPortal's
authentication layer, role-based access control, modular portal shell, and
first live connector scaffolding.

## What was added

### Auth model

Session-based authentication using Laravel's built-in `web` guard.

- Login at `GET /login`, submitted via `POST /login`.
- Logout via `POST /logout`.
- After login, users are redirected by role:
  - Staff roles (owner, admin, staff, support) → `/admin`
  - Customer role → `/portal`
- `App\Http\Middleware\EnsureUserHasRole` enforces role access on all
  protected routes.

### RBAC model

Roles are stored as a `role` string column on the `users` table, cast to the
`App\Enums\UserRole` backed enum.

| Role      | Label          | Access                            |
|-----------|----------------|-----------------------------------|
| `owner`   | Owner          | Full staff access                 |
| `admin`   | Administrator  | Full staff access                 |
| `staff`   | Staff          | Staff portal access               |
| `support` | Support        | Staff portal access               |
| `customer`| Customer       | Customer portal access only       |

Role helpers on `User`: `isOwner()`, `isAdmin()`, `isStaff()`, `isCustomer()`,
`hasRole(...$roles)`.

### Migrations added

| Migration file                                          | Creates / alters                |
|---------------------------------------------------------|---------------------------------|
| `0001_01_01_000003_create_organizations_table`          | `organizations` table           |
| `0001_01_01_000004_add_role_and_organization_to_users`  | `role`, `organization_id`, `deleted_at` on `users` |

These run cleanly after the 3 standard Laravel migrations. The 15 legacy NOC
migrations were moved to `database/migrations/legacy-noc/` to prevent
`migrate --force` failures (they reference missing base tables; see Phase 4).

### Models

- `App\Models\Organization` — organization/tenant record, soft-deletes,
  `glassbilling_customer_id` for future GlassBilling join.
- `App\Models\User` — updated with `role`, `organization_id`, `SoftDeletes`,
  and role helper methods.
- `App\Enums\UserRole` — backed PHP 8.1 enum with `isStaff()`, `isAdmin()`,
  `staffRoles()`, `adminRoles()`.

### Portal layouts

Three Blade layouts, each standalone (no Vite/npm build required):

- `resources/views/layouts/public.blade.php` — bare public pages
- `resources/views/layouts/staff.blade.php` — fixed left sidebar nav with
  all staff sections; shows role badge and logout in footer
- `resources/views/layouts/customer.blade.php` — horizontal top nav for
  customer portal

All use a shared dark operator theme (`#0d1117` background, `#58a6ff` accent).

### Routes (Phase 3)

| Method    | Path                             | Name                  | Guard         |
|-----------|----------------------------------|-----------------------|---------------|
| GET       | `/`                              | `home`                | public        |
| GET       | `/login`                         | `login`               | guest         |
| POST      | `/login`                         |                       | guest         |
| POST      | `/logout`                        | `logout`              | auth          |
| GET       | `/admin`                         | `admin.dashboard`     | staff roles   |
| GET       | `/admin/modules`                 | `admin.modules`       | staff roles   |
| GET       | `/admin/services`                | `admin.services`      | staff roles   |
| GET       | `/admin/provisioning`            | `admin.provisioning`  | staff roles   |
| GET       | `/admin/customers`               | `admin.customers`     | staff roles   |
| GET       | `/portal`                        | `portal.dashboard`    | customer role |
| GET       | `/portal/services`               | `portal.services`     | customer role |
| GET       | `/portal/support`                | `portal.support`      | customer role |
| GET       | `/up`                            | —                     | public        |
| GET       | `/api/health`                    | —                     | public        |
| GET       | `/api/glassbilling/health`       | —                     | public        |
| GET       | `/api/connectors/{module}/health`| —                     | public (stub) |

22 routes total.

### GlassBilling connector

`App\Services\GlassBillingClient`:

- Reads `GLASSBILLING_API_URL`, `GLASSBILLING_API_TOKEN`, `GLASSBILLING_TIMEOUT`
  from env via `config/glasshouse.php`.
- **Never throws** — all methods return safe offline payloads when
  misconfigured or unreachable. Errors are logged at `warning` level.
- Methods:
  - `health()` → `['status' => 'online'|'offline'|'unconfigured', 'detail' => '...']`
  - `dashboardSummary()` → billing KPIs or null placeholders
  - `customerServices()` → list of services or empty array
  - `provisioningRequests()` → list of requests or empty array
- Staff dashboard shows GlassBilling status card prominently.
- `GET /api/glassbilling/health` exposes the health result as JSON.

### Module registry (config/glasshouse.php)

7 modules registered:

| Key           | Display name     | Phase    |
|---------------|------------------|----------|
| `glassbilling`| GlassBilling     | Phase 3 (connector scaffold live) |
| `glasspanel`  | GlassPanel       | Phase 4+ |
| `aria`        | Aria (GlassAI)   | Phase 4+ |
| `proxmox`     | Proxmox          | Phase 4+ |
| `powerdns`    | PowerDNS         | Phase 4+ |
| `mailcow`     | Mailcow          | Phase 4+ |
| `pterodactyl` | Pterodactyl      | Phase 4+ (legacy migration target) |

Each module has: `enabled`, `display_name`, `base_url`, `token`, `timeout`,
`health_endpoint`, `notes`. All env-driven.

### Artisan commands

```bash
php artisan glassportal:healthcheck   # system health report
php artisan glassportal:create-admin  # create first staff/owner user from CLI
```

`glassportal:healthcheck` validates:
1. App bootstrap
2. APP_KEY is set
3. Storage writable
4. Session driver configured
5. Database reachable
6. Module config loads
7. GlassBilling connector responds safely (warn, not fail, when offline)

`glassportal:create-admin` securely collects name, email, role, and password
(hidden prompt). Validates role against allowed staff roles. Minimum 12-char
password.

## Module boundary rules (unchanged from Phase 1/2)

- GlassPortal is the shell. It reads state from modules; it does not own
  billing, game runtime, AI model, DNS, or mail logic.
- GlassBilling is the system of record. No direct DB coupling. API only.
- All connector calls go through typed service classes (`GlassBillingClient`
  etc.); controllers never call `Http::` directly.
- No secrets in git. All URLs/tokens in `.env`.

## Dev validation commands

```bash
composer validate
php artisan optimize:clear
php artisan migrate --force
php artisan route:list
php artisan glassportal:healthcheck
php artisan glassportal:create-admin --name="Admin" --email="admin@example.com" --role=admin
php -l on all app/ routes/ config/ files
```

## What remains stubbed

- `/admin/customers` renders local `organizations` table — not yet synced
  with GlassBilling customer records.
- `/admin/services`, `/admin/provisioning` — data comes from GlassBilling
  when `GLASSBILLING_API_URL` + `GLASSBILLING_API_TOKEN` are set; offline
  table shows "Connect GlassBilling..." message.
- `/portal/services` — same as above from customer perspective.
- Invoice and Account sections in customer portal — Phase 4+.
- Billing, Support, Settings in staff sidebar — Phase 4+.
- GlassPanel, Aria, Proxmox, PowerDNS, Mailcow, Pterodactyl connectors —
  Phase 4+.
- Password reset — not yet implemented.
- Email verification — not yet implemented.
- OAuth / SSO with GlassBilling — Phase 4+.

## Recommended Phase 4

1. **First live GlassBilling data** — populate dashboard summary, services,
   and provisioning from real GlassBilling API once a dev/staging instance
   is available.
2. **Organizations ↔ GlassBilling sync** — map `glassbilling_customer_id` to
   portal organizations on login or via webhook.
3. **GlassPanel connector** — follow same pattern as GlassBillingClient.
4. **Customer invoice view** — pull from GlassBilling, display read-only.
5. **Staff support inbox** — wire `SUPPORT_INBOX_PROVIDER` to a real provider.
6. **Password reset + email verification** — use Laravel notifications.
7. **CI pipeline** — GitHub Actions: `composer validate`, `migrate --fresh`,
   `php artisan test`, `route:list`, `glassportal:healthcheck`.
8. **15 legacy NOC migrations** — define the missing base tables (`nodes`,
   `sites`, `racks`, etc.) and reintegrate them from `database/migrations/legacy-noc/`.
