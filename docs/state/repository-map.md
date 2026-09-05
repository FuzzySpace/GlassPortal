# Repository Map (Drift Guard)

**Date:** 2026-07-03 · **Status:** Authoritative

| Repository | Role | State | Rules |
| :--- | :--- | :--- | :--- |
| `FuzzySpace/GlassPortal` | Canonical active application: portal shell + embedded GlassBilling domain module (pilot/commercial v1) | Active (Phases 1–29E track) | All new v1 work lands here; billing feature freeze beyond 29D scope |
| `FuzzySpace/GlassBilling` | Companion billing/provisioning service and component donor (orchestrator, drivers, ledger models) | Preserved, dormant since 2026-05-11 (its own Phases 1–7 track) | Do not archive, delete, or mutate without an approved change; not "legacy/dead" |
| GlassPanel repo | Execution plane (referenced; not in this workspace) | External | Integrated post-v1 via GlassBilling-domain provisioning jobs only |
| `FuzzySpace/Glasshosting-WHMCS` | WHMCS estate (billing frontend history) | Separate track | Out of commercial v1 scope |

**Phase numbering rule:** GlassPortal phase numbers (1–29E) and GlassBilling standalone phase numbers (1–7) are independent tracks. Always qualify phase references with the repo name, e.g. "GP-Phase 5" vs. "GB-Phase 5".
