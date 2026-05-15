# Legacy: Partial Laravel Scaffold

This directory preserves the partial Laravel scaffold that was imported
alongside the raw-PHP provisioning portal.

**This scaffold is superseded. It is NOT the target runtime.**

## What this contained

- `artisan` — empty placeholder (zero bytes)
- `composer.json` — empty placeholder (zero bytes)
- `routes/api.php` — empty placeholder
- `database/migrations/` — 15 real migration files (2026_03_13_*)

## What was done

The 15 migration files were copied into `database/migrations/` in the
Laravel-native root app during Phase 2. The "copy 2" duplicate
(`*_create_subscription_plans_table copy 2.php`) was dropped as it is
identical to the non-duplicated file.

The empty `artisan`, `composer.json`, and `routes/api.php` were replaced
by the full Laravel 11 versions at the repository root.

Git history for all files in this directory is preserved.
