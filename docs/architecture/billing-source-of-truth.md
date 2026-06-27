# ADR: Billing Source of Truth

- **Status:** Accepted (Phase 23)
- **Date:** 2026-06-27
- **Supersedes:** the informal billing ownership note in
  [`docs/architecture/module-boundaries.md`](./module-boundaries.md) (extends, does not contradict)
- **Related:** [`docs/phase23/billing-source-reconciliation.md`](../phase23/billing-source-reconciliation.md),
  [`docs/phase23/billing-gap-matrix.md`](../phase23/billing-gap-matrix.md)

---

## Context

GlassPortal is now the **control plane** for the Glasshouse ecosystem. It owns
the customer/admin operating layer, RBAC, module launch, SIONA tenant
provisioning, the GlassSite public catalog, and the audit trail — but it does
**not** own billing state. Today GlassPortal only **reads** GlassBilling data
through a read-only HTTP bridge (`GlassBillingClient`) and maps an organization
to a billing customer via `organizations.glassbilling_customer_id`.

Some legacy artifacts pre-date this arrangement. The most prominent — the
`ghpanel` dev stack on LXC 310 — was initially suspected to be a billing
source-of-truth candidate. **Discovery has ruled that out:** LXC 310 / GHpanel
is a legacy **GlassPanel game-server management runtime** (Laravel 11 panel API,
schema owner `glasspanel`, tables like `nodes` / `servers` / `node_allocations`)
whose billing footprint is limited to thin *integration hooks*
(`billing_integrations`, `billing_service_links`) — **not** a billing ledger.
It is preserved as **Legacy GlassPanel Reference #001** / **Migration Center
Test Case #001**, not as a billing system. (See
[`docs/phase23/billing-source-reconciliation.md`](../phase23/billing-source-reconciliation.md).)

Before any new billing lifecycle work continues, the system needs one
unambiguous answer to: *where does each billing fact live, authoritatively?*

This ADR records that decision so future phases build on a stable foundation.

## Decision

1. **GlassBilling is the billing / account / payment / subscription source of
   truth.** Invoices, charges, refunds, credits, taxes, products, plans,
   pricing, subscriptions, payment methods, and service-lifecycle state
   (active / suspended / terminated) are owned by GlassBilling. No other system
   may author these facts.

2. **GlassPortal is the control plane and customer/admin operating layer.** It
   reads billing state and **requests** lifecycle actions; it never writes
   ledger entries or mutates billing records directly. GlassPortal owns its own
   concerns: identity/RBAC, module links, module launch, SIONA workspace
   mapping, the public catalog, and the portal-side audit log.

3. **GlassSite reads only public, intentionally-published catalog data.** The
   public site (`/products`) renders `public_product_catalog_entries` and never
   reads billing, customer, pricing-engine, or provisioning data. Marketing
   "starting price" fields are display-only and are **not** a pricing source of
   truth.

4. **Provisioning is not performed directly by billing.** GlassBilling creates
   **entitlements** and **provisioning requests**; it does not SSH, call
   hypervisors, mutate infrastructure, or run drivers. Fulfillment happens
   through a request → approval → driver layer owned by the control plane and
   the provider modules.

5. **SIONA and future modules are provisioned through module lifecycle
   services.** SIONA workspaces are created via
   `SionaTenantProvisioningService` (Phase 20), keyed by
   `organizations.siona_workspace_id`, and launched via signed/back-channel
   SSO. New modules follow the same request/approval/driver shape rather than
   being provisioned from the billing engine.

6. **Stripe-first is the payment target, but Stripe is not yet implemented.**
   When payments land, Stripe objects (customers, subscriptions, payment
   methods, webhooks) are owned by **GlassBilling**, which reconciles them into
   its own ledger. GlassPortal never talks to Stripe directly.

### Ownership at a glance

| Concern | Source of truth | GlassPortal role |
|---|---|---|
| Customers / accounts | GlassBilling | maps org → `glassbilling_customer_id`; reads + requests |
| Products / plans / pricing | GlassBilling | reads; GlassSite shows display-only marketing copy |
| Subscriptions / invoices / payments | GlassBilling | reads; requests approvals/actions |
| Stripe objects (future) | GlassBilling | never direct; reads via GlassBilling |
| Service entitlements | GlassBilling | reads entitlement → drives provisioning requests |
| Provisioning requests / fulfillment | Control plane + provider modules | request → approval → driver; audited |
| Module identity / launch | GlassPortal | owns links, launch, SSO secrets |
| SIONA workspace mapping | GlassPortal (`siona_workspace_id`) + SIONA | provisions via module service |
| Public catalog | GlassPortal (`public_product_catalog_entries`) | owns; public read-only |
| Portal audit trail | GlassPortal (`module_launch_events`) | owns |
| Legacy GlassPanel (LXC 310 / GHpanel) | n/a — game-server reference, **not billing** | none; preserved as reference/migration test case only |

## Consequences

**Positive**

- One unambiguous owner per billing fact; no cross-system write races.
- New billing features have a clear integration contract: read state, request
  actions, await events.
- Infrastructure mutation is decoupled from billing — billing emits intent,
  drivers fulfill, the portal audits.
- GlassBilling is built **clean and Stripe-first**, not forked from the legacy
  GHpanel/GlassPanel stack — no legacy billing-shaped data is promoted into the
  ledger.
- LXC 310 / GHpanel is settled as **legacy GlassPanel** (game-server reference +
  migration test case), removing it as a billing source-of-truth question.

**Costs / constraints**

- GlassPortal must not add billing-write shortcuts, even when convenient.
- Some billing facts still have an *uncertain* current owner until a clean
  GlassBilling exists; the gap matrix tracks these. (LXC 310 is **no longer** one
  of them — it is settled as legacy GlassPanel.)
- A request/approval/driver layer must exist before automated provisioning from
  entitlements is safe. It is not built yet.

## Non-goals (this ADR)

- Implementing Stripe.
- Creating new billing lifecycle tables in GlassPortal.
- Migrating, mutating, importing code from, or copying secrets out of the LXC 310
  legacy GlassPanel environment.
- Defining the provisioning driver interface in detail (future phase).

## Guardrails (enforced going forward)

- Billing work must **not** treat LXC 310 / GHpanel as billing — it is legacy
  GlassPanel (game-server), preserved as reference / migration test case only.
- New billing features wait for source-of-truth reconciliation to complete.
- GlassBilling must **not** directly mutate infrastructure.
- Provisioning must go through a request / approval / driver layer.
- Stripe-first remains the target; GlassPortal never calls Stripe directly.
