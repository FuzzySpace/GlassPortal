# State — Phase Status

> **Drift anchor.** Where we are in the build. Confirm the active phase and the
> known unresolved issues before starting work. Last reviewed: Phase 29B
> (runtime consolidation planning). Active development branch: `claude/zealous-hamilton-rgqde2`.

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

**Phase 29B — runtime consolidation planning.** Produced the controlled, staged
**plan** to consolidate the legacy billing runtime (`:18180`) into the canonical
GlassPortal runtime (`:18188`) — and executed **none** of it. The legacy
`ghbilling` stack **remains online**; retirement/restriction is **pending
explicit approval**, a verified backup, a dependency check, and confirmation that
GlassPortal covers required workflows. Advisory readiness/healthcheck doc checks
added. No runtime, routing, container, database, or Stripe change.

Docs: `docs/architecture/runtime-consolidation-plan.md`,
`docs/state/legacy-billing-runtime-inventory.md`,
`docs/runbooks/runtime-consolidation.md`.

## Next planned phase

- **Runtime consolidation EXECUTION** (gated; future approved): execute the
  Phase 29B plan (Stages 3–5 — restrict/redirect/stop with backups + rollback)
  only after explicit approval. Until then the legacy runtime stays online.
- **30 — provisioning driver execution layer** (proposed): request → approval →
  driver with real infrastructure behind explicit approval and per-driver safety,
  plus Stripe live-mode hardening. Its own approval + ADR.

## Known unresolved issues

- **Runtime consolidation pending execution.** The plan exists (Phase 29B) but is
  not executed: canonical GlassPortal `:18188` and legacy billing `:18180`
  coexist by design. No redirect/merge/decommission until a future approved
  execution phase. Pilot on `:18188`; reference only on `:18180`.
- **Placeholder Stripe price ids.** Seeded plans use `price_local_*` placeholders;
  operator must set real Stripe TEST price ids before live checkout (readiness
  warns until then).
- **Provisioning is request-only.** No driver executes infrastructure yet
  (deferred to the driver-execution phase).
