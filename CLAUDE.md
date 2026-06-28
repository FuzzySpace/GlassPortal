# GlassPortal — Project Guidance

GlassPortal is the **control plane** for the Glasshouse ecosystem: a Laravel 11
customer/staff portal that renders, orchestrates, and audits — it does **not**
own business state. Each capability has an owner module (see
[`docs/architecture/module-boundaries.md`](docs/architecture/module-boundaries.md)).

## Architecture at a glance

- **GlassPortal** — identity/RBAC, module links, module launch + audit
  (`module_launch_events`), SIONA tenant provisioning, public catalog.
- **GlassBilling** — billing/account/subscription **domain of record**. Active
  billing development (Phases 24–28) lives **inside GlassPortal** as a bounded
  module (`app/Services/Billing/*`, `app/Models/Billing*`, `billing_*` tables); a
  legacy read-only bridge to an external source also exists via
  `GlassBillingClient` (mapped by `organizations.glassbilling_customer_id`). See
  **Repository consolidation** below.
- **GlassSite** — public product catalog (`/products`), published marketing data
  only (`public_product_catalog_entries`).
- **SIONA** — external module; tenant provisioning + signed/back-channel launch.
  Per-module signing secret via `GLASSPORTAL_MODULE_SECRET_SIONA`.

## Repository consolidation (Phase 28A) + billing reconciliation pending (Phase 29C)

See [`docs/architecture/repository-consolidation.md`](docs/architecture/repository-consolidation.md)
and [`docs/phase28a/`](docs/phase28a/):

- **`FuzzySpace/GlassPortal` is the canonical, active application repository.**
  All current development happens here; the active billing code lives here today.
- **The GlassBilling-domain module lives inside GlassPortal** (Phases 24–28) as a
  bounded module — the current active billing implementation.
- **Standalone `FuzzySpace/GlassBilling` is NOT legacy/dead.** It is an existing
  **billing/provisioning service designed to integrate with GlassPortal and
  GlassPanel** — treat it as **preserved / reference / potential canonical
  billing service**, pending the **Phase 29C architectural reconciliation** that
  decides the long-term canonical billing service.
- **Do not retire, archive, delete, or dismiss** the standalone GlassBilling repo;
  **do not migrate its data or move its code** yet; **do not blindly import** it
  (source-control import + security review first, pending 29C).
- **Keep billing code namespaced and bounded** using the existing conventions:
  `config/billing.php`, `app/Services/Billing/*`, `app/Models/Billing*`,
  `billing_*` tables, `resources/views/admin/billing/*`,
  `resources/views/portal/billing/*`, `docs/billing` or `docs/phase*`, and
  billing-behavior tests.

This is a repository-location decision plus a *pending* billing-service
reconciliation (29C); it changes no billing behavior, the Stripe flow, the
provisioning request engine, or customer billing self-service, and it leaves the
standalone GlassBilling repo untouched.

## Dev & validation

```bash
# Docker (preferred when the daemon is available):
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec -T app php artisan test

# Local path (also what CI uses — tests run on sqlite :memory: via phpunit.xml):
php artisan test
php artisan glassportal:healthcheck
php artisan route:list
```

Tests use sqlite `:memory:` regardless of `.env`. Migrations must be cross-DB
safe (no `after()`). Match the repo's hand-aligned code style; Pint's default
preset is **not** the enforced style and CI does not run it.

## Billing & source-of-truth guardrails (Phase 23)

See [`docs/architecture/billing-source-of-truth.md`](docs/architecture/billing-source-of-truth.md)
and [`docs/phase23/`](docs/phase23/). Until billing reconciliation completes:

- **LXC 310 / GHpanel (`10.10.1.40`) is legacy GlassPanel — a game-server
  panel, NOT billing.** Do not treat it as a GlassBilling source of truth.
  Preserve it as Legacy GlassPanel Reference #001 / Migration Center Test Case
  #001; future GlassPanel work may review it only after source-control import +
  security review.
- **Billing features wait for source-of-truth reconciliation.** Don't add new
  billing lifecycle tables or write paths ahead of the decision.
- **Stripe-first remains the target** — but Stripe is owned by **GlassBilling**,
  not GlassPortal. GlassPortal never calls Stripe directly.
- **GlassBilling must not directly mutate infrastructure.** It emits
  entitlements / provisioning requests.
- **Provisioning must go through a request → approval → driver layer.** Generalize
  the SIONA module lifecycle pattern; never provision from the billing engine.

## General conventions

- Portal **reads** other modules' state and **requests** actions; it never writes
  to other modules' databases or ledgers.
- Every staff-initiated action is audited in the portal in addition to any
  downstream module audit.
- Never store or render secrets/tokens; never put secrets in `metadata` columns
  or render them on public pages.
