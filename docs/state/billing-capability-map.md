# Phase 29C — Billing Capability Map

**Date:** 2026-07-03 · **Status:** Accepted · **Companion to:** `docs/architecture/glassportal-glassbilling-reconciliation.md`

This map records, for every billing/provisioning-adjacent capability, where it exists today (GlassPortal embedded engine, standalone GlassBilling, GlassPanel), who canonically owns it *now*, who should own it *long term*, what reconciliation action applies, and its commercial v1 status.

Legend — **GP**: GlassPortal (`FuzzySpace/GlassPortal`, embedded billing module Phases 24–28 + bridge Phase 5). **GB**: standalone GlassBilling (`FuzzySpace/GlassBilling`, Phases 1–7, dormant since 2026-05-11). **GPan**: GlassPanel (referenced; not audited directly — assessed via GB's `GlassPanelService` contract and module-boundaries doc). Existence: `yes` / `partial` / `no`. Owner values name the system that owns the *domain*; "GB-domain (in GP)" means the GlassBilling domain as embedded in GlassPortal.

| Capability | In GP | In GB | In GPan | Current canonical owner | Recommended long-term owner | Reconciliation action | Commercial v1 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| Organizations | yes (`organizations`, SSO-linked) | partial (`Organization` model) | no | GP | GP (identity) | GB references org IDs via contract; no dual write | Required |
| Users (staff/customer) | yes (`users` + `UserRole` enum) | partial (`CustomerUser`, `StaffUser`, unrouted auth surface) | no | GP | GP (identity) | GB consumes portal identity via SSO/back-channel | Required |
| Roles / RBAC | yes (`role` middleware owner/admin/staff/support/customer) | partial (Sanctum abilities) | no | GP | GP | Contract: role claims carried in signed launches | Required |
| Billing customers | yes (`billing_customers`, webhook-upserted, tested) | yes (`Customer` rich model; `/admin/customers` NOT routed) | no | GB-domain (in GP) | GlassBilling domain | Freeze GP schema as contract shape; fix standalone routing at parity stage | Required |
| Products | yes (`billing_products`, simple) | yes (rich: options/groups/categories/templates, routed CRUD) | no | GB-domain (in GP) | GlassBilling domain | GP shape = v1 contract; GB catalog richness adopted post-v1 if needed | Required |
| Plans / pricing | yes (`billing_plans` + Stripe price IDs) | yes (`ProductPlan/Price`, `PricingPlan`) | no | GB-domain (in GP) | GlassBilling domain | Same as products | Required |
| Public catalog entries | yes (`public_product_catalog_entries`, GlassSite Phase 22) | partial (`ProductCatalogItem`) | no | GP | GP (presentation) | Marketing catalog stays portal-side; links to billing plans by ID | Required |
| Checkout sessions | yes (`billing_checkout_sessions` + Stripe Checkout, routed + tested) | no (different pattern: stored PM) | no | GB-domain (in GP) | GlassBilling domain | GP hosted-checkout pattern is the v1 standard | Required |
| Stripe webhooks | yes (`POST /api/billing/stripe/webhook`, HMAC verify, idempotent, tested) | partial (controller exists, NOT routed) | no | GB-domain (in GP) | GlassBilling domain (single consumer) | Exactly one live webhook consumer; standalone stays unwired until Stage E | Required |
| Subscriptions | yes (`billing_subscriptions`, webhook-driven) | partial (model yes, controller unrouted) | no | GB-domain (in GP) | GlassBilling domain | GP shape = v1 contract | Required |
| Invoices | yes (`billing_invoices`, Stripe mirror) | yes (ledger-style `Invoice/InvoiceItem` + `InvoiceService`, unrouted) | no | GB-domain (in GP) | GlassBilling domain | v1 = Stripe mirror; full ledger deferred (GB modeling is donor) | Required (mirror) |
| Payments | yes (`billing_payments`, Stripe mirror) | yes (`Payment` charge-stored-PM, unrouted) | no | GB-domain (in GP) | GlassBilling domain | Same as invoices | Required (mirror) |
| Payment methods | partial (`billing_payment_methods` from `payment_method.attached`) | yes (SetupIntent vaulting, unrouted) | no | GB-domain (in GP) | GlassBilling domain | Vaulting/off-session charging deferred | Optional |
| Billing events (audit) | yes (`billing_events`, idempotent event log, tested) | partial (`AuditLog`, webhook delivery models) | no | GB-domain (in GP) | GlassBilling domain | GP event log is the v1 audit spine | Required |
| Entitlements / service authorization | yes (`billing_service_entitlements` + events + lifecycle, tested) | no (nearest: `CustomerService` lifecycle) | no | GB-domain (in GP) | GlassBilling domain | Entitlement concept is GP-born; map to GB `CustomerService` at Stage E | Required |
| Service lifecycle (suspend/reactivate/cancel/terminate) | yes (entitlement lifecycle actions, tested) | yes (routed Phase 7: stage-invoice/mark-active/suspend/terminate) | partial (executes suspensions when driven) | GB-domain (in GP) | GlassBilling domain (intent) + GPan (execution) | Two lifecycles must be mapped one-to-one at Stage E | Required (intent only) |
| Provisioning requests (intent) | yes (`provisioning_requests` + events, approval workflow, NO execution, tested) | yes (`ProvisioningRequest` + dry-run/approve/execute-stub, routed) | no | GB-domain (in GP) | GlassBilling domain | Keep GP engine for v1; unify schemas at Stage E | Required |
| Provisioning jobs / execution steps | no (deliberately) | yes (`ProvisioningJob/Step`, orchestrator) | partial (receives execution calls) | GB (dormant) | GlassBilling domain → GPan | Execution stays OFF for v1; GB orchestrator is the donor design | Deferred |
| Provider references | no | yes (`ProviderConnection`, connector credentials encrypted) | partial | GB (dormant) | GlassBilling domain | Manual fulfillment records provider refs in provisioning request notes for v1 | Required (manual) |
| GlassPanel provisioning | no | yes (`GlassPanelService` live Guzzle client + driver) | yes (target API) | GB (dormant) | GlassBilling domain calls GPan | Gated post-v1; contract re-verified against current GPan before use | Deferred |
| Suspension / reactivation / cancellation flows | yes (entitlement + change requests) | yes (Phase 7 endpoints) | partial | GB-domain (in GP) | GlassBilling domain | v1 uses GP flows; GB flows preserved | Required |
| Billing change requests | yes (`billing_change_requests` workflow, tested) | partial (`ServiceInvoiceApproval` approval workflow, routed) | no | GB-domain (in GP) | GlassBilling domain | Both are workflow records (never direct Stripe mutations) — correct pattern | Required |
| Customer billing UI (self-service) | yes (`/portal/billing/*`, tested) | partial (Next.js portal, unrouted API backing) | no | GP | GP (views) over GB data | Views stay portal-side permanently | Required |
| Admin billing UI / workflows | yes (`/admin/billing/*` + bridge pages) | partial (Next.js admin, dormant) | no | GP | GP (views) over GB data | Same | Required |
| SSO / module launch | yes (signed launches, back-channel, JWKS, SDK `packages/glasshouse`, tested) | partial (`ModuleSsoToken`, consumer side) | partial (consumer) | GP | GP | GB/GPan remain SSO consumers via portal-auth SDK | Required |
| Audit logs (staff actions) | yes (`module_launch_events`, billing/provisioning event tables) | yes (`AuditLog`, connector audit events) | partial | GP (portal actions) / GB (billing events) | Per-plane ownership | Each plane audits its own writes; portal displays | Required |
| Pilot readiness | yes (healthcheck sections; drift-guard docs) | no | no | GP | GP | Extended by `glassportal:commercial-readiness` (29D) | Required |
| Healthcheck / readiness checks | yes (`glassportal:healthcheck`, 1,242-line command) | partial (`/api/health` only) | unknown | GP | Each plane self-reports; portal aggregates | 29D adds admin bootstrap + commercial checks | Required |
| Orders (formal order objects) | no (checkout → subscription directly) | yes (`Order/OrderItem`, unrouted) | no | — | GlassBilling domain | Deferred; v1 uses checkout-session-as-order | Deferred |
| Refunds / credits | no | yes (`CreditTransaction`, refund via SDK, unrouted) | no | — | GlassBilling domain | Explicitly deferred by freeze rules | Deferred |
| Tax / dunning | no | partial (`DunningJob`, tax hooks) | no | — | GlassBilling domain | Explicitly deferred by freeze rules | Deferred |
| PayPal | no | partial (`PayPalService`, unrouted) | no | — | GlassBilling domain | Deferred | Deferred |
| Domains / DNS / mail commerce | no | yes (Domain*/DnsZone/Mail* + PowerDNS/Mailcow drivers) | no | GB (dormant) | GlassBilling domain | Deferred product lines | Deferred |
| WHMCS module compatibility | no | partial (`WhmcsModuleLoader`) | no | GB (dormant) | GlassBilling domain | Deferred; relevant to Glasshosting-WHMCS estate later | Deferred |
| Nodes / allocations / console / files / backups / server runtime | no | no (client only) | yes | GPan | GPan | Never migrates; accessed via GB jobs + portal launch | Deferred (v1 manual) |

## Reading the map

Three patterns dominate. First, everything **commercially required for v1 already exists, routed and tested, in GlassPortal's embedded module** — which is why the commercial v1 decision (see `commercial-v1-decision.md`) designates it the operative billing surface. Second, the standalone repo is consistently the **richer but unwired** implementation — its value concentrates in provisioning execution, connector drivers, and ledger-grade financial modeling, all of which are deferred domains for v1. Third, **no capability requires GlassPanel for v1**, because provisioning remains intent-plus-manual-fulfillment until execution is explicitly approved.
