# Runbook — Commercial V1 Launch

**Purpose:** the exact operator sequence to take GlassPortal from a validated build to onboarding the first paying customer, per `docs/architecture/commercial-v1-decision.md`. Every step is manual and supervised; nothing here provisions infrastructure automatically.

## 1. Preflight

Complete `docs/runbooks/ai-operator-preflight.md` first. Confirm you are operating against the canonical portal (:18188). Pull the release-candidate build and run, in order: `php artisan test` (full suite must be green), `php artisan glassportal:healthcheck`, and `php artisan glassportal:commercial-readiness`. A readiness result of `NOT READY` blocks launch; `READY WITH WARNINGS` requires reading each warning and deciding explicitly.

## 2. Bootstrap and configuration

Create the owner account per `docs/runbooks/admin-bootstrap.md` if none exists. In the admin UI, create at least one billing product and one active plan whose `stripe_price_id` matches a real Price in the Stripe dashboard (test mode first). Configure environment: `GLASSBILLING_ENABLED=true`, `GLASSBILLING_CHECKOUT_ENABLED=true`, `GLASSBILLING_WEBHOOKS_ENABLED=true`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` (never commit or print values). In the Stripe dashboard, register exactly one webhook endpoint pointing at `https://<portal>/api/billing/stripe/webhook` with the handled events listed in `docs/state/sdk-contract-map.md` §4. Re-run the readiness command.

## 3. Test-mode end-to-end walkthrough (mandatory before live keys)

With Stripe test keys: register a customer account; browse `/products` and `/portal/billing/plans`; start checkout on the pilot plan and pay with a Stripe test card; confirm the webhook fires (Stripe dashboard delivery log) and that within the portal the subscription shows active, an invoice and payment appear, an entitlement is created, and a provisioning request enters `pending_approval`. As admin: review the request under `/admin/provisioning/requests`, approve it, mark it queued → running while performing the (simulated) manual fulfillment, record the provider reference in the notes, and complete it. Confirm the customer sees the updated status, and that the customer account can see none of another organization's records.

## 4. Manual fulfillment procedure (live operations)

When a real provisioning request is approved: perform the actual service setup by hand (e.g., on GlassPanel or the target host), outside this system; record the provider reference and any hostnames/IPs in the request notes; transition the request `queued → running → completed` as the work progresses; and notify the customer through the support channel. Automatic execution stays disabled — the readiness command's `boundary.no_infra_execution` check enforces that no execution client ships in the portal.

## 5. Go-live gate (switching to live Stripe keys)

Only after §3 passes end-to-end and with explicit founder approval: swap `STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET` to live values, re-register the live-mode webhook endpoint, re-run `glassportal:commercial-readiness` (the key check will report LIVE), and process a real minimal-value transaction with a founder-controlled card before inviting the first customer. Confirm backups of the portal database are running before accepting external money.

## 6. Incident basics

If checkout fails: check `storage/logs/laravel.log` and the Stripe dashboard request log; the fail-safe design means no local records are created on failed checkout starts. If webhooks stop: Stripe retries automatically; verify the signing secret and the single registered endpoint; replay from the Stripe dashboard once fixed — intake is idempotent by event ID, so replays are safe. If the wrong runtime was tested: stop and re-read `docs/state/runtime-map.md`; :18180 is the preserved GlassBilling companion, not the portal. For anything requiring a prohibited action, see the approval gates in `docs/architecture/glassportal-glassbilling-reconciliation.md` §9.

