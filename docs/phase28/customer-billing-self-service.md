# Phase 28 — Customer Billing Self-Service

## Purpose

Phases 24–27 built the billing engine from the inside out: the Stripe-first
foundation (24), service entitlements (25), the approval-gated provisioning
request engine (26), and Stripe Checkout + verified webhook intake (27). Those
phases populate billing state but expose almost none of it to the customer.

Phase 28 makes the customer portal **useful** by letting customers view and
safely manage their own billing state.

**Core product rule:**

> Customers may **view** their own billing and service state.
> Customers may **request** changes.
> Customers may **not** directly mutate billing, entitlement, provisioning, or
> infrastructure state.

Everything here is read-only or request-only. No customer action calls Stripe,
mutates a subscription/invoice/payment, changes an entitlement, executes
provisioning, or touches infrastructure. Staff act on customer requests through
the existing admin + approval layers.

---

## Billing scope + isolation

`App\Services\Billing\BillingSelfServiceService` resolves the signed-in
customer's **billing scope**: the set of `billing_customers` mapped to their
organization or to them directly (`billingCustomerIds()`). Every billing query
(subscriptions, invoices, payments, checkout sessions, payment methods,
entitlements, provisioning requests) is constrained to that set, so a customer
can never reach another organization's data.

Route-model-bound detail pages add an ownership check
(`ownsSubscription` / `ownsInvoice` / `ownsCheckoutSession` /
`ownsChangeRequest`); a miss returns **404** (we never reveal that another
organization's record exists).

The billing record models (`BillingSubscription`, `BillingInvoice`,
`BillingPayment`) — which carry no direct `organization_id` — are scoped through
`billing_customer_id`. Entitlements/provisioning carry their own keys but are
scoped the same way here for consistency.

---

## 1. Customer billing dashboard — `GET /portal/billing`

`portal.billing.dashboard` summarises, all scoped to the customer:

- active subscriptions / past-due subscriptions
- recent invoices / payments / checkout sessions
- active & pending entitlements
- in-progress provisioning requests
- open billing change requests
- plain "next action" warnings (past-due, open invoices, in-progress requests)

An empty state is shown when the customer has no billing records yet.

## 2. Subscriptions — `GET /portal/billing/subscriptions[/{subscription}]`

List + detail. Detail shows plan/product, status, billing period, a safe
provider reference (`sub_…` — an identifier, not a secret), related
entitlements, and related invoices, plus shortcuts to request a plan change or
cancellation. No edit controls.

## 3. Invoices — `GET /portal/billing/invoices[/{invoice}]`

List + detail: reference, status, amount due/paid, currency, due/paid dates,
linked payments. If a browser-safe `hosted_invoice_url` is present in metadata it
is surfaced as a "View / pay on Stripe" link — the **only** field read from
metadata; the raw payload is never rendered.

## 4. Payments — `GET /portal/billing/payments`

Payment history (date, amount, currency, status, linked invoice, provider
reference) plus a **safe** payment-method summary (brand, last4, expiry,
default). Card PAN/CVC are never stored or shown.

## 5. Checkout history — `GET /portal/billing/checkout-sessions[/{checkoutSession}]`

List + detail of Stripe Checkout sessions the customer started: status, payment
status, amount, currency, plan/product, created/completed/expires. **No raw
payload or secret metadata** is rendered.

## 6. Billing change requests (the request mechanism)

Customers cannot cancel/upgrade/downgrade in Stripe in this phase. Instead they
submit a **request** that staff review.

### Table `billing_change_requests` (migration `2026_06_28_000001`, soft-deleted, cross-DB safe)

`request_key` (unique); `organization_id`, `user_id`, `billing_subscription_id`,
`billing_plan_id`, `requested_plan_id` (all FK `nullOnDelete`, indexed);
`request_type`, `status`; `reason`, `customer_message`, `admin_notes`;
`requested_at`, `reviewed_by`/`reviewed_at`, `completed_at`, `cancelled_at`;
`metadata` JSON (redacted on display); timestamps + soft deletes.

### Model `BillingChangeRequest`

Owns the lifecycle state machine. **Request types:** `cancel_subscription`,
`change_plan`, `update_billing_details`, `billing_support`, `pause_service`,
`resume_service`. **Statuses:** `submitted`, `under_review`, `approved`,
`rejected`, `completed`, `cancelled` (terminal: rejected/completed/cancelled).

Allowed transitions:

| From | Allowed → |
|---|---|
| `submitted` | under_review, approved, rejected, cancelled |
| `under_review` | approved, rejected, cancelled |
| `approved` | completed, cancelled |
| `rejected` / `completed` / `cancelled` | — (terminal) |

`isCustomerCancellable()` is true **only** while `submitted` — a customer can
withdraw their request until staff start review.

### Customer actions (`Portal\BillingChangeRequestController`)

`GET /portal/billing/change-requests` (list), `/create` (form),
`POST /portal/billing/change-requests` (submit),
`GET /portal/billing/change-requests/{changeRequest}` (detail),
`POST /portal/billing/change-requests/{changeRequest}/cancel` (withdraw).

`BillingChangeRequestService::submit()` enforces ownership (a referenced
subscription must be in the customer's scope), requires a subscription for
cancel/plan-change, and requires a valid **active** target plan for plan-change.
It generates a unique `request_key`. `customerCancel()` only succeeds for the
owner while still `submitted`.

**These are workflow records only — submitting one never calls Stripe or mutates
billing/subscription/entitlement/provisioning state.**

## 7. Customer billing controller/service

- `Portal\BillingController` — dashboard + read-only list/detail (Phase 28) and
  checkout start (Phase 27), scoped via `BillingSelfServiceService`.
- `Portal\BillingChangeRequestController` — submit/withdraw requests.
- `BillingSelfServiceService` — scope resolution, authorized queries, dashboard,
  ownership checks. Never calls Stripe; never exposes secrets.
- `BillingChangeRequestService` — request workflow (submit + transitions). Never
  calls Stripe; never mutates subscriptions/entitlements/provisioning.

## 8. Admin change-request visibility + workflow

`Admin\Billing\ChangeRequestController` (owner/admin only, under `/admin/billing`,
new "Requests" tab):

- `GET /admin/billing/change-requests` (list)
- `GET /admin/billing/change-requests/{changeRequest}` (detail, incl. internal
  notes + redacted metadata)
- `POST /admin/billing/change-requests/{changeRequest}/{action}` where action ∈
  `under-review|approve|reject|complete|cancel`.

Transitions delegate to `BillingChangeRequestService` (enforces the allowed-map,
stamps review/complete/cancel columns, appends `admin_notes`). **No Stripe
mutation, no infrastructure mutation** — an "approved"/"completed" request is
purely a status change. Acting on the underlying billing remains a separate,
deliberate staff action through the existing engines.

## 9. Portal navigation

The customer top nav gains a single **Billing** entry; the billing pages share a
sub-nav (Overview, Subscriptions, Invoices, Payments, Checkout History, Plans,
Billing Requests) to keep the top bar clean. The dead "Invoices (Phase 7)"
placeholder was removed now that real invoices exist.

## 10. Healthcheck

Five Phase 28 checks added to `glassportal:healthcheck` (pass in dev, never print
secrets): `billing.change_requests_table`, `billing.change_request_model`,
`billing.self_service_controller`, `billing.change_request_workflow`,
`billing.self_service_routes`.

---

## Security boundaries

- **Read/request only.** No portal route mutates billing, entitlement,
  provisioning, or infrastructure state. The only customer writes are creating a
  change *request* and withdrawing their own pending one.
- **Strict org/user isolation.** Every query is scoped to the customer's billing
  customers; cross-org detail access returns 404 (tested).
- **No secrets / no raw payloads.** Detail views render whitelisted fields only;
  JSON metadata is rendered through the shared `RedactsSensitiveArrays` trait
  (redacts `*token*`, `*secret*` incl. `stripe_secret`/`signing_secret`/
  `webhook_secret`, `password`, `private_key`, …). Payment methods expose only
  brand/last4/expiry.
- **Admin-only review.** Change-request review is owner/admin only; staff/support
  and customers are forbidden.
- **No Stripe/infra calls.** Verified by tests asserting `Http::assertNothingSent()`
  and that the underlying subscription is untouched through the full workflow.

## Relationship to other systems

- **Stripe hosted billing portal (later):** Phase 28 deliberately avoids direct
  Stripe subscription mutation. A future phase can add Stripe's hosted Billing
  Portal / Customer Portal for self-serve payment-method and cancellation flows;
  the change-request workflow is the safe interim, and the invoice
  `hosted_invoice_url` link is the first safe Stripe-hosted touchpoint.
- **Provisioning request engine (Phase 26):** unchanged and not bypassed. Billing
  change requests are a *customer-facing* workflow distinct from provisioning
  requests; acting on one may later *lead* staff to drive the provisioning engine,
  but Phase 28 creates no provisioning and executes nothing.

## Out of scope (unchanged from the brief)

Direct Stripe cancellation / upgrade / downgrade, proration, refunds/credits, tax,
the Stripe customer billing portal, infrastructure provisioning execution, AI
agents, telemetry/consent, SIONA CRM replacement, and social automation are all
**out of scope** for this phase.

## Tests

- `tests/Unit/Billing/BillingChangeRequestModelTest.php` — state machine, customer
  cancel rule, scopes, label, redaction.
- `tests/Unit/Billing/BillingChangeRequestServiceTest.php` — submit + ownership,
  type requirements, unique keys, customer cancel rules, admin transitions,
  invalid transitions, no Stripe/infra, subscription untouched.
- `tests/Unit/Billing/BillingSelfServiceServiceTest.php` — scope resolution,
  cross-org isolation, ownership checks, dashboard summary.
- `tests/Feature/PortalBillingDashboardTest.php` — access control, ownership,
  cross-org 404, payment-method safe summary, no secret render.
- `tests/Feature/PortalBillingChangeRequestTest.php` — submit/withdraw, ownership,
  validation, unique keys, no admin access.
- `tests/Feature/AdminBillingChangeRequestTest.php` — RBAC, list/detail, workflow
  transitions, invalid transition handling, admin notes, redaction.
- `tests/Feature/HealthCheckCommandTest.php` — Phase 28 checks present, exit zero.

Run: `php artisan test`, `php artisan glassportal:healthcheck`.

Full suite at completion: **738 passed**.

## Known limitations / TODO

- Change requests are workflow records; fulfilling them (actual Stripe
  cancellation/plan change) remains a manual staff action pending a future phase.
- A single `billing_customer` per organization is assumed for display; multiple
  billing customers per org are supported by the scope query but not surfaced
  distinctly in the UI.
- No customer-driven payment-method management yet (awaits the Stripe hosted
  portal integration).
- Change-request lifecycle is not mirrored into an append-only event table (admin
  notes capture history inline); add one if an audit trail per transition is
  required.
