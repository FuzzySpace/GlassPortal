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
| **28A** | Repository consolidation ADR: GlassPortal hosts the active code; embedded GlassBilling module inside it. **Corrected (29B/29C):** standalone GlassBilling is preserved / reference / *potential canonical* billing service, not legacy/dead. | `docs/architecture/repository-consolidation.md`, `docs/phase28a/` |

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

- **29C — billing architecture reconciliation** (pending; scoped in
  [`../architecture/billing-architecture-reconciliation.md`](../architecture/billing-architecture-reconciliation.md)):
  decide the long-term **canonical billing/provisioning service** — the embedded
  GlassBilling-domain module inside GlassPortal, the **standalone GlassBilling
  service** (designed to integrate with GlassPortal *and* GlassPanel), or a
  reconciled hybrid. **Required tracks include SDK / API contract parity** —
  **do NOT finalize the architecture until the SDK/API contract is inventoried,
  compared, and mapped** (plus data-ownership reconciliation and runtime
  alignment). Until 29C resolves, the standalone service is **preserved /
  reference / potential canonical** — not retired, archived, deleted, dismissed,
  migrated, or moved. Its own approval + ADR.
- **Runtime consolidation EXECUTION** (gated; future approved): execute the
  Phase 29B plan (Stages 3–5 — restrict/redirect/stop with backups + rollback)
  only after explicit approval. Until then the standalone billing runtime stays
  online.
- **30 — provisioning driver execution layer** (proposed): request → approval →
  driver with real infrastructure behind explicit approval and per-driver safety,
  plus Stripe live-mode hardening. Its own approval + ADR.

## Known unresolved issues

- **Billing-service reconciliation pending (Phase 29C).** Two billing
  implementations exist — the embedded module inside GlassPortal (current active)
  and the standalone GlassBilling service (preserved / potential canonical,
  integrates with GlassPortal + GlassPanel). Which is canonical long-term is
  **unresolved**; nothing is retired or moved until 29C.
- **SDK / API contract parity NOT YET DONE (finalization gate).** The
  GlassPortal ↔ GlassBilling architecture **must not be finalized** until the
  SDK/API contract is **inventoried, compared, and mapped** (Track 2 of 29C —
  see [`../architecture/billing-architecture-reconciliation.md`](../architecture/billing-architecture-reconciliation.md)).
  This gate is currently **open**.
- **Runtime consolidation pending execution.** The plan exists (Phase 29B) but is
  not executed: canonical pilot runtime GlassPortal `:18188` and the standalone
  billing runtime `:18180` coexist by design. No redirect/merge/decommission
  until a future approved execution phase. Pilot on `:18188`; the standalone
  runtime stays online and preserved on `:18180`.
- **Placeholder Stripe price ids.** Seeded plans use `price_local_*` placeholders;
  operator must set real Stripe TEST price ids before live checkout (readiness
  warns until then).
- **Provisioning is request-only.** No driver executes infrastructure yet
  (deferred to the driver-execution phase).
