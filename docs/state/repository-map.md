# State — Repository Map

> **Drift anchor.** Which repository is canonical and which are legacy/reference.
> Confirm this before moving code or importing from another repo. Authoritative
> decision record: [`docs/architecture/repository-consolidation.md`](../architecture/repository-consolidation.md)
> (Phase 28A). Last reviewed: Phase 29 (safeguard addendum).

## Repositories

| Repository | Status | Role |
|---|---|---|
| **`FuzzySpace/GlassPortal`** | **Canonical, active** | The application. All current development — including the GlassBilling billing **module** (`config/billing.php`, `app/Services/Billing/*`, `app/Models/Billing*`, `billing_*` tables, billing views/tests) — happens here. |
| `FuzzySpace/GlassBilling` (standalone) | **Legacy / reference** | The separate standalone billing repo. Not the home of active billing code. Treat as legacy/reference; do not import blindly (source-control import + security review first). |
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
([`runtime-map.md`](./runtime-map.md)) are two views of the same consolidation
story: GlassPortal is canonical in *both* code and deployment; the standalone
billing repo and the `:18180` billing runtime are the legacy/reference
counterparts. Runtime consolidation is **pending** (see runtime-map).
