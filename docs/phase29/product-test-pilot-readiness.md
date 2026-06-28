# Phase 29 — Product-Test / Pilot Readiness

A **readiness, operator-workflow, seed-data, and checklist** phase — not a major
feature expansion. It makes GlassPortal ready to run a *controlled* pilot of the
billing → checkout → webhook → entitlement → provisioning-request flow built
across Phases 24–28, and gives operators the tooling to verify that readiness.

> **Core rule:** GlassPortal may **guide and validate** the product test.
> GlassPortal must **not execute infrastructure provisioning automatically.**
> Provisioning stays approval-gated (Phase 26); nothing here calls Proxmox, DNS,
> NetBox, Mail, GamePanel/GlassPanel, or SIONA.

Operator step-by-step: [`docs/runbooks/pilot-product-test.md`](../runbooks/pilot-product-test.md).

---

## Purpose

- Provide a single read-only **pilot readiness dashboard** (`/admin/pilot-readiness`)
  and CLI (`php artisan glassportal:pilot-readiness`) that answer: *is the system
  ready for a controlled pilot, and if not, what's blocking it?*
- Provide **pilot-safe seed data** (one active product + plan per offering) so a
  fresh local/dev environment is immediately testable.
- Provide the **manual test runbook** the founder/operator follows.

## Pilot test scope

### In scope

- Verifying the **existing** flow end-to-end in Stripe **test mode**:
  product/plan → checkout session (local record) → verified webhook intake →
  billing records (customer/subscription/invoice/payment/event) → entitlement
  activation → **approval-gated** provisioning request → customer self-service
  visibility.
- Operator readiness checks, seed data, and documentation.

### Out of scope (explicitly NOT done)

Automatic Proxmox/LXC/VM creation, automatic DNS/mail provisioning, automatic
GamePanel/GlassPanel server creation, Stripe **live**-mode launch automation,
direct cancellation/upgrade/downgrade execution, refunds/credits, tax automation,
AI agents, telemetry/consent, social/SIONA campaign tools, and any repository
restructuring (the Phase 28A consolidation decision is unchanged).

## Runtime target

Pilot against the **canonical GlassPortal runtime — `http://40.160.61.180:18188`**.
The standalone billing runtime at `http://40.160.61.180:18180` is **legacy /
reference** and must not be used for the pilot. The full public/runtime mapping,
container inventory, and the "what must not change" list are in
[`runtime-exposure-inventory.md`](./runtime-exposure-inventory.md). The readiness
checks emit a **warning** (`runtime.canonical_target`) if you appear to be on the
legacy URL.

## Test prerequisites

- App boots; `APP_KEY` set; database migrated.
- `php artisan migrate:fresh --seed` has run (creates the pilot product/plan).
- For a **live (test-mode) run**: Stripe test keys + a test webhook secret
  configured (see below). For a **local dry run**, billing can stay disabled.

## Stripe test-mode setup checklist

> Secrets live in `.env` only. They are never rendered, logged, or printed by the
> readiness page/command/healthcheck.

- [ ] `GLASSBILLING_ENABLED=true`
- [ ] `GLASSBILLING_MODE=stripe`
- [ ] `STRIPE_SECRET_KEY=sk_test_…` (test mode)
- [ ] `STRIPE_PUBLISHABLE_KEY=pk_test_…` (optional, browser-safe)
- [ ] `GLASSBILLING_CHECKOUT_ENABLED=true`
- [ ] `STRIPE_CHECKOUT_SUCCESS_URL` / `STRIPE_CHECKOUT_CANCEL_URL` set
- [ ] `GLASSBILLING_WEBHOOKS_ENABLED=true`
- [ ] `STRIPE_WEBHOOK_SECRET=whsec_…` (from the test webhook endpoint)

## Product / plan setup checklist

- [ ] At least one **active** `BillingProduct`.
- [ ] At least one **active** `BillingPlan` for it.
- [ ] The plan has a **real Stripe TEST price id** (`price_…`). The seeder ships
      `price_local_*` placeholders — replace them under **Admin → Billing → Plans**
      (or via the data layer) before live checkout. The readiness check
      `product_catalog.plan_pricing` warns until a real price id is present.

## Customer account checklist

- [ ] A `customer`-role user exists, ideally linked to an organization.
- [ ] (Optional) a `BillingCustomer` mapped to that org/user — checkout will
      create one on demand if missing.

