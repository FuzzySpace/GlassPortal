# Runbook — AI / Operator Preflight

**Purpose:** the mandatory orientation checklist before any human operator or AI agent works on the Glasshouse estate. Its goal is to prevent the two recurring failure modes: testing the wrong runtime, and "fixing" architecture that is intentionally in a reconciled-but-not-consolidated state.

## 1. Read first

Read, in order: `docs/state/runtime-map.md` (which URL is canonical), `docs/state/repository-map.md` (which repo owns what), `docs/state/phase-status.md` (where the program is), and `docs/architecture/glassportal-glassbilling-reconciliation.md` §9 (approval gates). For billing work also read `docs/architecture/commercial-v1-decision.md` and `docs/state/sdk-contract-map.md`.

## 2. Orientation checks

Confirm the pilot portal is :18188, not :18180 — the companion GlassBilling runtime at :18180 must never be used for pilot/commercial validation. Confirm the branch you are on and that you are in the intended repository (`git remote -v`). Run `php artisan glassportal:healthcheck` and `php artisan glassportal:commercial-readiness` to see current state before changing anything.

## 3. Standing prohibitions (no approval on file = do not do)

No destructive runtime actions; no stopping/redirecting :18180; no database merges; no production data migration; no Stripe live-mode changes; no real infrastructure provisioning; no deleting or archiving repositories; no removal of legacy runtime paths; no new billing features in GlassPortal beyond the 29D freeze scope; no routing of the standalone Stripe webhook controller while the portal consumer is live.

## 4. When in doubt

If a task appears to require any prohibited action, stop and request explicit founder/operator approval, citing the relevant approval gate in the reconciliation ADR. Record decisions in the appropriate docs/state file so the next operator does not re-derive them.

