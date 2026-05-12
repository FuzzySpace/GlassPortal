# Phase 1 — GlassPortal Baseline Cleanup

This document summarizes the audit and cleanup performed on the imported
GlassPortal repository to prepare it for modular development.

## What was found

### Top-level state on import

- Root `.env`, `README.md`, `composer.json`, and `package.json` all existed
  but were **empty** placeholders.
- `INSTALL.md` (17 KB) contained the original Linux + Windows install guide.
- Three top-level directories:
  - `provisioning portal/` — the working raw-PHP application.
  - `laravel/` — a **partial** Laravel layout (only `artisan`, an empty
    `composer.json`, `database/migrations/`, and `routes/api.php`). It is not
    a runnable Laravel install.
  - `windows-installer/` — Inno Setup project + MariaDB / Apache / PHP
    templates and service install scripts.

### Stack identified

| Aspect             | Value                                                  |
|--------------------|--------------------------------------------------------|
| Primary language   | PHP                                                    |
| Web app            | Raw PHP (no framework wired up)                        |
| Framework target   | Laravel (scaffold present but incomplete)              |
| Database           | MariaDB / MySQL (`provisioning_portal` schema)         |
| Frontend           | Server-rendered PHP + static CSS/JS under `assets/`    |
| Package manager    | Composer (root composer.json empty), npm (empty)       |
| Build              | None — interpreted PHP                                 |
| Dev start          | `php -S 127.0.0.1:8080 -t "provisioning portal"`       |
| Production start   | Bundled Apache via `windows-installer/`                |

### Generated / runtime files

None tracked at import time — the tree is unusually clean. No
`node_modules/`, `vendor/`, `storage/logs/`, `cache/`, or build output was
present. The risk is **prospective** — preventing them from being tracked as
development begins.

### Secrets / credential-like values found in source

These are **hardcoded fallback values** that must be moved to environment
variables in Phase 2. They are flagged but **not changed** here because Phase
1 rule #1 forbids deleting application code and rule #7 says preserve the
baseline.

- `provisioning portal/database/config.php`
  - `username => 'portal_user'`
  - `password => 'PortalStrongPass!ChangeMe'` (clearly a placeholder, but a
    real-looking one)
- `provisioning portal/init-db.php`
  - `$dbRootPass = 'glasshouse';` (root password fallback used by the
    Windows installer)
- `windows-installer/` templates reference the same `glasshouse` root
  password fallback.

A repo-wide grep for typical secret patterns (`api_key`, `apikey`, `token`,
`secret`, real-looking long base64/hex strings) returned **no additional
hits** in PHP/JSON/INI sources at the time of audit.

### Stale repo / branding references

- `INSTALL.md:80` referenced the old development clone URL
  `https://github.com/GlassMineCraft/Glasshouse-Provisioning-Portal-Development.git`.
  Updated to `https://github.com/FuzzySpace/GlassPortal.git`.
- Many files still contain "Glasshouse NOC Portal" / "provisioning_portal"
  database name. These remain **intentionally untouched** in Phase 1 because:
  - "Glasshouse" is the ecosystem name and is still correct.
  - The DB schema name is structural and will be renamed via migration in a
    later phase rather than via mass find/replace.

## What was changed

| File                                       | Change                              |
|--------------------------------------------|-------------------------------------|
| `.gitignore`                               | Created — covers PHP/Laravel/Node/installer/editor junk + secrets. |
| `.env.example`                             | Created — safe placeholders for app + all six future module connectors + support inbox + secret backend. |
| `.env`                                     | Untracked going forward (was an empty tracked file). |
| `README.md`                                | Replaced empty file with project overview, status, setup, limitations, no-production warning. |
| `INSTALL.md`                               | Updated stale GlassMineCraft clone URL to FuzzySpace/GlassPortal. |
| `docs/phase1/baseline-cleanup.md`          | This document.                      |
| `docs/architecture/module-boundaries.md`   | New.                                |
| `docs/security/secrets-and-access.md`      | New.                                |
| `docs/development/local-dev.md`            | New.                                |

No application source files under `provisioning portal/`, `laravel/`, or
`windows-installer/` were modified. No files were deleted.

## Commands tested

| Command                                      | Result                              |
|----------------------------------------------|-------------------------------------|
| `git status` / `git ls-files`                | Clean tree, branch correct.         |
| Repo-wide secret grep (regex)                | No additional credentials found.    |
| `composer install`                           | **Not run** — root `composer.json` is empty; nothing to install. |
| `npm install`                                | **Not run** — root `package.json` is empty. |
| `php -l` / lint sweep                        | **Not run** — out of scope for Phase 1 (no behavior changes). |
| `php -S` boot                                | **Not run** — would require a live MariaDB to be meaningful. |

There is no test suite, lint config, or CI to run at this time.

## Risks

1. **Hardcoded credentials still present** in
   `provisioning portal/database/config.php`, `init-db.php`, and
   `windows-installer/` templates. Even though the values are placeholders,
   they will be copy-pasted into production by anyone who follows the README
   verbatim. Highest-priority Phase 2 item.
2. **Two competing application layouts** (`provisioning portal/` vs
   `laravel/`). A decision is needed before further code changes: finish the
   Laravel migration or stay on raw PHP. Avoid investing in both.
3. **Empty root `composer.json` / `package.json`** can mislead tooling and
   CI. Either populate or delete in Phase 2.
4. **No automated tests, no CI.** Any refactor in Phase 2 is high-risk
   without at least smoke tests.
5. **Folder name with a space** (`provisioning portal/`) is fragile on some
   tooling/shells. Rename should be paired with a Laravel-vs-raw decision.

## Recommended Phase 2

1. **Choose one** application layout (Laravel adoption recommended) and
   migrate the raw-PHP portal into it incrementally.
2. **Remove hardcoded credentials** from `database/config.php`, `init-db.php`,
   and the installer templates; read from `.env` via `getenv()` /
   `vlucas/phpdotenv` / Laravel config.
3. Rename `provisioning portal/` → `app/` (or equivalent) to eliminate the
   space-in-path footgun.
4. Add a minimal CI workflow: `php -l` lint sweep, `composer validate`,
   schema-load smoke test.
5. Stand up the first real connector (suggest GlassBilling) behind a feature
   flag, using the env placeholders introduced here.
6. Introduce structured logging and an audit log table for staff actions
   (precondition for the security model in
   `docs/security/secrets-and-access.md`).
