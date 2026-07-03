# Runbook — Runtime Consolidation (Gated; Not for Commercial v1)

**Purpose:** the step-by-step procedure for the *future, explicitly approved* consolidation of the GlassPortal (:18188) and GlassBilling (:18180) runtimes. **No step in this runbook may be executed without written founder/operator approval of the Stage E ownership ADR.** Commercial v1 ships with both runtimes unchanged.

## 1. Entry criteria (all must be true)

Stage E ownership ADR approved and merged; `docs/state/legacy-billing-runtime-inventory.md` operator fields completed; SDK parity check passing (see `docs/runbooks/sdk-parity-check.md`) with the `admin/customers` drift closed or formally waived; contract fixture tests green in CI; verified backups (PostgreSQL dump + volume snapshot) of the ghbilling stack taken within 24 hours; a maintenance window agreed; and a named rollback owner.

## 2. Consolidation paths (choose exactly one, per Stage E ADR)

**Path A — Embedded module remains canonical.** The portal's embedded billing module is confirmed as the permanent GlassBilling domain host. Steps: harvest wanted components from the standalone repo (orchestrator, drivers, ledger models) via reviewed PRs into the portal; migrate any real data found in ghbilling PostgreSQL into the portal DB with a scripted, reversible migration; switch the portal's read-bridge pages to embedded data; then (separate approval) retire the :18180 public exposure while archiving — not deleting — the ghbilling stack.

**Path B — Standalone service revived as canonical.** The standalone runtime becomes the billing brain behind the portal. Steps: bring the standalone repo to test parity (it currently has zero tests — blocking); route its unwired controllers; port the portal's tested Stripe checkout/webhook logic into it; repoint exactly one Stripe webhook consumer; convert the portal's embedded engine to the read/request bridge; migrate embedded `billing_*` data outward. This path is substantially more work and is only justified by scaling or isolation needs.

**Path C — Hybrid.** Billing intent/financial truth stays embedded; provisioning execution revives as a separate worker service derived from the standalone orchestrator/drivers. Data stays split by plane.

## 3. Universal safety steps (every path)

Take and verify backups before each mutating step; execute one mutating step per change window; after each step run `php artisan test`, `php artisan glassportal:healthcheck`, and `php artisan glassportal:commercial-readiness` on the portal; keep :18180 restartable as-is until final sign-off; never let two Stripe webhook consumers be live simultaneously; record every step, timestamp, and outcome in this runbook's log section.

## 4. Rollback

For any failed step: stop further changes; restore the affected database from the pre-step dump; restore volumes from snapshot; restart the original compose stacks; re-run the three portal commands above plus a manual login check on both URLs; record the failure and do not retry in the same window.

## 5. Execution log

| Date | Step | Operator | Outcome |
| :--- | :--- | :--- | :--- |
| — | (no consolidation steps executed as of 2026-07-03; plan only) | — | — |

