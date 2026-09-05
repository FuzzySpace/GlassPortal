# Phase 29C — SDK/API Parity Review

**Date:** 2026-07-03 · **Status:** Accepted · **Companion to:** `glassportal-glassbilling-reconciliation.md`, `docs/state/sdk-contract-map.md`, `docs/runbooks/sdk-parity-check.md`

This review inventories every integration seam between GlassPortal, GlassBilling (standalone), and GlassPanel, compares the contracts each side actually implements, and lists the mismatches and the tests required before any runtime consolidation. Per the phase rules, **no SDK rewrite is performed**; the one small candidate fix is flagged but deferred to a gated stage.

## 1. Integration point inventory

| Seam | Direction | Mechanism | Auth | State |
| :--- | :--- | :--- | :--- | :--- |
| Portal read-bridge → GlassBilling admin API | GP → GB | `GlassBillingClient` (Laravel HTTP, JSON, timeout + TLS-verify configurable) | Static bearer token (`GLASSBILLING_API_TOKEN`) → Sanctum | Implemented both sides except one route (see §3.1) |
| Stripe → Portal webhook | Stripe → GP | `POST /api/billing/stripe/webhook`, HMAC v1 signature, timestamp tolerance, idempotent `billing_events` intake, throttle 120/min | Stripe signing secret | Live, tested |
| Portal → Stripe API | GP → Stripe | `StripeBillingClient` SDK-free REST (checkout sessions) | Secret key | Live, tested |
| Stripe → standalone GB webhook | Stripe → GB | `StripeWebhookController` (SDK `constructWebhookEvent`) | Stripe signing secret | Written, **not routed** — dormant |
| GB → GlassPanel admin API | GB → GPan | `GlassPanelService` (Guzzle): node capacity, allocations, create/suspend/unsuspend/delete servers | Panel API token | Written, dormant; unverified against current GPan |
| SSO signed module launch | GP → modules (GB/GPan/SIONA) | `packages/glasshouse` portal-auth SDK: signed launch tokens, JWKS rotation, back-channel redemption, replay stores, mTLS middleware | HS/RS signatures per module secret | Live, tested, dogfooded |
| SIONA connector | GP → SIONA | `SionaConnectorClient`, tenant provisioning, per-module secrets | Signed | Live (out of billing scope) |

## 2. Contract comparison

### 2.1 Endpoints expected vs. provided (read bridge)

`GlassBillingClient` calls eight endpoint families; standalone `routes/api.php` (auth:sanctum) provides seven of them: `/api/health`, `/api/v1/admin/dashboard-tiles`, `customer-services` (+`/{id}`, `/{id}/timeline`), `provisioning-requests` (+`/{id}`), and `invoice-approvals` (+`/{id}`) all match. **`GET /api/v1/admin/customers` and `/customers/{id}` are called by the portal but never routed in the standalone repo** — the `CustomerController` exists but is unwired, so those bridge calls 404 today.

### 2.2 Identifiers

The portal links identity to billing through `organizations.glassbilling_customer_id` (checked by `glassportal:healthcheck`). The standalone models key on `organization_id` + `customer_id` (UUIDs). The embedded module keys `billing_customers` on local ID with `organization_id` + `stripe_customer_id`. Three ID vocabularies coexist; the contract map freezes which identifier crosses each seam.

### 2.3 Status enums and lifecycle state machines

| Object | GlassPortal embedded | GlassBilling standalone | Verdict |
| :--- | :--- | :--- | :--- |
| Provisioning request status | `draft, pending_approval, approved, rejected, queued, running, completed, failed, cancelled` + explicit transition map, terminal set | `draft, pending_approval, approved, queued, running, completed, failed, cancelled` (no `rejected`) + `ACTIONS` (`create, attach_existing, modify, suspend, unsuspend, terminate, migrate`) | **Near-match**; portal adds `rejected`, standalone adds action taxonomy + step records. Union is safe: standalone treats reject as `cancelled` today |
| Service/entitlement status | Entitlement: `pending, active, past_due, suspended, cancelled, terminated, expired, provisioning_pending, provisioning_failed` | CustomerService: `pending, provisioning, active, suspended, cancelled, terminated, failed` + separate `BILLING_STATUSES` (`draft, pending_invoice, invoiced, paid, comped, failed`) | **Mismatch in shape**: portal folds provisioning state into entitlement; standalone splits service vs. billing status. Requires explicit mapping table before Stage E |
| Subscription status | Stripe vocabulary passthrough (`active, trialing`, etc.) | Own model, unrouted | Portal's Stripe passthrough is the v1 contract |
| Checkout session status | `open, complete, expired` (Stripe vocabulary) | No equivalent (stored-PM pattern) | Portal-only concept for v1 |
| Change request status | `submitted, under_review, approved, rejected, completed, cancelled` | `ServiceInvoiceApproval` workflow (different noun, similar semantics) | Conceptually parallel; unify nouns at Stage E |

