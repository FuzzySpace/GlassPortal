# Phase 29C — Runtime Consolidation Plan

**Date:** 2026-07-03 · **Status:** Accepted (plan only — no runtime changes in this phase) · **Companions:** `docs/state/legacy-billing-runtime-inventory.md`, `docs/runbooks/runtime-consolidation.md`

## 1. Corrected framing

Earlier documents (Phase 28A) described the standalone GlassBilling repository as "legacy / reference only." That wording is corrected as follows and supersedes prior language wherever they conflict:

> **GlassBilling is an existing companion billing/provisioning service.** The recent GlassPortal billing work (Phases 23–28) is **embedded pilot/reference functionality until reconciliation is complete**. The GlassPortal runtime at **:18188 remains the current pilot portal**. The GlassBilling runtime at **:18180 remains online and preserved**. **Runtime consolidation remains pending** and requires explicit approval.

GlassBilling is not permanently dead, not archived, and not slated for deletion. Its runtime, database, and code are preserved assets pending the Stage E ownership decision recorded in `glassportal-glassbilling-reconciliation.md` §7.

## 2. Public URL map

| URL | System | Role | Rule |
| :--- | :--- | :--- | :--- |
| `http://40.160.61.180:18188/login` | GlassPortal | **Canonical pilot portal** — the only URL used for pilot/commercial testing and customer onboarding | Keep |
| `http://40.160.61.180:18180/login` | GlassBilling (standalone) | Preserved companion runtime | Do not shut down, redirect, or repoint; do not test it as if it were the pilot portal |

## 3. Container and compose map

| Compose project | Container | Internal port(s) | Notes |
| :--- | :--- | :--- | :--- |
| `glassportal-source` | `glassportal-source-app-1` | 8088 | Serves :18188 |
| `ghbilling` | `ghbilling-billing-1` | 8080 | Laravel billing API; serves :18180 |
| `ghbilling` | `ghbilling-portal-1` | 3000 | Next.js billing portal |
| `ghbilling` | `ghbilling-postgres-1` | 5432 (local only) | Dedicated PostgreSQL — **distinct from portal DB; never merge without approval** |
| `ghbilling` | `ghbilling-redis-1` | 6379 (local only) | Queue/cache for billing stack |
| `ghbilling` | `ghbilling-mailhog-1` | 1025 / 8025 | Mail capture (dev/test) |

## 4. Database and volume inventory needs (unknowns to resolve before any consolidation)

Before Stage E/F, an operator-run inventory must capture: the `ghbilling-postgres-1` database list, per-table row counts (especially customers, services, invoices, provisioning requests — to establish whether real pilot data exists there or it is seed/demo data); Docker volume names, sizes, and backup status for both compose projects; whether any cron/Horizon jobs are active in the ghbilling stack; whether any external system (Stripe webhook config, GlassPanel, DNS, mail) currently points at :18180 or the ghbilling containers; and the env/secret files each stack loads. The template for recording this is in `docs/state/legacy-billing-runtime-inventory.md`. None of these are collected by automation in this phase because they require production access.

## 5. Risks

**Of leaving both public:** operator/tester confusion (mitigated by the drift-guard checks and this URL map); divergent data entry if anyone transacts on :18180 (mitigated: treat :18180 as read/reference only); attack surface of a dormant, untested stack exposed publicly (recommend firewall/allowlist review at operator discretion — a recommendation, not an action of this phase); accidental Stripe webhook configuration pointing at the standalone stack (mitigated by single-consumer checks in 29D).

**Of shutting GlassBilling down early:** loss of the only implementation of provisioning execution, connector drivers, and ledger modeling before their value is harvested; loss of any real data in its PostgreSQL before inventory; breaking the portal's read-bridge pages (`/admin/provisioning`, `/admin/customers`, `/admin/billing-approvals`) that surface its data; and foreclosing the Stage E ownership decision that 29C explicitly leaves open.

## 6. Safe consolidation stages and approval gates

Consolidation follows the staged model in the reconciliation ADR: **Stage D** (contract parity fixes, fixtures in CI) → **Stage E** (ownership consolidation ADR: extract embedded module vs. absorb standalone components vs. hybrid; includes data migration and rollback plans) → **Stage F** (runtime consolidation proper: retire, repurpose, or promote :18180 per the Stage E decision). Each stage requires explicit founder/operator approval, a written change plan, a rollback plan, and — for Stage F — verified backups of the ghbilling PostgreSQL and volumes. Until Stage F is approved and executed, both runtimes stay up unchanged.

## 7. Rollback posture

Because this phase changes nothing at runtime, rollback for 29C is trivial (revert docs). For future stages, the runbook (`docs/runbooks/runtime-consolidation.md`) requires: pre-change database dumps and volume snapshots for any touched stack; DNS/port mappings recorded before change; a tested restore path; and a hard rule that no consolidation step may destroy the ability to restart the ghbilling stack as-is until the operator signs off on final decommissioning — which is itself out of scope for the commercial v1 program.
