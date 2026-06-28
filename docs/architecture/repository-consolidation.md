# ADR: Repository Consolidation — GlassPortal is the Canonical Application Repo

- **Status:** Accepted (Phase 28A)
- **Date:** 2026-06-28
- **Related:**
  [`docs/architecture/module-boundaries.md`](./module-boundaries.md),
  [`docs/architecture/billing-source-of-truth.md`](./billing-source-of-truth.md),
  [`docs/phase28a/repository-consolidation-adr.md`](../phase28a/repository-consolidation-adr.md),
  Phases 23–28 (`docs/phase23/` … `docs/phase28/`)
- **Scope:** Documentation + decision only. **No code is moved, renamed, or
  deleted in this phase. The standalone `FuzzySpace/GlassBilling` repository is
  not touched.**

---

## Context

### Current repository reality

The `FuzzySpace` GitHub organization contains two repositories that both carry
the "GlassBilling" name in some form:

| Repository | What it actually is today |
|---|---|
| **`FuzzySpace/GlassPortal`** | The active application. Laravel 11 control plane **and** the home of all recent billing work. |
| **`FuzzySpace/GlassBilling`** (standalone) | A separate repository that pre-dates the in-portal billing build. Inactive; not the home of current development. |

Recent active development — **Phases 23 through 28** — was implemented **inside
`FuzzySpace/GlassPortal`**, not in the standalone repo. That work includes:

- the GlassBilling billing foundation (`config/billing.php`,
  `app/Services/Billing/*`, `app/Models/Billing*`, `billing_*` tables);
- Stripe checkout + verified webhook intake;
- billing customers / products / plans / subscriptions / invoices / payments /
  events;
- billing service entitlements + lifecycle;
- the approval-gated provisioning request engine;
- customer billing self-service (Phase 28).

In other words: **the working GlassBilling system already lives inside
GlassPortal as a bounded module.** The standalone `FuzzySpace/GlassBilling`
repository does not contain this code and is not where new billing work happens.

Two earlier documents frame GlassBilling as a *separate owner module*:

- [`module-boundaries.md`](./module-boundaries.md) lists GlassBilling as the
  billing system of record (a Phase 1 reference written before the in-portal
  build).
- [`billing-source-of-truth.md`](./billing-source-of-truth.md) records that
  **GlassBilling owns billing facts** and GlassPortal reads/requests them.

Neither of those is wrong about *domain ownership* — billing is still its own
bounded domain with clean edges. What was never pinned down is the **repository**
question: *which git repository is the canonical home of the active billing
code?* This ADR answers exactly that, and nothing more.

### Why this needs a decision

Leaving the repository question implicit creates real, avoidable risk:

- A future maintainer could "helpfully" start moving billing code into the
  standalone `FuzzySpace/GlassBilling` repo, splitting the active codebase.
- Someone could blindly import old code from the standalone repo on the
  assumption that it is the source of truth — it is not.
- Duplicate, drifting billing implementations could emerge across two repos.

A one-paragraph decision now prevents a painful divergence later.

---

## Decision

1. **`FuzzySpace/GlassPortal` is the canonical, active application repository.**
   All current development — billing included — happens here.

2. **GlassBilling is a bounded module / domain *inside* GlassPortal**, not a
   separate codebase. It keeps clean domain boundaries (naming, services, config,
   routes, docs, tests) but ships in the GlassPortal repo and deploys with it.

3. **The standalone `FuzzySpace/GlassBilling` repository is legacy / reference
   only**, unless and until an explicit, approved future extraction phase revives
   it. It is **not** the source of truth for the active billing code.

4. **Domain ownership is unchanged.** This ADR is about *repository location*,
   not about *who owns billing facts*. GlassBilling remains the billing domain
   of record (see [`billing-source-of-truth.md`](./billing-source-of-truth.md));
   GlassPortal still does not author another domain's facts carelessly. The
   billing module simply lives in this repo.

### Decision statement

- **Short term:** Continue active GlassBilling development **inside GlassPortal**.
- **Medium term:** Keep billing boundaries clean through naming, services,
  config, routes, docs, and tests — so the module *could* be extracted cleanly
  if the business ever requires it.
- **Long term:** Extract GlassBilling into its own deployable service **only if**
  there is a clear business reason, stable internal APIs, and enough operational
  capacity to maintain separate deployment and versioning.

---

## What justifies a future extraction

Do **not** extract on aesthetics or "microservices feel good." Extraction is
warranted only when **most** of the following are true:

- **Business reason:** GlassBilling must serve consumers beyond GlassPortal
  (e.g., another product, an external partner, or an independent billing API),
  or regulatory / contractual isolation requires a separate boundary.
