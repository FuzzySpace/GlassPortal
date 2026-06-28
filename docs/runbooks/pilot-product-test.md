# Runbook — Controlled Product-Test Pilot

**Audience:** founder / operator.
**Goal:** walk one customer through the full billing → checkout → webhook →
entitlement → provisioning-request flow in **Stripe test mode**, verifying each
step, with **no infrastructure provisioning executed**.

Companion doc: [`docs/phase29/product-test-pilot-readiness.md`](../phase29/product-test-pilot-readiness.md).

> Safety: this pilot never creates real servers, DNS, mailboxes, or game
> instances. Provisioning is approval-gated and tracked only. Stop immediately if
> any abort condition in section **F** occurs.

---

## A. Pre-test

0. **Use the canonical pilot URL.** Test against **GlassPortal —
   `http://40.160.61.180:18188`** (login at `/login`). Do **not** pilot against
   the legacy billing runtime at `http://40.160.61.180:18180`; it is
   reference-only. The readiness checks warn if you're on the legacy URL. See
   [`runtime-exposure-inventory.md`](../phase29/runtime-exposure-inventory.md).

1. **Confirm app health**

   ```bash
   php artisan glassportal:healthcheck            # expect exit 0
   php artisan glassportal:pilot-readiness         # expect exit 0 (warnings ok)
   ```

   Or open **Admin → Pilot Readiness** and confirm no blocked checks.

2. **Confirm a product exists** — Admin → Billing → Products (an active product).
3. **Confirm a plan exists** — Admin → Billing → Plans (active, with a **real
   Stripe TEST price id**, not a `price_local_*` placeholder).
4. **Confirm Stripe test keys configured** — `STRIPE_SECRET_KEY=sk_test_…`,
   `GLASSBILLING_ENABLED=true`, `GLASSBILLING_MODE=stripe`,
   `GLASSBILLING_CHECKOUT_ENABLED=true`.
5. **Confirm webhook endpoint configured** — `GLASSBILLING_WEBHOOKS_ENABLED=true`,
   `STRIPE_WEBHOOK_SECRET=whsec_…`, and a Stripe **test** webhook pointed at
   `POST /api/billing/stripe/webhook`.
6. **Confirm a customer account exists** — a `customer`-role user (ideally linked
   to an organization).

## B. Checkout

1. Sign in as the customer; open **Portal → Billing → Plans**.
2. Click **Subscribe** on the pilot plan → you are redirected to Stripe Checkout
   (test mode).
3. Complete checkout with a Stripe **test card** (`4242 4242 4242 4242`, any
   future expiry / any CVC / any ZIP).
4. **Verify the checkout session** — Admin → Billing → Checkout sessions: a row
   exists; after completion its status becomes `complete`.

## C. Webhook

1. **Verify `checkout.session.completed` accepted** — Stripe dashboard shows a
   2xx; Admin → Billing → Events lists the event as `processed`.
2. **Verify billing records** — a `BillingCustomer`, `BillingSubscription`, and
   (on `invoice.paid`) `BillingInvoice` + `BillingPayment` exist for the customer.
3. **Verify a billing event was recorded** for each delivered event (payload
   shown redacted).
4. **Verify the entitlement is active** — Admin → Billing → Entitlements shows an
   `active` entitlement for the subscription.

## D. Provisioning

1. **Verify a provisioning request exists** — Admin → Provisioning Requests shows
   one request for the entitlement, `requires_approval = true`, status
   `pending_approval`.
2. **Admin reviews the request.**
3. **Admin approves or sets the workflow state** (approve / reject / queue …).
4. **Confirm no infrastructure execution occurred** — the request never advanced
   to `running`/`completed` on its own; nothing called Proxmox/DNS/Mail/Panel.

## E. Customer verification

Signed in as the customer, under **Portal → Billing**:

1. Customer **sees the subscription** (Subscriptions).
2. Customer **sees the invoice/payment** (Invoices / Payments).
3. Customer **sees the entitlement** (Overview / Entitlements).
4. Customer **sees provisioning status** (Provisioning).

Confirm the customer sees **only their own** organization's records.

## F. Abort conditions

Stop the pilot and investigate if any occur:

- **Webhook signature failures** for legitimately-signed events.
- **Duplicate billing records** created for a single event.
- **Entitlement not created** for an active/paid subscription.
- **Cross-org visibility bug** — a customer sees another org's data.
- **Secrets visible** in the UI or log output.
- **Provisioning execution attempted** unexpectedly (any infra side effect).

To halt intake immediately: set `GLASSBILLING_CHECKOUT_ENABLED=false` and
`GLASSBILLING_WEBHOOKS_ENABLED=false`. Local billing records can then be inspected
in the database. No infrastructure rollback is needed — none was provisioned.
