# Runbook — Runtime Consolidation (Standalone Billing ↔ GlassPortal)

**Audience:** founder / operator.
**Goal:** safely work toward a clear canonical billing-capable runtime without
losing data or breaking dependencies on the **standalone billing runtime**
(`:18180`).

> **⚠️ Correction (29B→29C):** `:18180` is the **standalone GlassBilling service**
> (integrates with GlassPortal + GlassPanel) — **preserved / potential canonical**
> pending the Phase 29C reconciliation, **not** legacy/dead. "legacy" labels below
> are historical descriptors of the `ghbilling-*` containers, not a retirement
> judgement. Do not retire/migrate/move it.
>
> **Phase 29B does NOT execute any of Stage 3–5.** This runbook is the plan.
> Stages 0–2 are **read-only / backup-only**. Any stop/restrict/redirect/remove
> action is **future approved execution only** (see the fenced section at the
> end) and requires Stage 4 approval + a verified backup + a rollback command.
> Plan/ADR: [`runtime-consolidation-plan.md`](../architecture/runtime-consolidation-plan.md).
> Inventory: [`legacy-billing-runtime-inventory.md`](../state/legacy-billing-runtime-inventory.md).

Reference facts (from [`runtime-map.md`](../state/runtime-map.md)):

- Canonical: GlassPortal — `:18188` — project `glassportal-source` — container
  `glassportal-source-app-1:8088`.
- Legacy: standalone Billing — `:18180` — project `ghbilling` — UI
  `ghbilling-portal-1:3000`, API `ghbilling-billing-1:8080`, plus
  `ghbilling-postgres-1` / `ghbilling-redis-1` / `ghbilling-mailhog-1`.

---

## Stage 0 — No-change inventory (read-only)

Goal: write down exactly what exists. Change nothing.

```bash
# Confirm Docker compose projects + containers
docker compose ls
docker ps --format 'table {{.Names}}\t{{.Ports}}\t{{.Status}}'

# Confirm public URLs respond (read-only; status code only)
curl -sS -o /dev/null -w 'portal :18188 -> %{http_code}\n' http://40.160.61.180:18188/login
curl -sS -o /dev/null -w 'legacy :18180 -> %{http_code}\n' http://40.160.61.180:18180/login

# Capture route lists where available
docker compose -p glassportal-source exec -T app php artisan route:list   # canonical
# (legacy: capture its routes by whatever means its framework supports, read-only)

# Record env KEY NAMES ONLY (never values)
docker compose -p ghbilling exec -T billing printenv | cut -d= -f1 | sort

# Record database TABLE NAMES ONLY (no data)
docker compose -p ghbilling exec -T postgres psql -U postgres -At -c "\dt"   # adjust user/db

# Record volumes + compose files
docker volume ls
ls -l /var/www/html/dev/GHbilling/docker-compose.dev.yml
```

Record: which backups will be needed (DB dump, compose file, env key list).

## Stage 1 — Preservation (backup-only; still no service change)

Goal: make the legacy runtime recoverable before anyone considers touching it.

```bash
# Backup the legacy database (read-only on the DB; writes a dump file)
docker compose -p ghbilling exec -T postgres pg_dump -U postgres ghbilling \
  > ~/ghbilling-db-$(date +%F).sql        # adjust user/db; store securely off-box

# Export schema / table list
docker compose -p ghbilling exec -T postgres psql -U postgres -c "\dt" > ~/ghbilling-tables.txt

# Export product / customer METADATA — only if explicitly approved
# (read-only SELECTs to CSV; review for secrets before sharing)

# Preserve compose files + .env KEY NAMES ONLY
cp /var/www/html/dev/GHbilling/docker-compose.dev.yml ~/ghbilling-compose-backup.yml
docker compose -p ghbilling exec -T billing printenv | cut -d= -f1 | sort > ~/ghbilling-env-keys.txt
```

Also preserve (optional): screenshots of key legacy screens, and any useful
docs/code references for future GlassPortal work.

## Stage 2 — Dependency check (read-only)

Goal: prove nothing important depends on `:18180`.

- Check bookmarks / docs / scripts for `:18180` references.
- Check DNS / reverse-proxy config for routes pointing at the legacy runtime
  (read-only inspection).
- Check customer / team usage (ask; review habits).
- Check access logs for recent hits, if available:
  ```bash
  docker compose -p ghbilling logs --since 720h ghbilling-portal-1 | tail -n 200   # read-only
  ```
- **Verify no pilot workflow depends on `:18180`** — the pilot targets `:18188`
  only (run `php artisan glassportal:pilot-readiness`).

## Stage 3 — Restrict-or-retire decision (decision, no commands)

Choose one option. Record the choice + rationale; do not act yet.

| Option | Pros | Cons |
|---|---|---|
| **A. Leave online, label legacy** | Zero risk; reversible | Confusion/surface persist |
| **B. Restrict to private / VPN / admin IPs** | Cuts public exposure; reversible | Needs proxy/firewall change (future phase) |
| **C. Redirect `:18180` → `:18188`** | One canonical URL; preserves bookmarks | Needs routing change; legacy UI gone |
| **D. Stop containers, keep volumes + backups** | Frees surface; data retained | Downtime; reversible only with care |
| **E. Fully remove later** | Clean end state | Irreversible; only after A–D proven safe |

## Stage 4 — Approved execution window (gated)

**Required before any action:**
- [ ] Explicit, written operator approval of a specific option (A–E).
- [ ] Verified, restorable backup from Stage 1.
- [ ] A prepared **rollback command** (see below).
- [ ] Stated expected downtime / customer impact (expected: none for the pilot,
      which uses `:18188`).

Only with all four checked does execution proceed — in a separately approved phase.

## Stage 5 — Post-change validation (read-only)

After any approved change:

```bash
curl -sS -o /dev/null -w 'portal :18188 -> %{http_code}\n' http://40.160.61.180:18188/login
curl -sS -o /dev/null -w 'legacy :18180 -> %{http_code}\n' http://40.160.61.180:18180/login   # expect the chosen option's behavior
docker compose -p glassportal-source exec -T app php artisan glassportal:healthcheck
docker compose -p glassportal-source exec -T app php artisan glassportal:pilot-readiness
```

- Verify `:18188` works and the GlassPortal pilot flow is intact.
- Verify `:18180` shows the **expected** behavior for the chosen option.
- Verify no missing data (compare against the Stage 1 export).
- Verify healthcheck + pilot-readiness still pass.

---

## ⚠️ Future approved execution only — DO NOT RUN in Phase 29B

These are **destructive or service-affecting** and are listed for reference only.
They require Stage 4 approval + a verified backup + a tested rollback. They are
**not** part of Phase 29B and must not be run now.

```bash
# Stop the legacy stack but KEEP volumes (Option D) — service-affecting:
#   docker compose -p ghbilling stop

# Rollback for Option D (restart the preserved stack):
#   docker compose -f ~/ghbilling-compose-backup.yml -p ghbilling up -d

# Bring the stack down but KEEP named volumes (still recoverable):
#   docker compose -p ghbilling down            # (NOT --volumes)

# Full removal (Option E) — IRREVERSIBLE, a separate approved phase only:
#   docker compose -p ghbilling down --volumes  # destroys data — never in 29B
```

Never use `down --volumes` / volume deletion as a primary step. Volumes and
backups are retained until a separate removal phase is explicitly approved after
Stage 5 validation passes.
