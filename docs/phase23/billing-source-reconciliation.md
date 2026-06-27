# Phase 23 — Billing Source-of-Truth Reconciliation

> **Status:** Discovery / decision phase. **Documentation-only.** No billing
> code, no new billing tables, no Stripe, and no changes to any external
> environment (including LXC 310) were made in this phase.

This report captures the current billing reality across GlassPortal and the
Glasshouse ecosystem, names what is authoritative versus stale, and recommends
how to reconcile before further billing work proceeds. The target decision is
recorded separately in
[`docs/architecture/billing-source-of-truth.md`](../architecture/billing-source-of-truth.md);
the per-area breakdown is in
[`docs/phase23/billing-gap-matrix.md`](./billing-gap-matrix.md).

---

## 1. Current known billing architecture

```
        ┌────────────────────────────┐         read-only HTTPS (Bearer)
        │        GlassPortal          │   GlassBillingClient
        │  (control plane / UI)       ├───────────────────────────────┐
        │                             │                               ▼
        │  organizations.glassbilling │                     ┌───────────────────┐
        │      _customer_id  ─────────┼────────────────────▶│    GlassBilling    │
        │                             │   maps org→customer  │ (billing system    │
        │  Admin: customers/services/ │                      │  of record — API)  │
        │   provisioning/approvals    │◀─────────────────────┤  /api/v1/admin/*   │
        └────────────────────────────┘   JSON (read-only)    └───────────────────┘
                    │                                                  ▲
                    │ public, no billing data                         │ (future) Stripe
                    ▼                                                  │ not implemented
        ┌────────────────────────────┐                       ┌────────┴────────┐
        │  GlassSite (/products)      │                       │  Stripe (TARGET)│
        │  public catalog only        │                       └─────────────────┘
        └────────────────────────────┘
```

- **GlassBilling** is the intended billing/account/service-lifecycle system of
  record. GlassPortal integrates with it **read-only** today.
- **Stripe-first** is the stated payment target but is **not implemented**
  anywhere in this repo (no SDK, no keys, no webhooks).

## 2. Current GlassPortal billing bridge assumptions

Grounded in the actual code in this repository:

- **Client:** `app/Services/GlassBilling/GlassBillingClient.php` — a read-only
  HTTP client. Methods: `health()`, `dashboardTiles()`, `customers()`,
  `customer()`, `customerServices()`, `customerService()`,
  `customerServiceTimeline()`, `provisioningRequests()`,
  `provisioningRequest()`, `invoiceApprovals()`, `invoiceApproval()`. Every
  method returns a normalized `GlassBillingResult` and **never throws** into
  callers. **There are no write/POST methods.**
- **Config:** `config/glassbilling.php` — `GLASSBILLING_BASE_URL`,
  `GLASSBILLING_API_TOKEN`, `GLASSBILLING_TIMEOUT`, `GLASSBILLING_VERIFY_TLS`.
  Unconfigured by default; the portal degrades gracefully.
- **Mapping:** `organizations.glassbilling_customer_id` (nullable, indexed) is
  the only persisted billing linkage. One billing customer ↔ one organization;
  many portal users inherit billing identity via `organization_id`.
- **Admin surfaces (read-only):** `Admin\CustomersController`,
  `Admin\ServicesController`, `Admin\ProvisioningController`,
  `Admin\BillingApprovalsController`, `Admin\DashboardController` — all render
  GlassBilling data and degrade to "not configured / unavailable" states.
- **No billing writes exist in GlassPortal.** Invoice approval, provisioning
  approve/reject, and link/unlink were scoped to "Phase 6 controlled writes"
  and remain **not implemented** (the customer-detail view still shows the
  "controlled writes are coming" stub).
- **Audit:** portal-initiated actions are recorded in `module_launch_events`
  (the authoritative portal audit log). No billing-specific audit table exists.

> Assumption to verify: the GlassBilling API shape (`/api/v1/admin/*`) reflects
> a *current* GlassBilling service, **not** the legacy `ghpanel` dev stack. This
> has not been confirmed against a running environment in this phase.

## 3. Known legacy environment — LXC 310

Treated as a **legacy discovery target, not production**. Do not mutate.

| Attribute | Value (as provided) |
|---|---|
| Container | LXC 310 |
| Hostname | `lxc-gh-billing-dev-01` |
| IP | `10.10.1.40` |
| Old Docker stack | likely `ghpanel` |
| Possible services | frontend `3000`, backend `8080`, PostgreSQL `5432`, Redis `6379`, MailHog `1025/8025`, Nginx `80` |

