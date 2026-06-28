# ADR: Runtime Consolidation Plan (Standalone Billing ↔ Canonical GlassPortal)

> **⚠️ Correction (Phase 29B → 29C pending).** Throughout this ADR, the
> `:18180` / `ghbilling` runtime is the deployment of the **standalone
> GlassBilling service** — an existing **billing/provisioning service designed to
> integrate with GlassPortal and GlassPanel**. It is **preserved / reference /
> potential canonical billing service**, **not** legacy/dead. Whether it becomes
> the canonical billing service is the **Phase 29C** reconciliation. Where the
> text below says "legacy," read "standalone / preserved / potential canonical."
> Do not retire, archive, delete, dismiss, migrate, or move it.

- **Status:** Accepted (Phase 29B) — **plan only; no runtime change**
- **Date:** 2026-06-28
- **Related:**
  [`docs/architecture/repository-consolidation.md`](./repository-consolidation.md) (Phase 28A),
  [`docs/state/runtime-map.md`](../state/runtime-map.md),
  [`docs/state/legacy-billing-runtime-inventory.md`](../state/legacy-billing-runtime-inventory.md),
  [`docs/runbooks/runtime-consolidation.md`](../runbooks/runtime-consolidation.md),
  [`docs/phase29/runtime-exposure-inventory.md`](../phase29/runtime-exposure-inventory.md)
- **Scope:** Documentation + planning + advisory checks **only**. Phase 29B
  changes no runtime: no container is stopped/removed, no port mapping, DNS,
  Traefik/Nginx/NAT/firewall changed, no database migrated/merged, no Stripe
  change, no customer-facing behavior change.

---

## Context

Phase 28A made **GlassPortal the canonical application repo** and GlassBilling a
bounded module inside it. Phase 29 made **GlassPortal `:18188` the canonical
pilot target** while the **standalone billing runtime stayed public on `:18180`**.
That left one thing unresolved: **two billing-capable runtimes are publicly
reachable at once.** This ADR records a *controlled, staged plan* to consolidate
them — and deliberately stops short of executing any of it.

## Current runtime map

| | Canonical | Legacy / reference |
|---|---|---|
| **Public URL** | http://40.160.61.180:18188/login | http://40.160.61.180:18180/login |
| **App** | GlassPortal | Standalone billing app |
| **Container** | `glassportal-source-app-1` (8088) | `ghbilling-portal-1` (3000) UI + `ghbilling-billing-1` (8080) API |
| **Compose project** | `glassportal-source` | `ghbilling` |
| **Role** | **Pilot/test here.** | Reference only. |

### Public URL map
- `:18188` → canonical GlassPortal.
- `:18180` → legacy billing UI.

### Container map (host `lxc-gh-glassbilling-pr2-01`)
- `glassportal-source-app-1` → 8088 (canonical app)
- `ghbilling-billing-1` → 8080 (legacy billing API)
- `ghbilling-portal-1` → 3000 (legacy billing UI)
- `ghbilling-postgres-1` → 5432 (local only) — legacy DB
- `ghbilling-redis-1` → 6379 (local only)
- `ghbilling-mailhog-1` → 1025 / 8025 (local mail catcher)

### Compose project map
- `glassportal-source` — the canonical app stack.
- `ghbilling` — the legacy billing stack (the six `ghbilling-*` containers).

## Canonical target runtime

**GlassPortal (`:18188`, project `glassportal-source`, container
`glassportal-source-app-1`).** All pilot/product-test work targets this runtime.

## Standalone runtime status

**Online and preserved as a potential canonical billing service (pending Phase
29C).** The `ghbilling` project keeps running on `:18180`. It is the deployment
of the standalone GlassBilling service (integrates with GlassPortal + GlassPanel)
— **not** legacy/dead, and **not** retired, restricted, redirected, migrated, or
moved in this phase.

## What is known

- The public URLs, container names, exposed ports, and compose project names
  above (operator-supplied runtime evidence).
- A repo/path clue for the legacy stack:
  `/var/www/html/dev/GHbilling/docker-compose.dev.yml`.
- GlassPortal already implements the billing → checkout → webhook → entitlement →
  provisioning-request → self-service flow (Phases 24–29).

## What remains unknown (requires operator/manual verification)

- Whether the legacy DB (`ghbilling-postgres-1`) holds **useful customer
  records**, **product definitions**, or **Stripe/test configuration**.
- Whether browser bookmarks / team habits / external links point at `:18180`.
- Whether any data must be **exported before** any shutdown.
- Whether the `:3000` frontend (`ghbilling-portal-1`) is still needed.
- Whether any current pilot workflow depends on `:18180` (expected: none).

These are enumerated in
[`legacy-billing-runtime-inventory.md`](../state/legacy-billing-runtime-inventory.md)
and worked through in Stages 0–2 of the runbook.

