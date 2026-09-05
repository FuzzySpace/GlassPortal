# GlassBilling Companion Runtime Inventory

**Date opened:** 2026-07-03 (Phase 29C) · **Status:** Partially complete — operator fields pending · **Rule:** GlassBilling at :18180 is a preserved companion runtime; nothing here authorizes changes to it.

## 1. Known facts (recorded from operator evidence and repo inspection)

| Item | Value | Source |
| :--- | :--- | :--- |
| Public URL | `http://40.160.61.180:18180/login` | Operator |
| Compose project | `ghbilling` | Operator |
| App container | `ghbilling-billing-1` (port 8080) | Operator |
| Frontend container | `ghbilling-portal-1` (port 3000, Next.js 14) | Operator / repo |
| Database | `ghbilling-postgres-1` (PostgreSQL 16, port 5432 local) | Operator / repo docker-compose |
| Cache/queue | `ghbilling-redis-1` (6379 local); Horizon configured in repo | Operator / repo |
| Mail capture | `ghbilling-mailhog-1` (1025/8025) | Operator |
| Source repo | `FuzzySpace/GlassBilling`, main @ `f526a26`, last commit 2026-05-11 | Repo |
| Routed API surface | Phase 3–7 admin API (see `docs/state/sdk-contract-map.md` §5) | Repo |
| Stripe webhook | Controller present, **not routed** — this runtime should receive no Stripe traffic | Repo |
| Automated tests | None in repo | Repo |

## 2. Operator-to-complete fields (required before Stage E/F)

The following cannot be determined from the repositories and must be captured from the live host by the operator (read-only commands; suggested commands shown):

| Item | How to capture | Value (fill in) |
| :--- | :--- | :--- |
| Databases present | `docker exec ghbilling-postgres-1 psql -U <user> -l` | — |
| Row counts (customers, customer_services, invoices, provisioning_requests) | `psql ... -c "select count(*) from <table>"` | — |
| Real vs. seed data determination | inspect sample rows / created_at ranges | — |
| Docker volumes + sizes | `docker volume ls` + `docker system df -v` | — |
| Backup existence + last backup date | operator records | — |
| Active scheduled jobs / Horizon status | `docker exec ghbilling-billing-1 php artisan horizon:status` | — |
| External systems pointing at :18180 | Stripe dashboard webhook list, GlassPanel config, DNS | — |
| Env/secret files in use | compose file `env_file` entries (do not print values) | — |
| Uptime / restart policy | `docker inspect` restart policy | — |

## 3. Preservation rules

Until an approved Stage F decision exists: do not stop, restart-with-changes, redirect, upgrade, or migrate this stack; do not point Stripe webhooks at it; do not write to its database from any new code; capture backups before *any* future approved change; and record every observation made against it in this file with a date.
