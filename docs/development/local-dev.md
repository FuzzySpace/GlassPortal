# GlassPortal — Local Development

How to bring up GlassPortal locally for Phase 1 work. The repo is in
reorganization, so some pieces (Laravel, tests, CI) are intentionally absent.

## Required dependencies

- **PHP 8.1+** with extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`,
  `curl`, `xml`.
- **MariaDB 10.6+** or **MySQL 8+**.
- **Composer 2.x** (for Phase 2 once `composer.json` is populated).
- **Node.js 20+** and **npm** (Phase 2, once `package.json` is populated).
- A POSIX shell (Linux/macOS) **or** Windows with the bundled installer
  under `windows-installer/`.

## Setup

```bash
# 1. Clone
git clone https://github.com/FuzzySpace/GlassPortal.git
cd GlassPortal

# 2. Env file
cp .env.example .env
#   then edit .env with local DB credentials and leave connector URLs blank

# 3. Database
mysql -u root -p < "provisioning portal/database/schema.sql"
#   creates the `provisioning_portal` database and `portal_user`.
#   For a fresh local dev DB you can also run:
php "provisioning portal/init-db.php"
```

> **Note:** `init-db.php` currently reads its admin/root credentials from
> hardcoded defaults or an installer-supplied INI file. This is a known
> Phase 1 limitation; Phase 2 will switch it to `.env`.

## Dev server

```bash
# Built-in PHP dev server, project root as docroot
php -S 127.0.0.1:8080 -t "provisioning portal"
```

Then open <http://127.0.0.1:8080/login.php>.

## Build

There is no build step in Phase 1. The app is interpreted PHP; static assets
in `provisioning portal/assets/` are served as-is.

## Lint / test

- No lint config or test runner is configured yet.
- Ad-hoc syntax check:
  ```bash
  find "provisioning portal" -name '*.php' -print0 | xargs -0 -n1 php -l
  ```
- Phase 2 will introduce a real test suite + CI workflow.

## Expected ports

| Service       | Port  | Notes                                |
|---------------|-------|--------------------------------------|
| PHP dev server| 8080  | `php -S 127.0.0.1:8080`              |
| MariaDB/MySQL | 3306  | Local DB                             |
| Windows app   | 8080  | Bundled Apache via installer         |

## Known missing pieces

- Root `composer.json` and `package.json` are empty.
- The `laravel/` directory has migrations + a stub route only — `php artisan`
  will not run until the scaffold is filled in.
- No connector module is live; integration env vars are placeholders.
- No CI, no formatter, no test runner.

## Troubleshooting

- **`PDOException: SQLSTATE[HY000] [1045] Access denied`** —
  `provisioning portal/database/config.php` still uses the bundled
  `portal_user` / `PortalStrongPass!ChangeMe` default. Either create that
  user via `schema.sql` or edit the config to match your local DB.
- **`Class "PDO" not found`** — install `php-mysql` (Debian/Ubuntu) or
  `php-pdo_mysql` (RHEL/Fedora).
- **Folder name with space** — `provisioning portal/` contains a space.
  Always quote it in shell commands: `php -S 127.0.0.1:8080 -t "provisioning portal"`.
- **Windows: port 8080 already in use** — change the port in
  `windows-installer/config/httpd.conf.template` or stop the conflicting
  service.
- **Empty page / 500 with no output** — check the PHP error log; the app
  does not yet log to `storage/logs/` in a structured way.
