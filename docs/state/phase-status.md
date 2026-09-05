# Phase Status (Drift Guard)

**Date:** 2026-07-03 · **Track:** GlassPortal (canonical)

| Phase | Scope | Status |
| :--- | :--- | :--- |
| GP 1–22 | Foundation, SSO/SDK, SIONA, GlassSite | Complete |
| GP 23 | Billing source-of-truth ADR + gap matrix | Complete (guardrail wording superseded by 29C) |
| GP 24–28 | Embedded billing engine: Stripe foundation, entitlements, provisioning requests, checkout/webhooks, self-service | Complete, tested |
| GP 28A | Repository consolidation ADR | Complete ("legacy" framing corrected by 29C) |
| **GP 29** | Product-test / pilot readiness: readiness dashboard (`/admin/pilot-readiness`), `glassportal:pilot-readiness` command, pilot-safe seed data, runbooks, runtime-exposure + safeguard addenda | Complete |
| **GP 29C** | Architecture reconciliation (docs only): reconciliation ADR, capability map, SDK/API parity, runtime consolidation plan, commercial v1 decision | **Complete** |
| **GP 29D** | Commercial v1 stabilization: admin bootstrap checks, commercial-readiness command, flow/boundary/contract tests, launch runbook | **In progress** |
| **GP 29E** | First commercial release candidate: validation matrix, release notes, tag proposal | **In progress** |
| Stage D/E/F (post-v1) | Contract parity fixes, ownership consolidation ADR, runtime consolidation | Not started — approval-gated |

GlassBilling standalone track: GB 1–7 complete as of 2026-05-11; no further GB phases planned until Stage D/E.

**AI/operator preflight:** before working on this estate, read `docs/state/runtime-map.md`, `docs/state/repository-map.md`, this file, and `docs/runbooks/ai-operator-preflight.md`. Also see `docs/runbooks/ai-worker-preflight.md` and `docs/phase29/product-test-pilot-readiness.md`.

## Known unresolved issues

- **Runtime consolidation pending.** Two runtimes coexist by design: canonical GlassPortal `:18188` and companion billing `:18180`. No redirect/merge/decommission until an approved phase. Pilot on `:18188`; reference only on `:18180`.
- **Placeholder Stripe price ids.** Seeded plans use `price_local_*` placeholders; operator must set real Stripe TEST price ids before live checkout (readiness warns until then).
- **Provisioning is request-only.** No driver executes infrastructure yet (deferred to the driver-execution phase).
