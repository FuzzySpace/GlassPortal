# Phase 23 — Billing System Gap Matrix

Per-area assessment of current state, who owns the fact **today**, who **should**
own it (per the [ADR](../architecture/billing-source-of-truth.md)), the risk, and
the next action. Grounded in this repo's actual code (read-only `GlassBillingClient`,
`organizations.glassbilling_customer_id`, no billing writes, no Stripe).

> **Correction (LXC 310 discovery):** LXC 310 / GHpanel is **legacy GlassPanel**
> (game-server management), **not** a billing source of truth. Its only
> billing-shaped tables are integration hooks. It is ruled out as a source for
> every row below and preserved as **Legacy GlassPanel Reference #001** /
> **Migration Center Test Case #001**.

**Legend — Status:** ✅ working · 🟡 partial/read-only · 🔴 missing · ❔ unknown/legacy

| # | Area | Current status | Source of truth (today) | Desired source of truth | Risk | Next action |
|---|---|---|---|---|---|---|
| 1 | Customer records | 🟡 read-only via API; org↔customer map in portal | GlassBilling (+ portal mapping `glassbilling_customer_id`) | GlassBilling (clean, Stripe-first) | LXC 310 ruled out (it's GlassPanel); GlassBilling itself not yet confirmed | Confirm/stand up GlassBilling as the live customer store |
| 2 | Products / plans | 🟡 read-only; GlassSite has separate *marketing* catalog | GlassBilling (plans); GlassPortal (public catalog copy) | GlassBilling (plans); GlassSite = display only | Catalog price drift vs. real plan price | Keep catalog display-only; link `product_key`→plan later |
| 3 | Pricing | 🟡 marketing "starting price" only in portal | GlassBilling | GlassBilling | Marketing price mistaken for billable price | Label catalog price as indicative; never bill from it |
| 4 | Subscriptions | 🟡 read-only (`customerServices`) | GlassBilling | GlassBilling (Stripe-reconciled later) | No Stripe reconciliation yet | Defer to Stripe phase; read-only until then |
| 5 | Invoices | 🟡 read-only (`invoiceApprovals`) | GlassBilling | GlassBilling | Approve/reject not implemented (Phase 6 stub) | Build write/action contract (Phase 25) |
| 6 | Payments | 🔴 not surfaced; no processor | GlassBilling (assumed) / none | GlassBilling (via Stripe) | No payment truth today | Wait for Stripe-in-GlassBilling |
| 7 | Payment methods | 🔴 not implemented | none | GlassBilling (Stripe) | — | Stripe phase |
| 8 | Stripe customers | 🔴 not implemented | none | GlassBilling | Aspirational only | Stripe phase (owned by GlassBilling) |
| 9 | Stripe subscriptions | 🔴 not implemented | none | GlassBilling | Aspirational only | Stripe phase |
| 10 | Webhooks | 🔴 none (no inbound billing/Stripe webhooks) | none | GlassBilling | No event-driven reconciliation | Define webhook intake in GlassBilling |
| 11 | Failed payments | 🔴 not modeled | none | GlassBilling | Dunning/suspension can't trigger | Stripe phase |
| 12 | Refunds | 🔴 not modeled in portal | GlassBilling (assumed) | GlassBilling | — | Read-only surface later |
| 13 | Credits | 🔴 not modeled in portal | GlassBilling (assumed) | GlassBilling | — | Read-only surface later |
| 14 | Taxes | 🔴 not modeled in portal | GlassBilling (assumed) | GlassBilling | Compliance owned elsewhere | Confirm GlassBilling owns tax |
| 15 | Service entitlements | 🟡 implied by `customerServices`; not a first-class portal model | GlassBilling | GlassBilling (emits entitlements) | Entitlement→provisioning link is informal | Model entitlement→request mapping (Phase 26) |
| 16 | Provisioning requests | 🟡 read-only list (`provisioningRequests`); SIONA provisions via module service | GlassBilling (requests) + GlassPortal (SIONA tenant svc) | Control plane + provider drivers (billing emits requests) | Risk of billing provisioning directly | Build request/approval/driver layer (Phase 26) |
| 17 | Suspension / reactivation | 🔴 no portal action; lifecycle in GlassBilling | GlassBilling | GlassBilling decides; drivers enforce | Manual today | Add as requested action post-reconciliation |
| 18 | Cancellation / termination | 🔴 no portal action | GlassBilling | GlassBilling decides; drivers enforce | Manual today | Add as requested action post-reconciliation |
| 19 | Customer portal self-service | 🟡 read-only (support/account context, module launch) | GlassPortal (UI) reading GlassBilling | GlassPortal UI; actions = requests to GlassBilling | Customers can't self-serve billing | Define safe self-service request set |
| 20 | Admin billing operations | 🟡 read-only views; writes are Phase 6 stub | GlassPortal (read) / GlassBilling (truth) | GlassPortal requests → GlassBilling executes | "Controlled writes" never shipped | Build write/action contract (Phase 25) |
| 21 | Audit logs | 🟡 portal actions in `module_launch_events`; no billing-specific audit | GlassPortal (portal actions); GlassBilling (its own) | Both: portal audits requests, GlassBilling audits ledger | No unified billing audit/export | Add billing-action audit when writes land |
| 22 | Support bundle / export | 🔴 not implemented | none | GlassPortal (assembles read-only) | Hard to reconcile without export | Add read-only export for reconciliation |

---

## Cross-cutting observations

- **Everything billing-write is missing or stubbed.** The portal is strictly
  read-only against GlassBilling today; the "Phase 6 controlled writes" surface
  was never implemented. This is *safe* (no drift from the portal) but blocks
  lifecycle operations.
- **Stripe is uniformly 🔴.** Rows 6–11 depend on a Stripe-in-GlassBilling phase
  that has not started. Do not surface payment/subscription state as
  authoritative until then.
- **Provisioning must not move into billing.** Rows 15–18 should resolve through
  a request → approval → driver layer (the SIONA module service is the existing
  pattern to generalize), never by GlassBilling mutating infrastructure.
- **LXC 310 (`ghpanel`) is NOT a billing source — it is legacy GlassPanel.**
  Discovery confirmed a game-server panel (schema `glasspanel`; tables `nodes`,
  `servers`, `node_allocations`, …) whose only billing tables
  (`billing_integrations`, `billing_service_links`) are integration hooks, not a
  ledger. The "GlassBilling (assumed)" cells become certain only once a clean,
  Stripe-first GlassBilling exists — **not** from LXC 310. Preserve LXC 310 as
  **Legacy GlassPanel Reference #001** / **Migration Center Test Case #001**.

## Priority next actions (condensed)

1. **Stand up / confirm a clean GlassBilling** (Stripe-first) → resolves every ❔
   and "(assumed)" cell. (LXC 310 is ruled out as a source; inventory it only to
   preserve it as a GlassPanel reference / migration test case.)
2. **Ship the GlassBilling write/action contract** (rows 5, 20) — no Stripe, no
   infra mutation.
3. **Provisioning request/approval/driver layer** (rows 15–18) generalizing SIONA.
4. **Stripe-first in GlassBilling** (rows 6–11) — webhook-driven, reconciled.
5. **Billing audit + support-bundle export** (rows 21–22) for ongoing reconciliation.
