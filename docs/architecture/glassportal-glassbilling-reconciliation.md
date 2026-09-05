# Phase 29C — GlassPortal / GlassBilling Architecture Reconciliation

**Status:** Accepted (Phase 29C)
**Date:** 2026-07-03
**Supersedes in part:** Phase 28A `repository-consolidation.md` (re-frames "legacy" language), Phase 23 `billing-source-of-truth.md` (re-anchors guardrail wording)
**Safety posture:** Documentation-only phase. No code moved, no code deleted, no repos archived, no databases merged, no runtime or Stripe changes, no provisioning executed.

---

## 1. Purpose

During Phases 23–28, substantial billing-control-plane functionality was implemented directly inside GlassPortal while the standalone GlassBilling repository and runtime went dormant. This document reconciles both architectures against docs, code, runtime, and git history, and records the corrected long-term ownership model, the transition strategy, and the approval gates required before any consolidation action.

Operating doctrine applied throughout: **docs show intent, code shows implementation, runtime shows operational truth, history shows why**. All four were inspected.

## 2. Current repo reality (code evidence)

### 2.1 GlassPortal (`FuzzySpace/GlassPortal`, main @ `d0d663b`)

GlassPortal is an active Laravel application containing two distinct billing surfaces that coexist today:

1. **A read-only integration bridge** to standalone GlassBilling (Phase 5): `app/Services/GlassBilling/GlassBillingClient.php`, driven by `config/glassbilling.php` (`GLASSBILLING_BASE_URL` / `GLASSBILLING_API_TOKEN`). It reads dashboard tiles, customer services (+timeline), customers, provisioning requests, and invoice approvals, and exposes `GET /api/glassbilling/health`.
2. **A full embedded billing engine** (Phases 24–28): local `billing_*` tables (customers, products, plans, subscriptions, invoices, payments, payment methods, events, checkout sessions, service entitlements + events, change requests) plus `provisioning_requests` (+events); an SDK-free Stripe REST client (`StripeBillingClient`) that creates real Checkout Sessions and verifies webhook signatures (HMAC v1); a routed, throttled webhook endpoint `POST /api/billing/stripe/webhook`; entitlement lifecycle; an approval-gated provisioning request engine (`approve|reject|queue|start|complete|fail|cancel`) that deliberately never executes infrastructure; customer self-service under `/portal/billing/*`; and admin workflows under `/admin/billing/*`. All of this is covered by roughly 70 test files.

### 2.2 GlassBilling (`FuzzySpace/GlassBilling`, main @ `f526a26`)

The standalone repository is a monorepo (Laravel 11 billing API + Next.js 14 portal + TypeScript provisioner packages) that has been dormant since 2026-05-11. It carries a far richer domain model (~80 models: orders, credit transactions, dunning, payment methods, product catalogs with options/bundles/templates, domains/DNS/mail, connectors) and a genuinely deep provisioning layer: a `ProvisioningOrchestratorService` with dry-run/approve/execute-stub step records, and **real connector drivers** for GlassPanel (live Guzzle client), Proxmox, Pterodactyl, Mailcow, and PowerDNS with encrypted credentials.

However, its Stripe integration (`StripeService`, `StripeWebhookController`) and its payment/invoice/subscription/portal controllers are **written but not routed** — `routes/api.php` never registers them — and the repository has **no automated tests**. What *is* routed matches the Phase 3–7 admin API that GlassPortal's read bridge consumes (with one drift exception noted in §5).

## 3. Current runtime reality (operator evidence)

| Runtime | Public URL | Containers / ports | Compose project | Status |
| :--- | :--- | :--- | :--- | :--- |
| GlassPortal (pilot portal) | `http://40.160.61.180:18188/login` | `glassportal-source-app-1` → 8088 | `glassportal-source` | Current pilot portal — canonical customer-facing surface |
| GlassBilling (companion) | `http://40.160.61.180:18180/login` | `ghbilling-billing-1` → 8080, `ghbilling-portal-1` → 3000, `ghbilling-postgres-1` → 5432 (local), `ghbilling-redis-1` → 6379 (local), `ghbilling-mailhog-1` → 1025/8025 | `ghbilling` | Online, preserved, pending reconciliation |

