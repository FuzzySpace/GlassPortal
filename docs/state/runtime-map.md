# Runtime Map (Drift Guard)

**Date:** 2026-07-03 · **Status:** Authoritative — checked by `glassportal:commercial-readiness`

| Property | Value |
| :--- | :--- |
| Canonical pilot/commercial portal URL | `http://40.160.61.180:18188/login` |
| Preserved companion (GlassBilling) URL | `http://40.160.61.180:18180/login` — **not** the pilot portal; do not test against it as if it were |
| Portal container | `glassportal-source-app-1` (8088), compose project `glassportal-source` |
| Companion containers | `ghbilling-billing-1` (8080), `ghbilling-portal-1` (3000), `ghbilling-postgres-1` (5432 local), `ghbilling-redis-1` (6379 local), `ghbilling-mailhog-1` (1025/8025), compose project `ghbilling` |
| Stripe webhook consumer (exactly one) | GlassPortal `POST /api/billing/stripe/webhook` |
| Infrastructure execution | Disabled by default; approval-gated (no automatic execution in commercial v1) |

The canonical and companion URLs are distinct on purpose. If an operator or AI agent is about to run pilot/commercial validation against :18180, stop: that is the preserved companion runtime, not the portal. See `docs/architecture/runtime-consolidation-plan.md`.
