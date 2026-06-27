# Phase 27 — Stripe Checkout + Verified Webhook Intake

## Purpose

Phase 24 built the Stripe-first billing foundation; Phase 25 added service
entitlements; Phase 26 added the approval-gated provisioning request engine.
Phase 27 closes the loop **from the payment side**: a customer can start a real
Stripe Checkout session, and GlassPortal can ingest Stripe's verified webhook
events and turn confirmed payment/subscription state into local billing records,
entitlements, and approval-gated provisioning requests.

**Core flow (and its hard stop):**

> Stripe confirms payment/subscription → Billing records the state →
> Entitlements represent the *right* to a service → Provisioning *requests* are
> created from eligible entitlements → **Infrastructure is still NOT mutated.**

Every provisioning request created here is **approval-gated by default** and is
never executed. Phase 27 does not call Proxmox, DNS, NetBox, Mail,
GlassPanel/GamePanel, SIONA, or any other infrastructure, and does not bypass the
Phase 26 request engine.

---

## Stripe SDK strategy

GlassPortal integrates with Stripe **without the official SDK** (Composer plugin
installation is disabled in this environment, and tests must never make real
Stripe calls). The acceptable fallback is implemented properly:

- **Checkout** uses Laravel's `Http` client against the Stripe REST API
  (`POST {api_base}/v1/checkout/sessions`). The base URL is config-driven
  (`billing.stripe.api_base`) so tests `Http::fake()` it.
- **Webhook signature verification** is pure PHP (HMAC-SHA256 over
  `t=…,v1=…` with a timestamp-tolerance check) — no SDK required.
- The Stripe **secret key** and **webhook signing secret** are read from config
  only and are **never returned, logged, rendered, or echoed** — not even in
  error messages (Stripe error bodies are deliberately discarded).

Everything sits behind `StripeBillingClient`, so swapping in the real SDK later
is a single-class change.

---

## Configuration

`config/billing.php`:

```php
'stripe' => [
    'secret_key'      => env('STRIPE_SECRET_KEY', ''),       // server-only, never exposed
    'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET', ''),   // server-only, never exposed
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),  // pk_… browser-safe
    'api_base'        => env('STRIPE_API_BASE', 'https://api.stripe.com'),
],
'checkout' => [
    'enabled'     => GLASSBILLING_CHECKOUT_ENABLED (bool, default false),
    'mode'        => STRIPE_CHECKOUT_MODE (default 'subscription'),
    'success_url' => STRIPE_CHECKOUT_SUCCESS_URL,
    'cancel_url'  => STRIPE_CHECKOUT_CANCEL_URL,
],
'webhooks' => [
    'enabled'        => GLASSBILLING_WEBHOOKS_ENABLED (bool, default false),
    'tolerance'      => STRIPE_WEBHOOK_TOLERANCE (seconds, default 300),
    'allowed_events' => [ …10 event types… ],
],
```

Env vars (added to `.env.example`, all default-off / empty):

| Var | Default | Purpose |
|---|---|---|
| `GLASSBILLING_CHECKOUT_ENABLED` | `false` | Master switch for customer checkout start |
| `GLASSBILLING_WEBHOOKS_ENABLED` | `false` | Master switch for the webhook endpoint |
| `STRIPE_CHECKOUT_SUCCESS_URL` | _(empty)_ | Post-payment redirect |
| `STRIPE_CHECKOUT_CANCEL_URL` | _(empty)_ | Cancelled-checkout redirect |
| `STRIPE_CHECKOUT_MODE` | `subscription` | Checkout session mode |
| `STRIPE_WEBHOOK_TOLERANCE` | `300` | Max signed-timestamp age (seconds) |

**Both features are disabled by default.** The webhook route returns `404` while
disabled; checkout fails safe while disabled.

---

## Data model

One new table (migration `2026_06_27_000015`), cross-DB safe, soft-deleted. No
changes to Phase 24 `billing_events` (its existing `status` / `error_message`
columns absorbed the new `processed_with_warnings` state), so Phase 24 is not
broken.

### `billing_checkout_sessions`

A local mirror of a Stripe Checkout Session. Nullable FKs (`nullOnDelete`) to
`billing_customers`, `billing_products`, `billing_plans`,
`billing_subscriptions`, `organizations`, `users`. `provider` (default
`stripe`), `provider_session_id` (unique), `provider_customer_id` /
`provider_subscription_id` (indexed, nullable), `mode`, `status` (default
`open`), `payment_status`, `currency`, `amount_total` (minor units),
`success_url` / `cancel_url`, `expires_at` / `completed_at`, and `payload` /
`metadata` JSON. Provider payload is **redacted on display**.