### 2.4 Payloads, errors, idempotency, webhooks, versioning

The bridge client returns a uniform `GlassBillingResult` (success flag, JSON payload, HTTP status, latency) and logs failures without leaking secrets; the standalone API returns Laravel resource JSON — compatible. Webhook intake on the portal side is idempotent by Stripe event ID with a handled-events allowlist in `config/billing.php`; the standalone webhook handler is idempotency-unverified (untested, unrouted). API versioning exists only as the `/api/v1/` prefix on the standalone side; the portal's embedded module exposes no external billing API yet — **defining that versioned API is the Stage D/E contract work**, with the fixture payloads in `docs/state/sdk-contract-map.md` as the frozen v1 shapes.

### 2.5 Auth/signing

Bridge auth is a static bearer token against Sanctum — adequate for v1 read-only use but unscoped (no per-endpoint abilities). SSO/back-channel launch flows are the strongest contract in the estate (signed tokens, JWKS rotation, replay protection, mTLS option, dogfooded SDK) and require no parity work. GlassPanel auth (panel API token in `GlassPanelService`) is dormant and must be re-verified before any execution phase.

## 3. Findings

### 3.1 Mismatches

1. **Missing route (blocking for bridge completeness):** `/api/v1/admin/customers` (+`/{id}`) unrouted in standalone. The fix is a two-line route registration in the standalone repo, but per phase rules and repo-preservation posture it is **deferred to Stage D** with operator approval, since the standalone runtime is live at :18180 and any change to it should ride its own reviewed change.
2. **Naming:** "entitlement" (portal) vs. "customer service" (standalone); "billing change request" vs. "service invoice approval"; "billing customer" vs. "customer". One glossary, recorded in the contract map, prevents silent drift.
3. **Enum gaps:** `rejected` missing from standalone provisioning statuses; provisioning-state vs. entitlement-state split described in §2.3.
4. **ID references:** `glassbilling_customer_id` (org-level link) vs. embedded `billing_customers.id` vs. standalone `Customer.id` — the contract map fixes which one crosses each seam.
5. **Stripe pattern conflict:** hosted Checkout Sessions (portal, live) vs. stored-PM SetupIntent→PaymentIntent (standalone, dormant). v1 standard is the portal pattern; the standalone pattern is a future capability (off-session charging) that must never run concurrently against the same Stripe account without a single-consumer decision.
6. **Webhook single-consumer risk:** if the standalone webhook were ever routed while the portal endpoint is live, both would consume overlapping events. Guard: standalone stays unwired until Stage E, and the commercial-readiness command asserts exactly one configured intake.

### 3.2 Migration risks

Risks to a future consolidation include: double webhook consumption (above); entitlement↔customer-service semantic mapping; UUID vs. integer key reconciliation across the two databases; the standalone's zero-test baseline (nothing protects its behavior during change); and the seven-week staleness of its GlassPanel contract.

### 3.3 Tests needed before runtime consolidation

Contract fixture tests freezing the nine payload shapes (added in Phase 29D under `tests/Fixtures/contracts/` and validated by `tests/Unit/Contracts/`); bridge contract tests asserting the portal's expected endpoint list against a recorded standalone route manifest; webhook idempotency and signature tests (exist, portal); single-webhook-consumer readiness check (added 29D); role/boundary enforcement tests (added 29D); and — before any execution phase — recorded-response tests for `GlassPanelService` against a current GlassPanel build.

## 4. Disposition

No SDK changes ship in 29C. The portal-auth SDK is healthy. The bridge SDK's missing-customers-route mismatch is documented, ticketed for Stage D, and neutralized in the interim because the admin customers page can rely on embedded data. The contract fixtures added in 29D freeze the shapes so Portal/Billing/Panel cannot drift silently again.
