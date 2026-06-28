# Phase 29C (scoping) — Billing Architecture Reconciliation

- **Status:** Open / scoping — **no architecture decision is finalized here.**
- **Date:** 2026-06-28
- **Related:**
  [`repository-consolidation.md`](./repository-consolidation.md) (28A + 29B/29C amendment),
  [`runtime-consolidation-plan.md`](./runtime-consolidation-plan.md) (29B),
  [`billing-source-of-truth.md`](./billing-source-of-truth.md) (23),
  [`../state/repository-map.md`](../state/repository-map.md),
  [`../state/legacy-billing-runtime-inventory.md`](../state/legacy-billing-runtime-inventory.md),
  [`../state/phase-status.md`](../state/phase-status.md)
- **Scope:** Documentation + scoping only. No code/data is moved, no service is
  retired, no Stripe behavior changes.

> **⛔ Finalization gate.** **Do not finalize the GlassPortal / GlassBilling
> architecture** until **every required track below is complete** — in
> particular, until the **SDK / API contract has been inventoried, compared, and
> mapped** (Track 2). Until then the standalone GlassBilling service is
> **preserved / reference / potential canonical** — not retired, archived,
> deleted, dismissed, migrated, or moved.

---

## The reconciliation question

Two billing implementations now coexist:

1. **Embedded GlassBilling-domain module inside GlassPortal** (Phases 24–28) —
   the current active billing implementation (`config/billing.php`,
   `app/Services/Billing/*`, `app/Models/Billing*`, `billing_*` tables, billing
   views/tests).
2. **Standalone GlassBilling service** — an existing **billing/provisioning
   service designed to integrate with GlassPortal *and* GlassPanel**, running on
   `:18180` (project `ghbilling`). Preserved / potential canonical.

**Which is the canonical billing/provisioning service long-term — the embedded
module, the standalone service, or a reconciled hybrid?** Phase 29C answers this.
This doc only scopes the work and locks the gates; it decides nothing yet.

## Required reconciliation tracks

All tracks must complete before the architecture is finalized.

### Track 1 — Canonical billing service decision
Decide embedded module vs standalone service vs hybrid, with the rationale,
migration cost, and operational impact. Depends on Tracks 2–4.

### Track 2 — SDK / API contract parity *(REQUIRED — finalization gate)*
**Inventory, compare, and map** the SDK/API contracts on all sides **before**
any architecture is finalized:

- **Inventory** — enumerate the contract surface of each side:
  - the **embedded module** (its services / DTOs / events / the HTTP surface it
    exposes today: `/api/billing/stripe/webhook`, portal/admin billing routes,
    `StripeBillingClient`, entitlement + provisioning-request services);
  - the **standalone GlassBilling service** (its REST/API + any client SDK,
    auth model, webhooks, data model);
  - the **GlassPanel integration contract** that the standalone service is
    designed to satisfy.
- **Compare** — capability-by-capability parity: what each side can do, request/
  response shapes, auth, idempotency, error semantics, eventing, versioning.
- **Map** — produce a mapping between the contracts (1:1, gap, divergence) so a
  consumer could target either without behavior change, and so gaps are explicit.

Until this inventory/compare/map exists and is reviewed, **the architecture is
not finalized.** No SDK/contract is the "winner" by default.

#### SDK / API contract parity matrix (to be filled in 29C)

| Capability / surface | Embedded module (GlassPortal) | Standalone GlassBilling service | GlassPanel integration contract | Parity status | Notes |
|---|---|---|---|---|---|
| Customers | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Products / plans | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Subscriptions | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Invoices / payments | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Checkout | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Webhooks / events | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Entitlements | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Provisioning requests | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Auth / signing | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |
| Versioning | _tbd_ | _tbd_ | _tbd_ | _tbd_ | |

_Parity status legend:_ `match` / `gap` / `divergent` / `unknown`.

### Track 3 — Data ownership / source of truth
Reconcile which side owns which billing facts (see Phase 23
`billing-source-of-truth.md`), what data exists in each runtime, and the
migration/coexistence story. No data is moved/merged until decided.

### Track 4 — Runtime consolidation alignment
Align with the Phase 29B runtime-consolidation plan (the `:18180` runtime stays
online and preserved until approved). The runtime decision follows the canonical-
service decision, not the other way around.

## Finalization gates (all required)

1. Track 2 (**SDK/API contract parity**) inventory + comparison + mapping
   complete and reviewed.
2. Track 3 data-ownership reconciliation complete.
3. Track 1 canonical-service decision made with explicit rationale + ADR.
4. Track 4 runtime plan aligned to the decision.
5. Backup/export + rollback prepared before any execution (per 29B runbook).

**No gate may be skipped, and the architecture is not "final" until all are
green.**

## What this phase does NOT do

Decide the canonical billing service; move/migrate any code or data; retire,
archive, delete, or dismiss the standalone GlassBilling service/repo; change
Stripe behavior; or alter the GlassPortal customer flow. It scopes the
reconciliation and **locks the SDK/API-parity finalization gate.**