A **read-only** inventory procedure to characterize this host before any
decision is provided in
[`docs/phase23/lxc-310-inventory-template.md`](./lxc-310-inventory-template.md).
Until that inventory is run and reviewed, the contents and authority of LXC 310
are **unknown** and must be assumed non-authoritative.

## 4. Known risks

- **Dual-source ambiguity.** A legacy `ghpanel` stack may contain
  customer/billing-shaped data that overlaps with GlassBilling, with no clear
  winner. Acting on either without reconciliation risks data divergence.
- **Stale dev data mistaken for production.** LXC 310 is a *dev* host; its
  Postgres may hold test data that looks authoritative.
- **No write path = no drift today, but also no migration path.** GlassPortal
  cannot currently correct or backfill billing state.
- **Stripe gap.** No payment processing exists; any "subscription/payment"
  surfaced today is whatever GlassBilling returns, not Stripe-reconciled.
- **Provisioning coupling risk.** If billing is later wired to provision
  directly, it would violate the control-plane boundary. The ADR forbids this.
- **Secret hygiene.** Legacy stacks often carry committed `.env` files / tokens.
  The inventory template only *locates* env files; it never prints secrets.

## 5. What is authoritative today

- **GlassBilling** for any billing/account/service-lifecycle fact it actually
  serves over `/api/v1/admin/*` (when configured and online).
- **GlassPortal** for: org↔customer mapping (`glassbilling_customer_id`),
  identity/RBAC, module links, module launch + audit (`module_launch_events`),
  SIONA workspace mapping (`siona_workspace_id`), and the public catalog
  (`public_product_catalog_entries`).
- **GlassSite** for public, published marketing copy only.

## 6. What is stale or unknown

- **LXC 310 / `ghpanel`** — unknown authority; assume **stale** until inventoried.
- Whether the GlassBilling API the portal targets is the same service as, or a
  successor to, the `ghpanel` backend (`:8080`).
- Any Stripe linkage — **none exists**; "Stripe customer/subscription" is
  aspirational.
- Real subscription/invoice/payment data fidelity (the portal has only ever
  read it; it has never been reconciled against payments).

## 7. What should be archived

- The legacy `ghpanel` Docker stack on LXC 310 **after** a read-only inventory
  and a data export/snapshot — archive images/volumes/compose, do not delete in
  this phase.
- Any committed legacy `.env`/secret material — rotate and remove from history
  in a dedicated security task (out of scope here; only *located* by the
  inventory).

## 8. What should be kept

- The read-only `GlassBillingClient` bridge and `GlassBillingResult` contract.
- `organizations.glassbilling_customer_id` as the canonical org↔customer link.
- The portal's read-only admin billing surfaces and graceful-degradation UX.
- The control-plane boundary: portal reads + requests, never writes ledger.

## 9. What should be rebuilt

- A **GlassBilling write/action contract** (approve invoice, link/unlink
  customer, request provisioning) — designed, not yet built; replaces the
  "Phase 6 controlled writes" stub.
- A **Stripe integration inside GlassBilling** (not GlassPortal), webhook-driven,
  reconciled into the GlassBilling ledger.
- A **provisioning request → approval → driver** layer so entitlements can be
  fulfilled without billing touching infrastructure.
- A **billing audit/export** ("support bundle") capability for reconciliation.

## 10. Recommended next billing phases

- **Phase 24 — LXC 310 read-only inventory + archival decision.** Run the
  inventory template, classify data, decide archive vs. keep, snapshot, and
  rotate any exposed secrets. Outcome: LXC 310 formally retired or scoped.
- **Phase 25 — GlassBilling write/action contract (no Stripe).** Define and
  implement the request/approval surface for invoice approvals and customer
  link/unlink from the portal, audited, still no infra mutation.
- **Phase 26 — Provisioning request/approval/driver layer.** Generalize SIONA's
  module lifecycle into a driver interface; billing emits entitlements →
  requests.
- **Phase 27 — Stripe-first in GlassBilling.** Stripe customers/subscriptions/
  webhooks owned by GlassBilling; portal reads reconciled state only.

## Recommended decision on LXC 310

**Treat LXC 310 (`ghpanel`) as a legacy, non-authoritative dev artifact.** Do
not build on it and do not let any billing feature assume it is current. Next
step is a **read-only inventory** (Phase 24) followed by **export + archive**;
retire it from the live path once GlassBilling authority is confirmed. No
mutation or teardown in this phase.

---

*Documentation-only deliverable. See the gap matrix for per-area current vs.
desired source of truth, and the ADR for the binding decision.*