- **Stable APIs:** the billing module exposes stable, versioned service
  interfaces that GlassPortal already consumes through a narrow, well-defined
  surface (not deep model/table coupling).
- **Operational capacity:** the team can own a *second* deployment pipeline,
  release cadence, on-call surface, schema-migration story, and security review
  for an independent service — without degrading either system.
- **Independent scaling / availability needs:** billing genuinely needs to scale
  or fail independently of the portal.
- **Clean seams already proven:** the boundaries below have been respected long
  enough that extraction is a lift-and-shift, not a rewrite.

If those are not clearly met, **keeping billing in-portal is the correct, lower-
risk choice.**

---

## What NOT to do (until an explicit extraction phase is approved)

- **Do not move billing code** out of GlassPortal into the standalone
  `FuzzySpace/GlassBilling` repo (or anywhere else).
- **Do not split active development** across two repositories.
- **Do not blindly import** code from the standalone `FuzzySpace/GlassBilling`
  repo into GlassPortal. If it is reviewed later, treat it strictly as
  **legacy / reference**, subject to source-control import + security review
  (the same posture applied to the GHpanel / LXC 310 legacy stack).
- **Do not delete, archive, rename, or otherwise mutate** the standalone
  `FuzzySpace/GlassBilling` repository as part of this or any nearby phase. This
  ADR only documents intent.
- **Do not rename billing namespaces** or relocate billing files to "prepare"
  for an extraction that has not been approved.

---

## Boundary rules that keep extraction *possible* (maintainer guidance)

Keep the GlassBilling module bounded using the **existing** conventions, so the
seam stays clean without any code movement:

| Concern | Canonical location |
|---|---|
| Config | `config/billing.php` |
| Services | `app/Services/Billing/*` |
| Models | `app/Models/Billing*` |
| Database | `billing_*` tables (migrations) |
| Admin UI | `resources/views/admin/billing/*` |
| Customer UI | `resources/views/portal/billing/*` |
| Docs | `docs/architecture/billing-*`, `docs/billing/`, or `docs/phase*` |
| Tests | billing-behavior tests under `tests/Unit/Billing/*` + feature tests |

Additional rules:

- **No new billing god-objects reaching across domains.** Billing talks to the
  rest of the portal through services and explicit relations, not by sprinkling
  billing logic into unrelated controllers/models.
- **No reverse coupling.** Non-billing code should depend on billing through a
  narrow surface (the billing services), not the other way around.
- **Behavior is unchanged by this ADR.** Stripe flow, webhook intake, the
  provisioning request engine, and customer billing self-service are **not**
  modified here.

---

## Migration / extraction rules (for the future maintainer who actually does it)

If a future, explicitly-approved phase extracts GlassBilling:

1. **Open a dedicated extraction phase** with its own ADR superseding this one.
   Do not do it incidentally inside an unrelated phase.
2. **Freeze the seam first.** Confirm GlassPortal consumes billing only through
   the billing services / a defined API surface; remove any deep coupling
   *before* moving code.
3. **Decide the data story explicitly:** does billing keep its own database?
   How are `billing_*` tables migrated/owned? How does GlassPortal read them
   (API vs. shared DB vs. event stream)?
4. **Stand up separate deployment + versioning** before flipping the switch:
   CI/CD, schema migrations, secrets management, security review.
5. **Reconcile with the standalone repo deliberately.** Decide whether the
   extracted service revives `FuzzySpace/GlassBilling`, starts fresh, or
   imports reviewed pieces of the legacy repo — never a blind import.
6. **Update the docs:** this ADR, `module-boundaries.md`, `billing-source-of-
   truth.md`, and `CLAUDE.md` must all reflect the new reality.

Until that phase exists and is approved, **the answer is: billing stays in
GlassPortal.**

---

## Consequences

**Positive**

- One canonical repo for active work — no split-brain development.
- Billing keeps clean internal boundaries, so a *future* extraction stays cheap.
- New contributors get an unambiguous answer to "where does billing code go?"
- The standalone repo is preserved untouched as reference, losing nothing.

**Negative / trade-offs**

- Billing cannot be deployed or versioned independently while it lives in-portal
  (accepted: there is no current business need for that).
- The `FuzzySpace/GlassBilling` repo name remains slightly confusing until a
  future phase resolves it; this ADR + `CLAUDE.md` mitigate that by stating the
  canonical home explicitly.

**Neutral**

- Domain ownership and the billing source-of-truth decision are unchanged; this
  ADR only fixes the *repository* question.
