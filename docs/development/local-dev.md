# GlassPortal — Local Development

How to bring up GlassPortal locally. As of Phase 2 this is a full
Laravel 11 application; the raw-PHP legacy portal is reference-only under
`legacy/provisioning-portal/`.

## Required dependencies

- **PHP 8.3+** with extensions: `pdo_pgsql` (or `pdo_sqlite` for local),
  `mbstring`, `openssl`, `json`, `curl`, `xml`, `fileinfo`, `tokenizer`.
- **Composer 2.x**
- **Node.js 20+** and **npm** (for Vite/frontend, optional in Phase 2)
- **PostgreSQL 15+** (preferred) **or** SQLite (local dev only)

## Setup

```bash
# 1. Clone
git clone https://github.com/FuzzySpace/GlassPortal.git
cd GlassPortal

# 2. Install PHP dependencies
composer install

# 3. Env file
cp .env.example .env
php artisan key:generate

# 4. Database — choose one:

## Option A: SQLite (quick, no server)
# In .env, set:
#   DB_CONNECTION=sqlite
#   DB_DATABASE=/absolute/path/to/database/glassportal.sqlite
touch database/glassportal.sqlite

## Option B: PostgreSQL
# Create DB and user, then set in .env:
#   DB_CONNECTION=pgsql
#   DB_HOST=127.0.0.1
#   DB_PORT=5432
#   DB_DATABASE=glassportal
#   DB_USERNAME=glassportal
#   DB_PASSWORD=your-password

# 5. Run safe migrations
#    NOTE: some imported migrations reference tables not yet created.
#    Run only the standard Laravel migrations for now:
php artisan migrate --path=database/migrations/0001_01_01_000000_create_users_table.php
php artisan migrate --path=database/migrations/0001_01_01_000001_create_cache_table.php
php artisan migrate --path=database/migrations/0001_01_01_000002_create_jobs_table.php
#    See docs/phase2/laravel-foundation.md "Orphaned migrations" for context.
#    Full migrate will work once Phase 3 adds missing base-table migrations.

# 6. Start dev server
php artisan serve
```

Open <http://127.0.0.1:8000> — you should see the GlassPortal landing page.

## Frontend (optional in Phase 2)

The welcome/placeholder views use inline CSS. To use the full Tailwind build:

```bash
npm install
npm run dev
```

## Common commands

| Purpose                   | Command                              |
|---------------------------|--------------------------------------|
| Install deps              | `composer install`                   |
| Generate app key          | `php artisan key:generate`           |
| Start dev server          | `php artisan serve`                  |
| List routes               | `php artisan route:list`             |
| Clear all caches          | `php artisan optimize:clear`         |
| Run migrations            | `php artisan migrate`                |
| Run tests                 | `php artisan test`                   |
| PHP syntax lint sweep     | `find . -path ./vendor -prune -o -name '*.php' -print0 \| xargs -0 php -l` |
| Lint (Pint)               | `./vendor/bin/pint --test`           |
| Tinker REPL               | `php artisan tinker`                 |
| Tail logs                 | `php artisan pail`                   |

## Expected ports

| Service              | Port  | Notes                      |
|----------------------|-------|----------------------------|
| Laravel dev server   | 8000  | `php artisan serve`        |
| Vite dev (frontend)  | 5173  | `npm run dev`              |
| PostgreSQL           | 5432  | If using PostgreSQL        |

## Health checks

- `GET /up` — Laravel built-in health check (returns 200 OK when app boots)
- `GET /api/health` — JSON response with version, env, and timestamp
- `GET /api/connectors/{module}/health` — per-module stub (returns 501
  until Phase 3 connectors are wired)

## Known missing pieces

- Auth/RBAC not yet implemented — `/admin` and `/portal` are open stubs.
- Imported migrations are not all runnable yet (see Phase 2 doc).
- No CI configured yet.
- Vite build is not required for dev but `npm install` is needed for
  `npm run dev`.

## Troubleshooting

**`APP_KEY is missing`** — run `php artisan key:generate` after copying
`.env.example` to `.env`.

**`PDO pgsql extension not found`** — install `php8.3-pgsql`
(Debian/Ubuntu) or use SQLite (`DB_CONNECTION=sqlite`).

**`SQLSTATE: Base table not found` on `php artisan migrate`** — use the
selective migration commands above. Full migrate will work in Phase 3.

**`Storage/cache is not writable`** — `chmod -R 775 storage bootstrap/cache`.

**Port 8000 in use** — `php artisan serve --port=8001`.

**Vite manifest not found** — this is a warning, not an error, if you
haven't run `npm run build`. The welcome view has a fallback inline style.
