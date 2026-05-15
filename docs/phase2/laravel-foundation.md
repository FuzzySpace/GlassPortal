# Phase 2 — GlassPortal Laravel Foundation

This document describes the work done in Phase 2 to convert GlassPortal from
a mixed raw-PHP / partial-Laravel state into a clean, bootable Laravel 11
application foundation.

## Decision: root-level Laravel, legacy preserved

The `laravel/` partial scaffold was **not** promoted in place. It contained
only empty `artisan`/`composer.json` and 15 real migration files. Instead:

1. A full Laravel 11 project was created via `composer create-project`.
2. Its skeleton was merged into the repository root, preserving all Phase 1
   files (`README.md`, `docs/`, `INSTALL.md`, `.gitignore`, `.env.example`).
3. The 15 legacy migrations were copied into `database/migrations/`.
4. The old `laravel/` scaffold was moved to `legacy/laravel-scaffold/` via
   `git mv` (history preserved).
5. The `provisioning portal/` raw-PHP app was moved to
   `legacy/provisioning-portal/` via `git mv` (history preserved, space in
   path eliminated).

## What the Laravel foundation includes

### Framework

- **Laravel 11** on **PHP 8.3+**
- Standard Laravel 11 project layout (no legacy `Http/Kernel.php` — uses
  the new `bootstrap/app.php` API)

### Routing

| Route              | Description                                  |
|--------------------|----------------------------------------------|
| `GET /`            | GlassPortal landing page (Blade view)        |
| `GET /up`          | Laravel health check (built-in)              |
| `GET /portal`      | Customer portal placeholder                  |
| `GET /admin`       | Staff portal placeholder                     |
| `GET /api/health`  | JSON health endpoint (version, env, time)    |
| `GET /api/connectors/{module}/health` | Per-module connector stubs (501) |

All connector stubs return HTTP 501 until Phase 3+ wires real connectors.

### Configuration

- `config/glasshouse.php` — new; centralizes all ecosystem module config.
  Every value is read from env. No hardcoded URLs or tokens.
- `config/app.php`, `config/database.php`, etc. — standard Laravel 11
  defaults, untouched.
- `.env.example` — updated to be Laravel-native while retaining all
  Glasshouse ecosystem placeholders from Phase 1.

**Database preference:** PostgreSQL (parity with GlassBilling). SQLite
instructions provided for local dev without a DB server.

### Migrations

Existing 15 migrations from the imported scaffold are now in
`database/migrations/` alongside the 3 standard Laravel migrations
(users, cache, jobs tables). They are **not yet safe to run** in full because
they reference tables (`nodes`, `sites`, `racks`, `providers`, etc.) whose
own migrations weren't included in the imported scaffold. See Risks below.

### Views

- `resources/views/welcome.blade.php` — GlassPortal-branded landing (dark,
  minimal, no external CDN dependencies at runtime).
- `resources/views/placeholder.blade.php` — reusable "under construction"
  page for `/admin` and `/portal`.

### Legacy code

```
legacy/
├── provisioning-portal/   # Original raw-PHP app (reference only, history preserved)
│   └── LEGACY.md
└── laravel-scaffold/      # Superseded partial scaffold (history preserved)
    └── LEGACY.md
```

## Commands run during Phase 2

| Command                                        | Result        |
|------------------------------------------------|---------------|
| `composer create-project laravel/laravel`      | OK            |
| Skeleton merge to repo root                    | OK            |
| `git mv "provisioning portal" legacy/…`        | OK (history preserved) |
| `git mv laravel legacy/…`                      | OK (history preserved) |
| `composer validate`                            | see Validation section |
| `php artisan --version`                        | see Validation section |
| `php artisan route:list`                       | see Validation section |
| `php -l` on modified PHP files                 | see Validation section |

## Validation results

See the final report at the bottom of the commit message. Commands were run
immediately before commit; results are authoritative.

## Risks

1. **Orphaned migrations.** The 15 imported migrations reference tables
   (`nodes`, `sites`, `racks`, `providers`, `ip_pools`, `users`, etc.) that
   are not all covered by the 3 standard Laravel migrations. Running
   `php artisan migrate` will fail on a fresh database until the missing
   base-table migrations are added. **Resolution in Phase 3:** audit all
   referenced tables, add missing migrations or update the order.

2. **No auth system yet.** Auth was explicitly not built in Phase 2 (per
   spec). `/admin` and `/portal` are open stubs. Phase 3 must add RBAC and
   session/auth boundary before any staff or customer access.

3. **No test suite.** The Laravel default `tests/` directory is present but
   empty beyond the stock `Feature/ExampleTest.php`. Phase 3 should add at
   minimum route smoke tests.

4. **Vite / frontend not built.** `package.json` is the stock Laravel 11 one
   (Vite + Tailwind). `npm install && npm run dev` is needed for the full
   Tailwind class set. The welcome/placeholder views use inline CSS and don't
   depend on a Vite build.

5. **Legacy credentials still in legacy/provisioning-portal/.** The hardcoded
   fallback DB/root passwords documented in Phase 1 are still present in the
   legacy tree. This is acceptable for reference code; do not re-deploy
   from `legacy/`.

## Recommended Phase 3

1. **Auth + RBAC.** Implement staff vs. customer session/auth boundary using
   Laravel Sanctum or Fortify. Gate `/admin` and `/portal`. Add the
   `audit_log` table.
2. **Migrate the missing base tables.** Create migration files for `nodes`,
   `sites`, `racks`, `providers`, `ip_pools`, `vlans`, `automations`,
   `ansible_scripts`, `build_templates`, `deployments`, `provisioning_jobs`,
   `ip_assignments` (referenced by the 15 imported migrations).
3. **First live connector — GlassBilling.** Use `config/glasshouse.php`
   `glassbilling` section. Build a thin HTTP client, add retry/circuit-breaker.
4. **CI pipeline.** Add GitHub Actions: `composer validate`, `php artisan
   route:list`, `php artisan test`, `php -l` sweep.
5. **Customer portal scaffold.** Replace the `/portal` placeholder with a
   real Blade layout — nav, sidebar, dashboard shell — wired to GlassBilling
   for subscription status read.
