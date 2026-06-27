# Phase 23 — Billing Source-of-Truth Reconciliation

> **Status:** Discovery / decision phase. **Documentation-only.** No billing
> code, no new billing tables, no Stripe, and no changes to any external
> environment (including LXC 310) were made in this phase.
>
> **Update — LXC 310 discovery (correction):** LXC 310 is **not** a billing
> source-of-truth candidate. Discovery identified it as a **legacy GlassPanel
> (GHpanel) game-server management runtime** with only limited billing
> *integration hooks*. It is preserved as **Legacy GlassPanel Reference #001**
> and **Migration Center Test Case #001** — not as a billing system. See §3.

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

> **Resolved by discovery:** the GlassBilling API (`/api/v1/admin/*`) is **not**
> served by the legacy `ghpanel` stack on LXC 310. LXC 310 is GlassPanel (a
> game-server panel), so the portal's GlassBilling bridge and LXC 310 are
> unrelated systems. GlassBilling itself must still be built/confirmed as a
> clean, Stripe-first billing service (see §3 and the ADR).

## 3. Known legacy environment — LXC 310 (GlassPanel, **not** billing)

Treated as a **legacy discovery target, not production**. Do not mutate. Do not
import its code. Do not copy secrets.

| Attribute | Value |
|---|---|
| Container | LXC 310 |
| Hostname | `lxc-gh-billing-dev-01` (misleading name — see below) |
| IP | `10.10.1.40` |
| Docker stack | `ghpanel` |
| Possible services | frontend `3000`, backend `8080`, PostgreSQL `5432`, Redis `6379`, MailHog `1025/8025`, Nginx `80` |

### Discovery findings (correction)

LXC 310 / `ghpanel` is a **legacy GlassPanel — Game and Server Management
Panel**, *not* a billing platform. Despite the `*-billing-*` hostname, its
purpose and schema are game-server infrastructure management.

| Evidence | Detail |
|---|---|
| Runtime path | `/var/www/html/dev/GHpanel` |
| Backend | `apps/panel` — Laravel 11 API |
| Frontend | `apps/web` — Next.js 14 |
| Agent | `apps/agent` |
| Migrator | `packages/migrator` — Pterodactyl / Pelican imports |
| Composer description | "GlassPanel — Game and Server Management Panel API" |
| DB owner / schema | `glasspanel` |
| Core tables | `nodes`, `servers`, `node_allocations`, `server_backups`, `server_databases`, `server_schedules`, `server_transfers`, `service_templates`, `template_variables` |
| Billing footprint | **limited to** `billing_integrations`, `billing_service_links` — *integration hooks*, not a billing ledger |

**Conclusion:** the only billing-shaped tables (`billing_integrations`,
`billing_service_links`) are thin links *to* an external billing system — they
are not a customer/invoice/payment source of truth. LXC 310 is therefore ruled
out as a GlassBilling source-of-truth candidate.

### Preservation classification

- **Legacy GlassPanel Reference #001** — keep as the canonical reference for how
  the prior game-server panel modeled nodes/servers/allocations/templates.
- **Migration Center Test Case #001** — keep as a real-world dataset for
  exercising future GlassPanel/GamePanel migration tooling (it already ships a
  `packages/migrator` for Pterodactyl/Pelican).

The **read-only** inventory procedure in
[`docs/phase23/lxc-310-inventory-template.md`](./lxc-310-inventory-template.md)
is used to complete/verify the snapshot for these two preservation purposes —
not to qualify it for billing.

## 4. Known risks

- **Misleading hostname.** LXC 310 is named `lxc-gh-billing-dev-01` but is
  actually a **GlassPanel** game-server runtime. The name must not be taken as
  evidence that it holds billing truth — it does not.
- **Integration hooks mistaken for a ledger.** GHpanel's `billing_integrations`
  / `billing_service_links` tables are links to an external billing system, not
  a billing source of truth. Do not reconcile billing *from* them.
- **Stale dev data mistaken for production.** LXC 310 is a *dev* host; its
  `glasspanel` Postgres may hold test data that looks authoritative.
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

