# SDK / API Contract Map (Phase 29C)

**Date:** 2026-07-03 · **Status:** Accepted, frozen for commercial v1 · **Fixtures:** `tests/Fixtures/contracts/*.json` (added Phase 29D)

This map freezes the cross-system vocabulary and payload shapes for commercial v1. Any change to these shapes requires a versioned contract bump and an update to the fixtures plus their tests.

## 1. Glossary (naming reconciliation)

| Contract term (canonical) | GlassPortal embedded term | GlassBilling standalone term |
| :--- | :--- | :--- |
| Billing Customer | `BillingCustomer` (`billing_customers`) | `Customer` |
| Product / Plan | `BillingProduct` / `BillingPlan` | `Product` / `ProductPlan` (+`ProductPrice`) |
| Checkout Session | `BillingCheckoutSession` | — (stored-PM pattern instead) |
| Subscription | `BillingSubscription` | `Subscription` (unrouted) |
| Invoice / Payment | `BillingInvoice` / `BillingPayment` | `Invoice`+`InvoiceItem` / `Payment` |
| Entitlement (service authorization) | `BillingServiceEntitlement` | nearest: `CustomerService` |
| Provisioning Request (intent) | `ProvisioningRequest` | `ProvisioningRequest` (+`Job`, `Step`, `Profile`) |
| Provider Reference | — (manual notes for v1) | `ProviderConnection` / connector credential |
| Change Request | `BillingChangeRequest` | nearest: `ServiceInvoiceApproval` |

## 2. Identifier rules

The portal organization is the identity anchor. Seam rules: (1) portal → standalone bridge reads use standalone UUIDs opaque to the portal, joined through `organizations.glassbilling_customer_id`; (2) Stripe objects (`cus_*`, `sub_*`, `cs_*`, `in_*`, `pi_*`, `pm_*`) are stored verbatim and are the reconciliation keys for all webhook-mirrored records; (3) embedded billing rows carry both `organization_id` (local FK) and their Stripe ID; no system ever synthesizes another system's IDs.

## 3. Frozen v1 payload shapes

The authoritative JSON examples live in `tests/Fixtures/contracts/`. Summary of each shape (field: type — notes):

**customer.json** — `id`: int; `organization_id`: int; `stripe_customer_id`: string|null; `email`: string; `name`: string; `created_at`: ISO-8601.

**product-plan.json** — product: `id`, `name`, `description`, `is_active`; plan: `id`, `billing_product_id`, `name`, `stripe_price_id`, `interval` (`month|year`), `amount_minor_units`: int, `currency` (ISO-4217 lowercase), `is_active`.

**checkout-session.json** — `id`: int; `billing_customer_id`: int; `billing_plan_id`: int; `stripe_checkout_session_id`: string (`cs_*`); `status`: `open|complete|expired`; `stripe_subscription_id`: string|null; `completed_at`: ISO-8601|null.

**subscription.json** — `id`: int; `billing_customer_id`: int; `billing_plan_id`: int|null; `stripe_subscription_id`: string (`sub_*`); `status`: Stripe vocabulary (`active|trialing|past_due|canceled|incomplete|incomplete_expired|unpaid|paused`); `current_period_end`: ISO-8601|null.

**invoice-payment.json** — invoice: `id`, `billing_customer_id`, `stripe_invoice_id` (`in_*`), `status` (`draft|open|paid|void|uncollectible`), `amount_due_minor_units`, `amount_paid_minor_units`, `currency`; payment: `id`, `billing_invoice_id`|null, `stripe_payment_intent_id` (`pi_*`), `status` (`succeeded|processing|requires_action|failed`), `amount_minor_units`, `currency`.

**entitlement.json** — `id`: int; `billing_customer_id`: int; `billing_subscription_id`: int|null; `service_key`: string; `status`: `pending|active|past_due|suspended|cancelled|terminated|expired|provisioning_pending|provisioning_failed`; `activated_at`/`suspended_at`/`terminated_at`: ISO-8601|null.

**provisioning-request.json** — `id`: int; `billing_service_entitlement_id`: int; `status`: `draft|pending_approval|approved|rejected|queued|running|completed|failed|cancelled`; `requested_action`: string; `payload`: object; `approved_by`: int|null; `notes`: string|null. Terminal statuses: `rejected|completed|failed|cancelled`.

**provider-reference.json** — `provider`: `glasspanel|proxmox|pterodactyl|mailcow|powerdns|manual`; `external_id`: string; `label`: string; `recorded_by`: int; `recorded_at`: ISO-8601. (v1: recorded manually on the provisioning request during fulfillment.)

**lifecycle-status.json** — the entitlement↔customer-service mapping table: portal `active`↔standalone `active`; `suspended`↔`suspended`; `cancelled`↔`cancelled`; `terminated`↔`terminated`; `pending`↔`pending`; `provisioning_pending`↔`provisioning`; `provisioning_failed`↔`failed`; `past_due`/`expired` → standalone `BILLING_STATUSES` domain (no service-status equivalent — billing-side only).

## 4. Webhook contract (v1)

Single consumer: `POST /api/billing/stripe/webhook` on GlassPortal. Signature: Stripe v1 HMAC with timestamp tolerance from `config/billing.php`. Idempotency: unique Stripe event ID recorded in `billing_events` before processing. Handled events (allowlist): `checkout.session.completed`, `checkout.session.expired`, `customer.subscription.created|updated|deleted`, `invoice.paid`, `invoice.payment_failed`, `payment_method.attached`. The standalone webhook controller remains unrouted; routing it while the portal endpoint is live is a contract violation.

## 5. Read-bridge contract (v1)

Expected by `GlassBillingClient`: `GET /api/health`, `GET /api/v1/admin/dashboard-tiles`, `GET /api/v1/admin/customer-services[?query]`, `GET .../customer-services/{id}`, `GET .../customer-services/{id}/timeline`, `GET /api/v1/admin/customers[?query]` ⚠ *unrouted server-side (known drift, Stage D fix)*, `GET .../customers/{id}` ⚠, `GET /api/v1/admin/provisioning-requests[?query]`, `GET .../provisioning-requests/{id}`, `GET /api/v1/admin/invoice-approvals[?query]`, `GET .../invoice-approvals/{id}`. Auth: bearer token, Sanctum. All bridge calls are read-only; the portal never writes to the standalone runtime in v1.
