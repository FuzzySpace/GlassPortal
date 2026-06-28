# State — Standalone Billing Runtime Inventory

> _(Filename `legacy-billing-runtime-inventory.md` is historical; see the
> corrected status below — this runtime is **not** legacy/dead.)_
>
> **Drift anchor + consolidation input.** What is *known* about the standalone
> Billing runtime, and what is *unknown* and must be verified by an operator
> before any consolidation step. Documentation only — **the runtime is not
> modified, stopped, or retired in Phase 29B.** Plan:
> [`docs/architecture/runtime-consolidation-plan.md`](../architecture/runtime-consolidation-plan.md);
> runbook: [`docs/runbooks/runtime-consolidation.md`](../runbooks/runtime-consolidation.md).
> Last reviewed: Phase 29B correction.

## Status

**Standalone billing/provisioning service runtime — preserved / reference /
potential canonical, pending Phase 29C. DO NOT RETIRE.** This is an existing
billing/provisioning service **designed to integrate with GlassPortal and
GlassPanel** — **not** legacy/dead. Online and public on `:18180`. Whether it
becomes the canonical billing service (vs the embedded GlassPortal module) is the
**Phase 29C** architectural reconciliation. **Do not retire, archive, delete,
dismiss, migrate data, or move code**; any restriction/redirect/stop is pending
the staged plan + explicit approval.

## Known inventory

| Item | Value |
|---|---|
| Public URL | http://40.160.61.180:18180/login |
| Internal billing container | `ghbilling-billing-1` |
| Exposed internal port (billing API) | 8080 |
| Associated frontend | `ghbilling-portal-1` on 3000 |
| Associated Postgres | `ghbilling-postgres-1` (local 5432) |
| Associated Redis | `ghbilling-redis-1` (local 6379) |
| Associated Mailhog | `ghbilling-mailhog-1` (1025 / 8025) |
| Compose project | `ghbilling` |
| Repo/path clue | `/var/www/html/dev/GHbilling/docker-compose.dev.yml` |

(The canonical GlassPortal runtime, for contrast, is `glassportal-source-app-1`
on `8088` → public `:18188`, compose project `glassportal-source`. See
[`runtime-map.md`](./runtime-map.md).)

## Unknowns requiring manual / operator verification

These are **not** answered in this phase and must be checked before any Stage 3+
decision:

- [ ] Does it contain **useful customer records**?
- [ ] Does it contain **useful product definitions**?
- [ ] Does it have **Stripe / test configuration** worth preserving?
- [ ] Do any **browser bookmarks or team habits** point to `:18180`?
- [ ] Does anything **external link** to it?
- [ ] Must any **data be exported before shutdown**?
- [ ] Is the **frontend on `:3000`** (`ghbilling-portal-1`) still needed?

Until every box above is checked and recorded, the standalone runtime stays
online and preserved (do not retire/migrate/move).

## How these get answered (pointer)

Work the unknowns via the runbook:
- **Stage 0** (no-change inventory) — confirm projects/containers/URLs; capture
  table *names only*, env *key names only*, volumes, compose files.
- **Stage 1** (preservation) — backup the DB + export schema/metadata *if
  approved* before anything else.
- **Stage 2** (dependency check) — bookmarks/docs/scripts, DNS/proxy refs, usage,
  access logs; confirm no pilot workflow depends on `:18180`.

## Boundaries

This document records inventory and open questions only. It does **not** authorize
or perform any change to the `ghbilling` runtime, its containers, volumes,
database, or network exposure.
