# State — Phase Status

> **Drift anchor.** Where we are in the build. Confirm the active phase and the
> known unresolved issues before starting work. Last reviewed: Phase 29
> (safeguard addendum). Active development branch: `claude/zealous-hamilton-rgqde2`.

## Completed phases (23 – 28A)

| Phase | Summary | Key docs |
|---|---|---|
| **23** | Billing source-of-truth reconciliation; corrected LXC 310/GHpanel as legacy GlassPanel (not billing). | `docs/architecture/billing-source-of-truth.md`, `docs/phase23/` |
| **24** | Stripe-first billing foundation (customers/products/plans/subscriptions/invoices/payments/events; `StripeBillingClient`). | `docs/phase24/` |
| **25** | Billing service entitlements + lifecycle state machine. | `docs/phase25/` |
| **26** | Approval-gated provisioning request engine (no infra execution). | `docs/phase26/` |
| **27** | Stripe Checkout + verified webhook intake (idempotent, fail-closed). | `docs/phase27/stripe-checkout-webhook-intake.md` |
| **28** | Customer billing self-service (read/request only; billing change requests). | `docs/phase28/customer-billing-self-service.md` |
| **28A** | Repository consolidation ADR: GlassPortal canonical; GlassBilling a bounded module inside it; standalone repo legacy/reference. | `docs/architecture/repository-consolidation.md`, `docs/phase28a/` |

(Earlier phases ≤ 22 — identity/RBAC, SSO/signed launch, SIONA connector + tenant
provisioning, GlassSite public catalog — are foundational and complete.)

## Active phase

**Phase 29 — product-test / pilot readiness.** Readiness dashboard
(`/admin/pilot-readiness`), `glassportal:pilot-readiness` command, pilot-safe
seed data, runbooks, and readiness checks. Addenda:

- **29 runtime-exposure addendum** — runtime map + legacy-URL readiness guard.
- **29 safeguard addendum (this)** — state docs (`docs/state/*`) + AI/operator
  preflight runbook + drift-guard readiness checks.

Docs: `docs/phase29/product-test-pilot-readiness.md`,
`docs/phase29/runtime-exposure-inventory.md`,
`docs/runbooks/pilot-product-test.md`, `docs/runbooks/ai-worker-preflight.md`.

## Next planned phase

- **29B — runtime consolidation** (proposed): decide whether to redirect/retire
  `:18180`, consolidate databases, and decommission the legacy `ghbilling-*`
  stack. Requires its own approval + ADR.
- **30 — provisioning driver execution layer** (proposed): request → approval →
  driver with real infrastructure behind explicit approval and per-driver safety,
  plus Stripe live-mode hardening. Its own approval + ADR.

(Order/numbering of 29B vs 30 to be confirmed when the next phase is approved.)

## Known unresolved issues

- **Runtime consolidation pending.** Two runtimes coexist by design: canonical
  GlassPortal `:18188` and legacy billing `:18180`. No redirect/merge/decommission
  until an approved phase (29B). Pilot on `:18188`; reference only on `:18180`.
- **Placeholder Stripe price ids.** Seeded plans use `price_local_*` placeholders;
  operator must set real Stripe TEST price ids before live checkout (readiness
  warns until then).
- **Provisioning is request-only.** No driver executes infrastructure yet
  (deferred to the driver-execution phase).