- **LXC 310 / `ghpanel`** — identified as **legacy GlassPanel**, not billing.
  Stale for billing purposes; preserved only as a GlassPanel reference /
  migration test case (§3).
- **GlassBilling itself** — still to be built/confirmed as a clean, Stripe-first
  billing service. It is *not* derived from GHpanel. The `ghpanel` backend
  (`:8080`) is GlassPanel and unrelated to the portal's GlassBilling bridge.
- Any Stripe linkage — **none exists**; "Stripe customer/subscription" is
  aspirational.
- Real subscription/invoice/payment data fidelity (the portal has only ever
  read it; it has never been reconciled against payments).

## 7. What should be archived

- The legacy `ghpanel` (GlassPanel) Docker stack on LXC 310 **after** a
  read-only inventory and a data export/snapshot — archive images/volumes/
  compose as **Legacy GlassPanel Reference #001** and **Migration Center Test
  Case #001**. Do not delete, and do not promote it into any billing path.
- Any committed legacy `.env`/secret material — rotate and remove from history
  in a dedicated security task (out of scope here; only *located* by the
  inventory). Do not copy secrets out of LXC 310.

## 8. What should be kept

- The read-only `GlassBillingClient` bridge and `GlassBillingResult` contract.
- `organizations.glassbilling_customer_id` as the canonical org↔customer link.
- The portal's read-only admin billing surfaces and graceful-degradation UX.
- The control-plane boundary: portal reads + requests, never writes ledger.
- **LXC 310 / GHpanel as a GlassPanel reference + migration test case only** —
  its game-server data model (`nodes`, `servers`, `node_allocations`,
  `service_templates`, …) may inform future GlassPanel/GamePanel work, **but
  only after source-control import and security review** — never billing.

## 9. What should be rebuilt

- **GlassBilling, built clean as a Stripe-first billing/account/subscription/
  payment source of truth** — *not* forked or migrated from GHpanel. GHpanel is
  a game panel; its limited billing hooks are not a starting point for a ledger.
- A **GlassBilling write/action contract** (approve invoice, link/unlink
  customer, request provisioning) — designed, not yet built; replaces the
  "Phase 6 controlled writes" stub.
- A **Stripe integration inside GlassBilling** (not GlassPortal), webhook-driven,
  reconciled into the GlassBilling ledger.
- A **provisioning request → approval → driver** layer so entitlements can be
  fulfilled without billing touching infrastructure.
- A **billing audit/export** ("support bundle") capability for reconciliation.

## 10. Recommended next billing phases

- **Phase 24 — LXC 310 (GlassPanel) read-only inventory + preservation.** Run
  the inventory template, snapshot, and rotate any exposed secrets. Outcome:
  LXC 310 archived as **Legacy GlassPanel Reference #001** + **Migration Center
  Test Case #001** — explicitly *not* a billing source. (Billing reconciliation
  no longer depends on LXC 310.)
- **Phase 25 — GlassBilling write/action contract (no Stripe).** Define and
  implement the request/approval surface for invoice approvals and customer
  link/unlink from the portal, audited, still no infra mutation.
- **Phase 26 — Provisioning request/approval/driver layer.** Generalize SIONA's
  module lifecycle into a driver interface; billing emits entitlements →
  requests.
- **Phase 27 — Stripe-first in GlassBilling.** Stripe customers/subscriptions/
  webhooks owned by GlassBilling; portal reads reconciled state only.

## Recommended decision on LXC 310

**LXC 310 / GHpanel is legacy GlassPanel — a game-server management runtime, not
billing. Do not use it as the GlassBilling source of truth.** Preserve it as
**Legacy GlassPanel Reference #001** and **Migration Center Test Case #001**.
Build future GlassBilling cleanly as a Stripe-first billing/account/subscription/
payment source of truth, independent of GHpanel. Future GlassPanel/GamePanel work
may review GHpanel's game-server concepts **only after source-control import and
security review**. No mutation, teardown, code import, or secret copying in this
phase — next step is the read-only inventory (Phase 24).

---

*Documentation-only deliverable. See the gap matrix for per-area current vs.
desired source of truth, and the ADR for the binding decision.*
