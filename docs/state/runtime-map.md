# State — Runtime Map

> **Drift anchor.** A concise, authoritative snapshot of the current runtime
> reality, kept under `docs/state/` so operators and AI workers can confirm the
> world before changing code. If this conflicts with what you observe, **stop and
> reconcile** — do not assume. Fuller detail:
> [`docs/phase29/runtime-exposure-inventory.md`](../phase29/runtime-exposure-inventory.md).
>
> Last reviewed: Phase 29B correction. This file documents reality; it changes no
> infrastructure.
>
> **Correction:** the `:18180` runtime is the **standalone GlassBilling service**
> (integrates with GlassPortal + GlassPanel) — **preserved / potential canonical**
> pending Phase 29C, **not** legacy/dead. Below, "legacy" container labels are
> historical descriptors of the `ghbilling-*` stack, not a retirement judgement.

## Public URLs

| Role | URL | Notes |
|---|---|---|
| **Canonical pilot target** | **http://40.160.61.180:18188** | GlassPortal. **Test here.** Login at `/login`. |
| Standalone billing (preserved / potential canonical, pending 29C) | http://40.160.61.180:18180 | Standalone GlassBilling service. Not the pilot target — **do not pilot here** — but **not** legacy/dead. |

- **Pilot target = `:18188`.** The readiness checks warn if the configured pilot
  target is the standalone `:18180`, or if the canonical URL is not `:18188`.

## Internal container mapping (host `lxc-gh-glassbilling-pr2-01`)

| Container | Port | Role |
|---|---|---|
| `glassportal-source-app-1` | 8088 | **Canonical GlassPortal app** (backs `:18188`) |
| `ghbilling-billing-1` | 8080 | Legacy billing API |
| `ghbilling-portal-1` | 3000 | Legacy billing portal/UI (backs `:18180`) |
| `ghbilling-postgres-1` | 5432 (local) | Legacy billing database |
| `ghbilling-redis-1` | 6379 (local) | Legacy cache/queue |
| `ghbilling-mailhog-1` | 1025 / 8025 | Legacy mail catcher |

## Current public port assumptions

- `:18188` → `glassportal-source-app-1:8088` (canonical GlassPortal).
- `:18180` → `ghbilling-portal-1:3000` (legacy billing portal).
- Local-only ports (`5432`, `6379`, `1025/8025`) are **not** publicly exposed.
- Host NAT / Traefik / Nginx own these mappings and are **out of scope** here.

## Systems NOT to modify during Phase 29

- Do **not** stop any container (the legacy `ghbilling-*` stack stays running).
- Do **not** change public port mappings (`:18188`, `:18180`).
- Do **not** modify host NAT, Traefik, or Nginx.
- Do **not** migrate data between the legacy billing runtime and GlassPortal.
- Do **not** merge databases (`ghbilling-postgres-1` is untouched).
- Do **not** redirect `:18180` → `:18188` (deferred to a future approved phase).

## Known unresolved

**Runtime consolidation is pending (planned in Phase 29B); billing-service
reconciliation is pending Phase 29C.** Two runtimes (`:18188` canonical pilot,
`:18180` standalone billing) coexist by design. Phase 29B produced the
controlled, staged **plan** — but executed none of it:

- The **standalone billing runtime remains online** on `:18180` (project
  `ghbilling`); it is **not** stopped, restricted, redirected, migrated, or moved.
- The standalone GlassBilling service is **preserved / potential canonical**
  (integrates with GlassPortal + GlassPanel) — **not** legacy/dead. Whether it is
  the canonical billing service is the **Phase 29C** reconciliation.
- **Retirement/restriction is pending** Phase 29C + explicit approval, a verified
  backup, a dependency check, and confirmation of the canonical billing service.
- The **canonical pilot target remains `:18188`**; `:18180` remains the
  preserved standalone runtime.

See the plan ([`../architecture/runtime-consolidation-plan.md`](../architecture/runtime-consolidation-plan.md)),
the legacy inventory ([`legacy-billing-runtime-inventory.md`](./legacy-billing-runtime-inventory.md)),
and the runbook ([`../runbooks/runtime-consolidation.md`](../runbooks/runtime-consolidation.md)).
