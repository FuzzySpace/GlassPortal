# Repository Map (Drift Guard)

**Date:** 2026-07-03 · **Status:** Authoritative

| Repository | Role | State | Rules |
| :--- | :--- | :--- | :--- |
| `FuzzySpace/GlassPortal` | Canonical active application: portal shell + embedded GlassBilling domain module (pilot/commercial v1) | Active (Phases 1–29E track) | All new v1 work lands here; billing feature freeze beyond 29D scope |
| `FuzzySpace/GlassBilling` | Companion billing/provisioning service and component donor (orchestrator, drivers, ledger models) | Preserved, dormant since 2026-05-11 (its own Phases 1–7 track) | Do not archive, delete, or mutate without an approved change; not "legacy/dead" |
| SIONA | External AI-sales module | Separate repo | GlassPortal integrates via signed/back-channel launch + tenant provisioning. Do not modify the SIONA repo from here. |
| GlassPanel repo / GHpanel (LXC 310) | Execution plane / legacy GlassPanel game-server runtime (referenced; not in this workspace) | External / reference only | Integrated post-v1 via GlassBilling-domain provisioning jobs only. Do not reuse or import GHpanel/LXC 310 code without source-control import + security review. |
| `FuzzySpace/Glasshosting-WHMCS` | WHMCS estate (billing frontend history) | Separate track | Out of commercial v1 scope |

**Phase numbering rule:** GlassPortal phase numbers (1–29E) and GlassBilling standalone phase numbers (1–7) are independent tracks. Always qualify phase references with the repo name, e.g. "GP-Phase 5" vs. "GB-Phase 5".

## Rules (do / don't)

- **Do** keep the GlassBilling module bounded inside GlassPortal using the existing conventions (config/services/models/tables/views/docs/tests).
- **Don't** move billing code to the standalone `FuzzySpace/GlassBilling` repo unless a future, explicit extraction phase is approved (its own ADR).
- **Don't** blindly import standalone-repo, SIONA, or GHpanel/LXC 310 code.
- **Don't** modify the SIONA or standalone GlassBilling repositories from GlassPortal work.

## Relationship to the runtime map

The **repository** map (this file) and the **runtime** map ([`runtime-map.md`](./runtime-map.md)) are two views of the same consolidation story: GlassPortal is canonical in *both* code and deployment. Runtime consolidation is **pending** (see runtime-map).
