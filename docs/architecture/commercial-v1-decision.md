# Commercial V1 Architecture Decision

**Date:** 2026-07-03 · **Status:** Accepted (Phase 29C) · **Depends on:** `glassportal-glassbilling-reconciliation.md`, `docs/state/billing-capability-map.md`

## 1. Definition of the first commercial version

Commercial v1 is the smallest safe configuration in which **a first paying customer can be onboarded with founder/operator supervision**. Full automation is explicitly not required. The system must accept (or simulate, in test mode) payment through the approved Stripe path, record billing state correctly, create entitlements and provisioning intent, let the admin review and act, let the customer see status, prevent cross-customer and secret leakage, avoid any unauthorized infrastructure execution, and be operable from runbooks without guessing.

## 2. What is required, who owns it, and what runtime serves it

**The customer-facing runtime for commercial v1 is GlassPortal at :18188.** The GlassBilling *domain* functions required for v1 are served by the embedded billing module inside GlassPortal (Phases 24–28), which is the only routed, tested Stripe-capable surface in the estate. The standalone GlassBilling runtime at :18180 remains online, preserved, and outside the v1 payment path.

| Required v1 function | Owner (domain) | Where it runs in v1 | Evidence |
| :--- | :--- | :--- | :--- |
| Customer login / registration / roles | GlassPortal | Portal | `role` middleware + auth routes, tested |
| Product/plan display (public + portal) | GlassPortal (views) / GlassBilling domain (data) | Portal `/products`, `/portal/billing/plans` | Phase 22 + 27 |
| Stripe checkout (test/live-ready) | GlassBilling domain | Embedded module (`StripeCheckoutService`) | Phase 27, tested |
| Stripe webhook intake (verified, idempotent) | GlassBilling domain | Embedded module (`POST /api/billing/stripe/webhook`) | Phase 27, tested |
| Billing records (customers/subscriptions/invoices/payments as Stripe mirror) | GlassBilling domain | Embedded module tables | Phase 24/27 |
| Entitlements / service authorization | GlassBilling domain | Embedded module | Phase 25, tested |
| Provisioning intent (approval-gated, no execution) | GlassBilling domain | Embedded provisioning request engine | Phase 26, tested |
| Admin review/approval workflows | GlassPortal (views) | `/admin/billing/*`, `/admin/provisioning/requests/*` | Phases 25–28 |
| Customer status visibility | GlassPortal (views) | `/portal/billing/*` | Phase 28, tested |
| Admin bootstrap | GlassPortal | `php artisan glassportal:create-admin` | Verified command (29D checks added) |
| Readiness verification | GlassPortal | `glassportal:healthcheck` + `glassportal:commercial-readiness` (29D) | 29D |
| Operations runbooks | GlassPortal docs | `docs/runbooks/commercial-v1-launch.md` (29D) | 29D |

## 3. What remains manual in v1

Fulfillment is manual: after a provisioning request is approved, the operator provisions the service outside the system (or on GlassPanel by hand), records the provider reference and status on the request, and notifies the customer. Refund handling, plan changes beyond the change-request workflow, and any Stripe dashboard operations are manual. Admin account creation is manual via the CLI command.

## 4. What remains approval-gated

Provisioning execution of any kind (the request engine's `queued → running` transition represents operator work, not automation); any Stripe live-mode activation or key change; any change to the :18188/:18180 runtime layout; and any write path from portal to the standalone GlassBilling runtime.

## 5. What is deferred (not in v1)

Automatic infrastructure execution (Proxmox / DNS / mail / GlassPanel server creation), refunds and credits, tax and dunning automation, PayPal, orders as first-class objects, payment-method vaulting and off-session charging, domain/mail commerce, WHMCS compatibility surface, AI agents with write access, telemetry consent, large UI redesign, and repository restructuring. These map to the "Deferred" rows of the capability map, several of which have donor implementations waiting in the standalone repo.

## 6. Validation gates before real money and real infrastructure

**Before accepting payment from real customers:** full portal test suite green; `glassportal:healthcheck` passing; `glassportal:commercial-readiness` passing with zero blockers; Stripe webhook signature verification and duplicate-event idempotency proven by tests; live-mode keys and webhook secret configured (never printed); at least one active product/plan; at least one owner/admin account; customer/admin path walked end-to-end in test mode per the launch runbook; cross-organization isolation tests green; backups confirmed.

**Before provisioning real infrastructure automatically (post-v1):** Stage E ownership ADR approved; GlassPanel contract re-verified against a current build with recorded-response tests; connector credentials stored encrypted with health checks; an execution kill-switch and per-action approval flag; and a dedicated approval from the operator.

## 7. Decision

Commercial v1 ships on GlassPortal :18188 with the embedded GlassBilling domain module, manual fulfillment, and everything else gated or deferred as above. This decision does not finalize the long-term physical home of the GlassBilling domain, which remains a Stage E decision per the reconciliation ADR.