Statuses: `open`, `complete`, `expired`.

### Model

`App\Models\BillingCheckoutSession` — casts, `customer()`/`product()`/`plan()`/
`subscription()`/`organization()`/`user()` relations, `scopeOpen` /
`scopeCompleted`, and `isOpen()` / `isComplete()` / `isExpired()`. Reverse
`checkoutSessions()` / `billingCheckoutSessions()` relations added to
`BillingCustomer`, `BillingProduct`, `BillingPlan`, `BillingSubscription`,
`Organization`, `User`.

### Shared redaction trait

`App\Models\Concerns\RedactsSensitiveArrays` centralises secret-shaped-key
redaction (`token`, `secret`, `password`, `private_key`, `api_key`,
`credential`, …) used by `BillingCheckoutSession`, `BillingEvent`, and
`ProvisioningRequest`. `safePayload()` / `safeMetadata()` / `safeResult()` return
recursively-redacted copies; raw JSON is never rendered.

---

## Customer checkout

`StripeCheckoutService::createSessionForPlan(BillingPlan, User, ?Organization, array $options)`
→ `StripeCheckoutResult` DTO.

It fails safe (no Stripe call, no records) when:

| Status | Cause |
|---|---|
| `disabled` | `billing.checkout.enabled` is false |
| `unconfigured` | Stripe not configured (no secret key / mode ≠ stripe / billing off) |
| `plan_unavailable` | Plan status ≠ `active` |
| `no_price` | Plan has no `stripe_price_id` |
| `stripe_error` | Stripe REST call failed (body discarded) |

On success it creates a Stripe Checkout Session and stores **only** a local
`billing_checkout_sessions` row (status `open`), then returns the redirect URL.
It resolves/creates a `BillingCustomer` for the org/user storing only
name/email/back-references — **never** a subscription, entitlement, or
provisioning request. Those are created only after Stripe confirms via webhook.

### Customer-facing route

`GET  /portal/billing/plans` (`portal.billing.plans`) — lists active plans with a
notice when checkout is disabled.
`POST /portal/billing/checkout/plans/{plan}` (`portal.billing.checkout`) —
starts checkout and `redirect()->away()` to Stripe on success, or back to the
plans page with an `error` flash on safe failure. `role:customer` only.

---

## Webhook intake

`POST /api/billing/stripe/webhook` (`api.billing.stripe.webhook`), public,
`throttle:120,1`, **no CSRF** (it is an `/api` route). `StripeWebhookController`:

1. `404` if `billing.webhooks.enabled` is false.
2. **Fail closed** with `500` if enabled but no signing secret (cannot verify).
3. `400` if the `Stripe-Signature` fails verification (HMAC + tolerance).
4. `400` if the JSON payload is missing `id`/`type`.
5. Otherwise `StripeWebhookService::handle()` runs and returns `200`.

`200` is returned for handled / duplicate / ignored events so Stripe stops
retrying once an event is durably recorded.

### `StripeWebhookService`

- **Idempotent** on `provider_event_id` (a terminal existing event → `duplicate`).
- Records **every** event in `billing_events`; types not in `allowed_events`
  are recorded and `ignored`.
- Marks each event `processed`, `processed_with_warnings`, or `failed` safely.
  Handler exceptions never store raw payload data (`error_message` =
  `handler_error`).

Handlers (each returns warning strings; empty = clean):

| Event | Effect |
|---|---|
| `checkout.session.completed` | Mark local session `complete`, link provider IDs, create a subscription **stub**. **Does not** activate entitlement / create provisioning — waits for confirmation events. |
| `customer.created` / `customer.updated` | Upsert `BillingCustomer` by Stripe id (+ back-reference metadata). |
| `customer.subscription.created` / `updated` | Upsert `BillingSubscription`, link the plan by `stripe_price_id`, then sync the entitlement. |
| `customer.subscription.deleted` | Cancel subscription + its entitlements. |
| `invoice.paid` / `invoice.payment_succeeded` | Record invoice + payment, mark the subscription active, sync the entitlement. |
| `invoice.payment_failed` | Record invoice/payment, mark subscription + entitlements `past_due`. |
| `payment_method.attached` | Store **only** safe display data (brand, last4, exp) — never PAN/CVC. |

### Entitlement + provisioning sync (the safe hand-off)

When a subscription is `active`/`trialing`, `ensureEntitlementActiveAndProvisioning`:

1. Activates the entitlement (unless already active / mid-provisioning — no churn).
2. If no **open** `provision` request exists and the entitlement
   `canProvision()`, creates one via the Phase 26
   `ProvisioningRequestService::createFromEntitlement(...)` — **`requires_approval = true`**.