## Checkout test checklist

- [ ] Customer opens **Portal → Billing → Plans**.
- [ ] Customer clicks Subscribe → redirected to Stripe Checkout (test mode).
- [ ] A `billing_checkout_sessions` row is created locally (status `open`).
- [ ] Complete the Stripe test checkout (test card `4242 4242 4242 4242`).

## Webhook test checklist

- [ ] `checkout.session.completed` is accepted (HTTP 2xx) and signature-verified.
- [ ] `customer.*`, `customer.subscription.*`, `invoice.*` events recorded.
- [ ] Each event appears under **Admin → Billing → Events** (payload redacted).
- [ ] Duplicate deliveries return 2xx and do **not** double-create records.

## Entitlement verification checklist

- [ ] An active subscription drives an **active** `BillingServiceEntitlement`.
- [ ] The entitlement is visible under **Admin → Billing → Entitlements** and in
      the customer portal.

## Provisioning request verification checklist

- [ ] An active entitlement yields exactly one **open, approval-gated**
      provisioning request (`requires_approval = true`, status `pending_approval`).
- [ ] The request is **not** auto-executed — it never reaches `running`/`completed`
      from webhook intake.
- [ ] Admin can review/approve/reject under **Admin → Provisioning Requests**.

## Customer portal verification checklist

- [ ] Customer sees subscription, invoice/payment, entitlement, and provisioning
      status under **Portal → Billing**.
- [ ] No cross-organization records are visible.
- [ ] No secrets/raw payloads appear anywhere in the UI.

## Admin review checklist

- [ ] **Admin → Pilot Readiness** shows no blocked checks.
- [ ] Operator can reach products/plans, checkout sessions, customers,
      subscriptions, entitlements, provisioning requests, and change requests from
      the readiness page quick links.
- [ ] `php artisan glassportal:healthcheck` exits 0.
- [ ] `php artisan glassportal:pilot-readiness` exits 0 (warnings acceptable).

## Rollback / abort criteria

Abort the pilot if any of these occur:

- Webhook **signature verification fails** for legitimately-signed events.
- **Duplicate** billing records are created for one event.
- An active/paid subscription does **not** produce an entitlement.
- **Cross-organization** data is visible to a customer.
- Any **secret** appears in the UI, logs, healthcheck, or readiness output.
- A provisioning request is **executed automatically** (any infra side effect).

Rollback is low-risk: this phase mutates no infrastructure. Disable
`GLASSBILLING_CHECKOUT_ENABLED` / `GLASSBILLING_WEBHOOKS_ENABLED` to halt intake;
billing records are local and can be inspected/cleaned in the database.

## Readiness checks (reference)

The readiness service groups checks into 12 categories: application health,
product catalog, billing, Stripe, checkout, webhook, entitlement, provisioning
request, customer portal, admin workflow, documentation, and security boundary.
Each item reports `ready` / `warning` / `blocked` / `unknown` with a short
message and a recommended action. **Blocked** items fail the CLI (exit 1);
warnings do not.

Healthcheck additions (machinery, always green in dev): `pilot.readiness_service`,
`pilot.readiness_command`, `pilot.admin_route`, `pilot.readiness_doc`,
`pilot.no_infrastructure_execution`.

## Known limitations

- Automated tests never make real Stripe API calls; webhook tests use locally
  signed payloads. A real Stripe test-mode round-trip is a **manual** operator
  step (the runbook).
- Seeded plans use `price_local_*` placeholders; the operator must set real test
  price ids before live checkout.
- The readiness command reflects the current environment's config/data; it does
  not persist history.

## Next-phase recommendation

After a successful pilot, the natural next phase is a **provisioning driver
execution layer** (still request → approval → driver, with real infrastructure
calls behind explicit approval and per-driver safety), plus Stripe **live-mode**
launch hardening. Both are out of scope here and should each get their own phase
and ADR.

## Tests / validation performed

- Readiness service, command, admin page, healthcheck, and customer pilot-path
  tests added (see the completion report).
- `php artisan test` — full suite green; `glassportal:healthcheck` exit 0;
  `glassportal:pilot-readiness` exit 0 after `migrate:fresh --seed`. Validated on
  the local sqlite path (Docker unavailable in this environment).