## Risks

### Risk of leaving both public (status quo)
- **Operator/customer confusion** — testing or bookmarking the wrong URL (the
  pilot readiness checks mitigate this with warnings).
- **Drift** — the legacy runtime could be mistaken for the source of truth.
- **Surface area** — an extra public, possibly-unmaintained app stays exposed.

### Risk of shutting legacy billing down too early
- **Data loss** — unexported customer/product/Stripe data in `ghbilling-postgres-1`.
- **Broken links/habits** — bookmarks, docs, or external references to `:18180`.
- **Hidden dependency** — some workflow not yet covered by GlassPortal.
- **No rollback** — stopping without a backup/snapshot is irreversible.

The asymmetry is deliberate: *leaving it up* is low-risk and reversible;
*taking it down* is high-risk and must be gated.

## Safe consolidation stages (summary)

Detailed in [`runtime-consolidation.md`](../runbooks/runtime-consolidation.md):

- **Stage 0 — No-change inventory.** Confirm projects/containers/URLs; capture
  route lists, env *key names only*, table *names only*, volumes, compose files.
- **Stage 1 — Preservation.** Backup the legacy DB; export schema/table list;
  export product/customer metadata *if approved*; preserve compose + `.env` key
  names + useful docs/code references.
- **Stage 2 — Dependency check.** Bookmarks/docs/scripts, DNS/proxy refs,
  customer/team usage, access logs; verify no pilot workflow depends on `:18180`.
- **Stage 3 — Restrict-or-retire decision.** Choose among A–E (below).
- **Stage 4 — Approved execution window.** Explicit approval + backup + rollback
  command + stated downtime/impact.
- **Stage 5 — Post-change validation.** Verify `:18188`, expected `:18180`
  behavior, GlassPortal pilot flow, no missing data, healthcheck/pilot-readiness.

### Stage 3 options
| Option | Pros | Cons |
|---|---|---|
| **A. Leave online, label legacy** | Zero risk; fully reversible | Confusion/surface persist |
| **B. Restrict to VPN/admin IPs** | Cuts public exposure; reversible | Needs proxy/firewall change (future phase) |
| **C. Redirect `:18180` → `:18188`** | One canonical URL; preserves bookmarks | Needs routing change; legacy UI gone |
| **D. Stop containers, keep volumes/backups** | Frees surface; data retained | Downtime; reversible only with care |
| **E. Fully remove later** | Clean end state | Irreversible; only after A–D proven safe |

## Approval gates

1. **Gate 0 → 1:** operator confirms inventory is captured (no change yet).
2. **Gate 1 → 2:** backup/export verified to exist and be restorable.
3. **Gate 2 → 3:** dependency check shows nothing depends on `:18180`.
4. **Gate 3 → 4:** explicit, written approval of a specific option (A–E) **and** a
   maintenance window.
5. **Gate 4 → 5:** rollback command prepared and tested before execution.

No gate may be skipped. Each later gate presumes the earlier ones passed.

## Rollback strategy

- Until Stage 4, rollback is trivial: **nothing changed.**
- For any Stage 4 action, the rollback is to **restart the `ghbilling` project**
  from its preserved compose file and (if needed) **restore the DB** from the
  Stage 1 backup. Volumes are retained until Stage 5 validation passes and a
  separate removal phase is approved.

## Final target state

A single canonical billing-capable runtime — **GlassPortal `:18188`** — with the
legacy `ghbilling` stack either redirected (C), access-restricted (B), or stopped
with retained backups (D), and only **fully removed (E)** in a later, separately
approved phase after validation. The exact end option is chosen at Stage 3 with
operator approval; this ADR does not pre-commit to one.

---

## Decision statement

- **Short term:** Keep **both runtimes online** while pilot testing targets
  GlassPortal on `:18188`. The standalone billing runtime stays preserved.
- **Medium term:** **Inventory** the standalone Billing service's data, routes,
  UI behavior, environment variables, and any useful code/docs — input to the
  **Phase 29C** billing-service reconciliation — **before** any retirement/restrict
  decision.
- **Long term:** **Retire or restrict `:18180` only after** the Phase 29C
  reconciliation, explicit approval, backup/export, validation that nothing
  depends on it, and confirmation of the canonical billing service. The standalone
  service is **preserved / potential canonical** until then — not legacy/dead.

## What Phase 29B explicitly does NOT do

Does not shut down `:18180`, redirect `:18180`, stop/remove `ghbilling`
containers, delete volumes, merge databases, migrate customer records, modify
public DNS/routing/firewall/Traefik/Nginx/NAT/Proxmox, change Stripe config, or
alter the GlassPortal customer flow. It documents, checks, and plans only.
