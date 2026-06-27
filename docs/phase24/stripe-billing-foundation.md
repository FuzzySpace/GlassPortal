# Phase 24 — Stripe-first Billing Foundation

## Purpose

Phase 23 established that GlassBilling must be built **clean** as the
billing/account/subscription/payment **source of truth** (see the
[ADR](../architecture/billing-source-of-truth.md)) — not derived from the legacy
GHpanel/GlassPanel stack. Phase 24 lays that foundation inside GlassPortal:
Stripe-first config, the minimum billing data model, models, a SDK-free Stripe
wrapper, read-only admin visibility, and healthcheck coverage.

This is **foundation only**. It deliberately does not process real payments or
mutate infrastructure (see *Out of scope*).

---

## Data Model

Eight tables (migrations `2026_06_27_000003`–`000010`), cross-DB safe (sqlite/
pg/mysql), soft-deleted except the append-mostly event log.

| Table | Purpose | Key Stripe mapping |
|---|---|---|
| `billing_customers` | account record; maps to org/user | `stripe_customer_id` (unique) |
| `billing_products` | sellable product; optional catalog link | `public_catalog_entry_id` |
| `billing_plans` | priced offering of a product (cents) | `stripe_price_id` |
| `billing_subscriptions` | mirrors a Stripe subscription | `stripe_subscription_id` (unique) |
| `billing_invoices` | mirrors a Stripe invoice (cents) | `stripe_invoice_id` (unique) |
| `billing_payments` | mirrors a Stripe PaymentIntent (cents) | `stripe_payment_intent_id` (unique) |
| `billing_payment_methods` | safe card display data only | `stripe_payment_method_id` (unique) |
| `billing_events` | idempotent provider webhook intake log | `provider_event_id` (unique) |

Models (in `App\Models`, matching the flat project convention):
`BillingCustomer`, `BillingProduct`, `BillingPlan`, `BillingSubscription`,
`BillingInvoice`, `BillingPayment`, `BillingPaymentMethod`, `BillingEvent` —
each with fillable, casts, relationships, and scopes (`active`, `paid`,
`default`, `unprocessed`, etc.). Money is stored in integer minor units (cents).

```
organizations ─┐
users ─────────┴─▶ billing_customers ─┬─▶ billing_subscriptions ─▶ billing_plans ─▶ billing_products ─▶ public_product_catalog_entries
                                       ├─▶ billing_invoices ─▶ billing_payments
                                       └─▶ billing_payment_methods
billing_events (provider webhook intake; unique provider_event_id)
```

---

## Stripe-first Design

- **Config:** `config/billing.php` (distinct from the legacy read-only
  `config/glassbilling.php` bridge). Keys: `GLASSBILLING_ENABLED`,
  `GLASSBILLING_MODE` (`stripe`|`external`|`off`), `STRIPE_SECRET_KEY`,
  `STRIPE_WEBHOOK_SECRET`, `STRIPE_PUBLISHABLE_KEY`, `GLASSBILLING_CURRENCY`.
- **Client:** `app/Services/Billing/StripeBillingClient.php` — **SDK-free**.
  Provides `isConfigured()` / `mode()` / `publishableKey()` /
  `safeConfigSummary()` (presence booleans only), `customerPayload()` (safe,
  back-referenced), `verifyWebhookSignature()` (pure-PHP HMAC of Stripe's
  `t=...,v1=...` scheme with timestamp tolerance), and `recordEvent()`
  (idempotent intake — duplicate `provider_event_id` returns the existing row).
  No real Stripe API calls are made in this phase.
- **Why SDK-free:** the foundation needs config detection, signature
  verification, and safe payloads — all doable without a network dependency, so
  tests stay fully offline. A later phase can drop the official SDK behind
  `isConfigured()` without changing callers.

---

## What Is Included

- Config + env scaffolding (`config/billing.php`, `.env.example`).
- 8 migrations + 8 models + 8 factories.
- `StripeBillingClient` (config detection, webhook signature verification, safe
  payloads, idempotent event intake).
- Read-only admin billing area (owner/admin only) at `admin/billing`:
  overview, customers (+ detail), products, plans, subscriptions, events. A
  "Billing" sidebar link for admins.
- Healthcheck: `billing.tables`, `billing.models`, `billing.stripe_config`,
  `billing.webhook_secret`.

---

## What Is Intentionally Out of Scope (later phases)

- Real Stripe checkout sessions / customer creation API calls.
- Customer self-service payment-method updates.
- Provisioning requests and service entitlements.
- Suspension/reactivation automation.
- Tax logic, refunds UI, credits.
- A live, public webhook endpoint and production webhook processing (only the
  *intake scaffolding* — verification helper + idempotent `billing_events` — is
  built here; no route is exposed yet).

---

## Security Boundaries

1. `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` are server-side only, read
   from config, and **never** returned, logged, rendered, or printed in
   healthcheck output. Only presence booleans and the publishable key are ever
   exposed. (Enforced by tests.)
2. `billing_payment_methods` stores **only** safe display data (brand, last4,
   expiry) — there is no column for a full card number or CVC.
3. Admin billing is **owner/admin only** (stacked `role:owner,admin`); staff,
   support, customers, and guests are blocked. Read/list only — no writes.
4. Webhook signatures are verified with constant-time comparison and a
   timestamp tolerance to resist replay; unsigned/unconfigured requests fail closed.
5. `billing_events.provider_event_id` is unique at the DB layer, so replayed
   provider events cannot be recorded twice (idempotent intake).

---

## Relationship to GlassPortal, GlassSite, and Provisioning

- **GlassPortal** is the control plane: it hosts the billing data model and the
  admin operating surface, and remains the audit layer.
- **GlassBilling** (this foundation) is the **source of truth** for billing
  facts. `billing_products.public_catalog_entry_id` optionally links a billable
  product to a **GlassSite** catalog entry, but GlassSite still shows only
  display-only marketing copy — never billing data.
- **Provisioning** stays decoupled: billing does not mutate infrastructure. A
  future phase adds a request → approval → driver layer; entitlements/
  provisioning requests are emitted, not executed, by billing.

---

## Tests

| Suite | File | Coverage |
|---|---|---|
| Unit | `tests/Unit/Billing/BillingModelsTest.php` | tables exist; products/plans/subscriptions/invoices/payments relationships; org + catalog links; scopes; safe card data; provider event id + duplicate rejection; mark processed/failed. |
| Unit | `tests/Unit/Billing/StripeBillingClientTest.php` | config detection; webhook signature accept/tamper/stale/no-secret; safe summary + payload never leak the secret; idempotent `recordEvent`. |
| Feature | `tests/Feature/AdminBillingTest.php` | guest→login, customer/staff→403, admin→200 on all pages; lists render; overview never renders the secret. |
| Feature | `tests/Feature/HealthCheckCommandTest.php` | billing foundation checks present + exit 0 in default dev; healthcheck never prints the Stripe secret. |

Run: `php artisan test` → **553 passed**.

---

## Next Phases

1. **GlassBilling write/action contract** — invoice approval, customer
   link/unlink, audited (no Stripe yet).
2. **Real Stripe integration** — customer/subscription creation + a verified,
   live webhook endpoint that drives `billing_events` → record reconciliation.
3. **Provisioning request/approval/driver layer** — entitlements emit
   provisioning requests; SIONA's module lifecycle is the pattern to generalize.
4. **Customer self-service** — portal payment-method + subscription management.