This is idempotent across repeated/duplicate events: one subscription, one
entitlement, one open provisioning request. `past_due`/`unpaid` → entitlement
`past_due`; `canceled`/`incomplete_expired` → entitlement cancelled.

**Insufficient data is never guessed.** If an event can't be safely linked
(e.g. a subscription with no resolvable customer), the event is recorded as
`processed_with_warnings` and nothing is persisted.

---

## Admin visibility

Owner/admin only, read-only, under `/admin/billing` (new "Checkouts" tab):

- `GET /admin/billing/checkout-sessions` — list.
- `GET /admin/billing/checkout-sessions/{checkoutSession}` — detail.
- `GET /admin/billing/events/{event}` — billing event detail.

Detail views render `safePayload()` only — **secrets are redacted**.

---

## Healthcheck

Seven Phase 27 checks added to `glassportal:healthcheck`:

| Check | Pass / Warn / Strict-fail |
|---|---|
| `billing.checkout_sessions_table` | table present / — / fails if missing |
| `billing.checkout_model` | model loadable |
| `billing.checkout_service` | `StripeCheckoutService` resolvable |
| `billing.stripe_webhook_route` | route registered |
| `billing.stripe_webhook_service` | `StripeWebhookService` resolvable |
| `billing.stripe_checkout_config` | warns while disabled/dev; **strict-fails** when checkout enabled but Stripe unconfigured |
| `billing.stripe_webhook_config` | warns while disabled/dev; **strict-fails** (fail closed) when webhooks enabled but no signing secret |

The config checks print **presence only** — never key/secret values.

---

## Security invariants

- **No infrastructure mutation.** No Proxmox/DNS/NetBox/Mail/GlassPanel/SIONA
  calls; no driver execution. Provisioning requests are approval-gated and never
  auto-executed (verified by tests asserting no request reaches
  `running`/`completed`).
- **Provisioning only after confirmation.** Checkout start and
  `checkout.session.completed` grant nothing; entitlement activation +
  provisioning happen only on confirmed `active`/`paid` subscription/invoice
  state.
- **Secrets never exposed.** Secret key and webhook secret are never returned,
  logged, or rendered; payloads are redacted on display; payment methods store
  only brand/last4/exp.
- **Fail closed.** Webhooks enabled without a signing secret → `500`, never an
  unverified `200`.
- **Idempotent.** Duplicate deliveries return `2xx` and never double-process or
  double-provision.
- **GHpanel / LXC 310 untouched.** No GHpanel/GlassPanel code reused or imported.

---

## Tests

- `tests/Unit/Billing/BillingCheckoutSessionModelTest.php` — casts,
  relationships, status helpers, recursive redaction.
- `tests/Unit/Billing/StripeCheckoutServiceTest.php` — safe failures, success
  path (mocked HTTP), no subscription/entitlement/provisioning created, customer
  reuse, no secret leakage.
- `tests/Unit/Billing/StripeWebhookServiceTest.php` — idempotency, ignored
  events, all handlers, approval-gated provisioning, `processed_with_warnings`,
  no outbound HTTP, no auto-execution.
- `tests/Feature/StripeWebhookEndpointTest.php` — 404/500/400/200 paths,
  signature verification, public + no-CSRF, idempotent delivery, no secret leak.
- `tests/Feature/AdminBillingCheckoutTest.php` — owner/admin RBAC + payload
  redaction.
- `tests/Feature/PortalCheckoutTest.php` — plans page, RBAC, safe-fail flash,
  Stripe redirect (mocked), no grants on start.
- `tests/Feature/HealthCheckCommandTest.php` — the seven checks, strict-fail
  cases, no-secret-print.

Run: `php artisan test`, `php artisan glassportal:healthcheck`.

---

## Out of scope (future phases)

- **Driver execution / real provisioning.** Requests stay approval-gated; no
  driver runs against real infrastructure (future provisioning-driver phase).
- **Stripe SDK adoption.** Optional later swap behind `StripeBillingClient`.
- **Refunds, disputes, proration, tax, coupons, trials UX.** Not handled.
- **Customer billing portal / Stripe Billing Portal** (payment-method
  management, invoice history UI). Phase 7 covers invoices.
- **Outbound Stripe customer/subscription mutation** (create/cancel from the
  portal). GlassPortal still only *reads* and *requests*.
- **Multi-currency presentation / dunning automation.**
- **GlassBilling extraction.** Stripe remains owned conceptually by GlassBilling;
  this code is the portal-side bridge until that service exists.
