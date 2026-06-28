# Phase 28A — Repository Consolidation ADR

A **documentation-and-decision-locking** phase. It moves no code, changes no
behavior, and touches no other repository. It records, in one place, which git
repository is the canonical home of the active application — including the
GlassBilling module built across Phases 23–28.

> Full decision record: [`docs/architecture/repository-consolidation.md`](../architecture/repository-consolidation.md).

---

## Purpose

Make the implicit explicit: lock in that **GlassPortal is the canonical active
repository** and that **GlassBilling is a bounded module inside GlassPortal**,
so future maintainers don't split active development across repos or blindly
import legacy code from the standalone `FuzzySpace/GlassBilling` repository.

## Decision summary

- **Short term:** continue active GlassBilling development **inside GlassPortal**.
- **Medium term:** keep billing boundaries clean (naming, services, config,
  routes, docs, tests) so the module could be extracted cleanly later.
- **Long term:** extract GlassBilling only with a clear business reason, stable
  APIs, and operational capacity for separate deployment/versioning.

## Affected repositories

| Repository | Status after this phase |
|---|---|
| `FuzzySpace/GlassPortal` | **Canonical, active.** Home of all current billing development. Only this repo is edited (docs only). |
| `FuzzySpace/GlassBilling` (standalone) | **Legacy / reference.** Untouched — not deleted, archived, renamed, or mutated. |
| SIONA, GlassBilling standalone, GHpanel / LXC 310 | **Not touched.** |

## Current active code location

The working GlassBilling system already lives in GlassPortal:

- `config/billing.php`
- `app/Services/Billing/*`
- `app/Models/Billing*`
- `billing_*` database tables (migrations)
- `resources/views/admin/billing/*`, `resources/views/portal/billing/*`
- billing tests under `tests/Unit/Billing/*` + feature tests
- `docs/architecture/billing-*`, `docs/phase23/` … `docs/phase28/`

## Future extraction criteria

Extraction is justified only when most of these hold: a real business need for
billing beyond the portal, stable/versioned billing APIs consumed through a
narrow surface, operational capacity for a second deployment + on-call +
migration story, independent scaling/availability needs, and already-clean seams.
Absent those, keeping billing in-portal is the correct, lower-risk choice. See
the ADR's "What justifies a future extraction" and "Migration / extraction rules"
sections.

## Operational impact

- **None at runtime.** No code moved, no namespaces renamed, no behavior changed.
  Stripe flow, webhook intake, the provisioning request engine, and customer
  billing self-service are all unchanged.
- **Deployment unchanged:** billing continues to deploy as part of GlassPortal.
- **Healthcheck:** two lightweight, **advisory (non-blocking)** doc checks were
  added — `architecture.repository_consolidation_doc` and
  `architecture.glassbilling_boundary_doc` — mirroring the existing Phase 23 ADR
  doc checks. They warn (never fail) if the docs go missing and never print
  secrets.

## Developer guidance

- New billing work goes in GlassPortal under the conventions above.
- Do **not** move billing code to the standalone `FuzzySpace/GlassBilling` repo
  unless a future explicit extraction phase is approved.
- Do **not** blindly import old standalone-repo code; if reviewed later, treat it
  as legacy/reference subject to source-control import + security review.
- Keep billing namespaced and bounded using the existing conventions.

(Also captured in [`CLAUDE.md`](../../CLAUDE.md) → "Repository consolidation".)

## Tests / validation performed

- Documentation files exist:
  `docs/architecture/repository-consolidation.md`,
  `docs/phase28a/repository-consolidation-adr.md`.
- Healthcheck updated with the two advisory architecture doc checks + tests;
  `php artisan glassportal:healthcheck` exits **0**.
- Full test suite: **`php artisan test` — all green** (see completion report for
  the exact count). Validated on the local sqlite path (Docker unavailable in
  this environment).

## Out of scope (explicitly not done)

Moving billing files, renaming namespaces, deleting code, modifying the
standalone GlassBilling repo, changing the Stripe flow / customer billing self-
service / provisioning request engine, new product features, AI agents,
telemetry/consent, and any change to SIONA or GHpanel / LXC 310.
