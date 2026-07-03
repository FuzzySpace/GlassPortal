# Release Notes — Glasshouse Commercial V1, Release Candidate 1

**Proposed tag:** `glasshouse-commercial-v1-rc1` · **Branch:** `glassportal-phase29c-29e-commercial-v1` · **Date:** 2026-07-03 · **Status:** Proposed (tag pending founder approval)

## 1. What this release is

This release candidate marks the point at which the GlassPortal runtime is considered ready for supervised commercial pilot use: real customers, real Stripe test-mode (and, with explicit approval, live-mode) payments, and manually fulfilled provisioning. It is the product of the Phase 29C architecture reconciliation, the Phase 29D commercial stabilization work, and the Phase 29E validation pass. It deliberately does **not** include automated infrastructure provisioning, database consolidation, or any change to the preserved standalone GlassBilling runtime.

## 2. Architecture decisions locked in this release

The Phase 29C reconciliation (see `docs/architecture/glassportal-glassbilling-reconciliation.md`) resolved the architecture creep between GlassPortal and GlassBilling with an evidence-based ownership map. For commercial v1: the **embedded billing module inside GlassPortal is the operational billing control plane** (products, plans, checkout, webhook intake, subscriptions, invoices, payments, entitlements, provisioning intent); the **standalone GlassBilling repo and runtime are preserved as a companion reference** — not dead, not archived, and not to be treated as the live system; **Stripe is owned by exactly one consumer** (the portal's `/api/billing/stripe/webhook`); and **GlassPanel remains the future execution plane**, with all provisioning in v1 remaining approval-gated intent records fulfilled manually. The long-term extraction question (whether billing later moves back out into a dedicated service) is explicitly deferred and documented, not silently decided.

## 3. What was added in Phase 29D

| Area | Deliverable |
| :--- | :--- |
| Readiness | `php artisan glassportal:commercial-readiness` — 25+ read-only checks across foundation, admin bootstrap, catalog, Stripe config, schema, routes, safety boundaries, documentation, and runtime drift; exit 0/1; never prints secrets |
| Admin bootstrap | Runbook `docs/runbooks/admin-bootstrap.md` for the existing `glassportal:create-admin` command, plus automated checks and tests |
| Contract fixtures | Nine frozen v1 payload fixtures in `tests/Fixtures/contracts/` guarded by `ContractFixturesTest` so SDK/API shapes cannot drift silently |
| Pilot flow tests | `CommercialPilotFlowTest` — checkout → webhooks → subscription/invoice/payment → entitlement → approval-gated provisioning → admin approval, with Stripe fully mocked and idempotency verified |
| Boundary tests | `BoundaryEnforcementTest` — role walls, no self-service admin creation, no network calls from provisioning transitions, no execution clients in the codebase, customers cannot mutate billing |
| Runbooks | `commercial-v1-launch.md`, `sdk-parity-check.md`, `runtime-consolidation.md`, `ai-operator-preflight.md` |
| Drift guards | `docs/state/runtime-map.md`, `repository-map.md`, `phase-status.md` + readiness checks that fail if the app is pointed at the preserved :18180 companion runtime |

## 4. Validation summary (Phase 29E)

The full validation matrix lives in `docs/state/commercial-v1-validation-matrix.md`. Headline results: the complete GlassPortal suite passes — **772 tests, 2,257 assertions, 0 failures** (PHP 8.3.6, PHPUnit 11.5, in-memory SQLite) — including the 32 new Phase 29D commercial/contract tests and the 61 SDK (`packages/glasshouse/portal-auth`) tests dogfooded through the main suite. `glassportal:healthcheck` passes all required checks. `glassportal:commercial-readiness` behaves correctly: it reports NOT READY with exactly the expected blockers on an unconfigured database and READY on a configured one (verified by its own test class). The standalone GlassBilling app was validated structurally only — it boots on Laravel 11.51 and registers 97 routes — and it has **no test suite**, which is recorded honestly as a known limitation of the preserved companion, not fixed in this phase per the do-not-modify boundary.

## 5. Known limitations

Provisioning fulfillment is manual by design; the approval-gated request lifecycle is the only provisioning surface. The standalone GlassBilling runtime remains preserved and untested; it must not receive traffic. Stripe live-mode has not been exercised — the launch runbook requires a founder-approved test-mode walkthrough first, then a founder-controlled live transaction. The `/admin/customers` read-bridge endpoint expected by `GlassBillingClient` remains unrouted in the standalone GlassBilling API (documented drift, deferred). Multi-currency, taxes, dunning, refunds, and metered billing are out of scope for v1.

## 6. Upgrade / rollout notes

No database schema changes were introduced in Phases 29C–29E beyond what already existed at Phase 28; deploying this RC is a code+docs update. Operators should run, in order: `php artisan migrate` (no-op expected), `php artisan glassportal:healthcheck`, and `php artisan glassportal:commercial-readiness`, then follow `docs/runbooks/commercial-v1-launch.md`. Rollback is a plain git revert to the previous deploy tag; no data migrations to unwind.

## 7. Tagging recommendation

All Phase 29E gates that can be satisfied without production configuration are green. Recommendation: **tag `glasshouse-commercial-v1-rc1`** on the merge commit of `glassportal-phase29c-29e-commercial-v1` once the founder has (a) reviewed the reconciliation ADR and commercial v1 decision, and (b) confirmed the production runtime passes `glassportal:commercial-readiness` with its real environment. The tag has intentionally **not** been created yet — creating it is a one-line follow-up after approval.