Both runtimes stay up. `:18188` remains the pilot portal; `:18180` must not be shut down, redirected, or treated as dead. The two stacks have **separate databases** (portal DB with `billing_*` tables vs. dedicated PostgreSQL 16 for ghbilling) which must not be merged in this phase.

## 4. Original intended architecture vs. the creep

The original intent, corroborated by `docs/architecture/module-boundaries.md`, GlassBilling's `docs/INTEGRATIONS.md`, and the Phase 23 ADR, was:

> **GlassPortal is the face** (unified customer/staff portal, SSO, module launch, UI). **GlassBilling is the billing brain** (products, plans, orders, subscriptions, payments, Stripe, entitlements, provisioning intent). **GlassPanel is the execution hand** (nodes, allocations, servers, console, files, backups).

The creep happened in a well-documented window: Phase 23 (2026-06-27) declared GlassBilling the source of truth and even wrote the guardrail "GlassPortal never calls Stripe directly." Phases 24–28 (2026-06-27 → 06-28) then implemented Stripe checkout, webhook intake, subscriptions, invoices, payments, entitlements, provisioning requests, self-service, and admin billing workflows **inside GlassPortal**, reconciled rhetorically as "the GlassBilling module inside GlassPortal." Phase 28A then declared the standalone repo "legacy/reference only." The work itself is good and tested; the problem is that ownership language and implementation reality diverged, and the standalone repo's deeper financial/provisioning modeling was never reconciled.

## 5. Duplicated, missing, and conflicting capabilities

The full per-capability matrix lives in `docs/state/billing-capability-map.md`. The headline duplications and conflicts are:

| # | Conflict | Evidence | Consequence |
| :--- | :--- | :--- | :--- |
| 1 | **Bridge contract drift** | Portal's `GlassBillingClient` calls `GET /api/v1/admin/customers` (+`/{id}`); standalone `routes/api.php` never routes `CustomerController` | Bridge 404s against the current standalone build |
| 2 | **Split Stripe ownership** | Portal: hosted Checkout Sessions, SDK-free REST, routed + tested webhook. Standalone: stored payment methods (SetupIntent → PaymentIntent), official SDK, webhook controller unrouted | Two incompatible Stripe patterns; if both were wired, double consumption of overlapping webhook events |
| 3 | **Two provisioning request engines** | Portal `ProvisioningRequest` (approval lifecycle, no execution) vs. standalone `ProvisioningRequest/Job/Profile/Step` (dry-run/execute-stub + real drivers) | Split brain between provisioning *intent* and provisioning *execution* |
| 4 | **Independent phase tracks** | GlassPortal Phases 1–28A vs. GlassBilling Phases 1–7 | "Phase 5" is ambiguous across docs |
| 5 | **Neither system is complete financial truth** | Portal invoices/payments are a Stripe webhook mirror (no ledger/credits/refunds/tax/dunning); standalone has the richer ledger but no live wiring or tests | Commercial v1 must define which surface is authoritative *now* and what is deferred |

Capabilities missing from the portal's embedded engine but present in standalone: orders, credit transactions, dunning, refunds, payment-method vaulting, tax hooks, provider connectors/drivers, domains/DNS/mail commerce, PayPal, WHMCS module compatibility. Capabilities missing from standalone but present in the portal: routed Stripe checkout/webhooks, entitlement concept, customer self-service, change-request workflow, tests, SSO/signed-launch integration.

## 6. Recommended target ownership (evidence-based)

The original three-plane model is **confirmed** as the long-term architecture. Full assignments per capability are in the capability map; in summary:

**GlassPortal (the face)** owns: unified login/session, customer and staff dashboards, navigation/module launch, public product presentation (GlassSite), customer-facing billing *views*, admin billing *workflow views*, approval UI, pilot/commercial readiness UI, runtime/safeguard visibility, ARIA/SIONA surfaces, SSO and signed launches, and integration orchestration.

**GlassBilling (the billing brain — as a domain, not necessarily the current standalone runtime)** owns: billing customers, products/plans/pricing, subscriptions, invoices, payments, payment methods, Stripe checkout/webhooks, refunds/credits (later), tax/dunning (later), service lifecycle, entitlements/service authorization, provisioning intent, provider connections, GlassPanel provisioning jobs, the billing API, and the billing SDK contract.

