# Legacy: Provisioning Portal (Raw PHP)

This directory preserves the original raw-PHP provisioning portal as
imported into GlassPortal during Phase 1.

**This code is reference-only. It is NOT the target runtime.**

The active application is now the Laravel-native foundation at the repository
root. Feature parity migration from this legacy portal is a Phase 3+ task.

## What this contained

- PHP 8.x raw web application (no framework)
- MariaDB/MySQL backend (`database/schema.sql`, `database/config.php`)
- Server-rendered pages: dashboard, nodes, customers, racks, automations,
  provisioning, hardware, users, settings, search, audit
- Authentication: session-based (`auth/`)
- Worker/automation runner (`worker/`)
- Windows installer compatibility (`init-db.php`)

## Known issues (documented in Phase 1)

- `database/config.php` contains hardcoded fallback DB credentials.
- `init-db.php` contains a hardcoded MariaDB root password fallback
  (`glasshouse`) used by the Windows installer.
- These are acceptable as reference artifacts. Do not deploy this code.

## Original location

`provisioning portal/` (root level, folder name had a space).
Moved to `legacy/provisioning-portal/` in Phase 2 via `git mv` to:
- eliminate the space-in-path footgun
- make the legacy boundary explicit

Git history for all files in this directory is preserved.
