# State — Repository Map

> **Drift anchor.** Which repository hosts the active code, and the status of the
> others. Confirm this before moving code or importing from another repo.
> Authoritative decision record:
> [`docs/architecture/repository-consolidation.md`](../architecture/repository-consolidation.md)
> (Phase 28A + the Phase 29B/29C amendment). Last reviewed: Phase 29B correction.
>
> **Correction:** standalone GlassBilling is **not** legacy/dead — it is a
> preserved / reference / **potential canonical billing service** pending the
> **Phase 29C** reconciliation.

## Repositories

| Repository | Status | Role |
|---|---|---|
| **`FuzzySpace/GlassPortal`** | **Canonical app; active billing code today** | The application. All current development — including the embedded GlassBilling-domain **module** (`config/billing.php`, `app/Services/Billing/*`, `app/Models/Billing*`, `billing_*` tables, billing views/tests) — happens here. |
| `FuzzySpace/GlassBilling` (standalone) | **Preserved / reference / potential canonical (pending Phase 29C)** | An **existing billing/provisioning service designed to integrate with GlassPortal and GlassPanel.** NOT legacy/dead. Not the home of the *current* active billing code, but a candidate canonical billing service pending the 29C reconciliation. Do not retire/archive/delete/dismiss; do not migrate data or move code; do not import blindly (source-control import + security review first). |
| **SIONA** | **Separate repo** | External AI-sales module. GlassPortal integrates via signed/back-channel launch + tenant provisioning. **Do not modify the SIONA repo** from here. |
| **GHpanel / LXC 310** | **Legacy / reference only** | Legacy GlassPanel game-server runtime (Legacy GlassPanel Reference #001 / Migration Center Test Case #001). **Do not reuse or import** its code; review only after source-control import + security review. |

## Rules (do / don't)

- **Do** keep the GlassBilling module bounded inside GlassPortal using the
  existing conventions (config/services/models/tables/views/docs/tests).
- **Don't** move billing code to the standalone `FuzzySpace/GlassBilling` repo
  unless a future, explicit extraction phase is approved (its own ADR).
- **Don't** blindly import standalone-repo, SIONA, or GHpanel/LXC 310 code.
- **Don't** modify the SIONA or standalone GlassBilling repositories from
  GlassPortal work.

## Relationship to the runtime map

The **repository** map (this file) and the **runtime** map
([`runtime-map.md`](./runtime-map.md)) are two views of the same story:
GlassPortal hosts the active code and is the canonical pilot runtime today; the
standalone GlassBilling repo and its `:18180` runtime are the **preserved /
potential-canonical** counterparts. Both the **runtime** consolidation (Phase
29B plan) and the **billing-service** reconciliation (**Phase 29C**) are
**pending** — nothing is retired or moved meanwhile.