**GlassPanel (the execution hand)** owns: nodes, allocations, templates, server creation, console/files/backups, start/stop/restart, server status, runtime health, and execution callbacks.

### 6.1 The critical nuance: domain vs. runtime

"GlassBilling owns billing" is a statement about the **domain boundary**, not about which process hosts it today. Evidence supports a two-step posture:

1. **For commercial v1 (now):** the GlassBilling *domain* is legitimately hosted **inside GlassPortal** as the bounded, tested, embedded module built in Phases 24–28. It is the only routed, tested, operational Stripe path in the estate. Re-wiring the dormant standalone runtime into the live payment path before launch would add risk with no commercial payoff.
2. **Long term (post-v1, gated):** the embedded module is the *reference implementation* of the GlassBilling API contract. Whether it is later extracted into the standalone runtime, or the standalone repo's superior components (orchestrator, drivers, ledger modeling) are absorbed into the module, is decided by a future ADR after the SDK/API parity work (see `sdk-api-parity-review.md`) proves the contract seams. Neither outcome is presumed.

This corrects Phase 28A's framing: the standalone GlassBilling repo/runtime is **not dead and not "legacy."** It is a *preserved companion service and component donor* — most notably for provisioning execution (orchestrator + drivers) and the richer financial ledger — pending reconciliation. It also corrects Phase 23's violated guardrail: the accurate rule is now "**only the GlassBilling domain talks to Stripe**," and today that domain lives inside GlassPortal's `app/Services/Billing/*`.

## 7. Transition strategy

The transition proceeds in gated stages, none of which are executed in this phase:

| Stage | Action | Gate |
| :--- | :--- | :--- |
| A (29C, now) | Documentation reconciliation: this ADR, capability map, SDK/API parity review, runtime consolidation plan, commercial v1 decision | Docs merged |
| B (29D) | Commercial v1 stabilization inside GlassPortal only: admin bootstrap checks, `glassportal:commercial-readiness`, flow/boundary/contract tests, runbooks. **Billing feature freeze in the portal beyond this.** | Full test suite green |
| C (29E) | Release candidate: validation matrix, release notes, tag proposal `glasshouse-commercial-v1-rc1` | All readiness commands pass; operator approval to tag |
| D (post-v1) | SDK/API parity fixes (e.g., route `/admin/customers` in standalone or retire that bridge call), contract fixtures enforced in CI both sides | Parity review actions approved |
| E (post-v1) | Ownership consolidation ADR: extract module ↔ absorb standalone components ↔ hybrid | Dedicated ADR + operator approval; data migration plan; rollback plan |
| F (post-v1) | Runtime consolidation per `runtime-consolidation-plan.md` | Explicit approval; :18180 preserved until then |

## 8. What remains provisional, required, and deferred

**Provisional until Stage E:** the physical home of the GlassBilling domain; the fate of the standalone runtime; unification of the two provisioning request schemas; adoption of the standalone's connector drivers.

**Commercially required (v1):** customer login; product/plan display; Stripe checkout (test/live-ready); webhook-driven billing state; entitlement creation; provisioning *intent* creation; admin review/approval workflow; customer status visibility; role boundaries; audit visibility; admin bootstrap; readiness checks; runbooks. All of these exist in GlassPortal today and are hardened in Phase 29D.

**Intentionally deferred (post-v1):** automatic infrastructure execution (Proxmox/DNS/mail/GamePanel), refunds, credits, tax automation, dunning automation, PayPal, domain/mail commerce, telemetry consent, AI agents with write access, large UI redesign, repository restructuring beyond documented boundary work.

## 9. Approval gates

The following require explicit founder/operator approval before execution: any runtime change to `:18188`/`:18180`; any DNS/routing/firewall/Proxmox change; database merges or production data migration; Stripe live-mode changes; real infrastructure provisioning; removal of any legacy runtime path; archiving or restructuring either repository; the Stage E consolidation ADR; and tagging `glasshouse-commercial-v1-rc1`.

## 10. Decision summary

GlassPortal is the face. GlassBilling is the billing brain — a domain currently and legitimately embedded in GlassPortal for commercial v1, with the standalone repo/runtime preserved as a companion service and component donor. GlassPanel is the execution hand, integrated post-v1 through GlassBilling-owned provisioning jobs. No code moves, no runtime changes, no data merges occur until the gated stages above are individually approved.
