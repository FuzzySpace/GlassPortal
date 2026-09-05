# Commercial V1 Validation Matrix (Phase 29E)

**Validated:** 2026-07-03 · **Environment:** PHP 8.3.6 · PHPUnit 11.5.55 · Laravel 12 (portal) / Laravel 11.51 (standalone GlassBilling) · SQLite in-memory for tests

This matrix records exactly what was validated for the `glasshouse-commercial-v1-rc1` proposal, what the result was, and what was deliberately not validated. Anything not listed as PASS was not claimed.

## 1. Automated test suites

| # | Surface | How validated | Result |
| :- | :--- | :--- | :--- |
| 1 | GlassPortal full suite | `vendor/bin/phpunit` (APP_ENV=testing) | **PASS — 772 tests, 2,257 assertions, 0 failures** |
| 2 | Phase 29D commercial + contract tests | `--filter "Commercial\|ContractFixtures"` | **PASS — 32 tests, 260 assertions** |
| 3 | Portal-auth SDK (packages/glasshouse) | `--filter "PortalAuthSdk\|SdkDogfood"` (dogfooded in main suite) | **PASS — 61 tests, 138 assertions** |
| 4 | Stripe webhook endpoint hardening | `StripeWebhookEndpointTest` (in suite) | **PASS** (fail-closed, signature, idempotency) |
| 5 | Standalone GlassBilling suite | n/a | **NOT APPLICABLE — repo ships no tests/ and no phpunit.xml**; recorded, not fixed (do-not-modify boundary) |

## 2. Commands and structural checks

| # | Check | How validated | Result |
| :- | :--- | :--- | :--- |
| 6 | `glassportal:healthcheck` | Run against migrated SQLite DB | **PASS — all required checks** |
| 7 | `glassportal:commercial-readiness` on unconfigured system | Run against fresh DB | **Correct: NOT READY, 7 expected blockers, exit 1** |
| 8 | `glassportal:commercial-readiness` on configured system | `CommercialReadinessCommandTest` | **PASS — exit 0, secrets never printed** |
| 9 | Migrations apply cleanly from zero | `php artisan migrate` on fresh SQLite | **PASS — 23 migrations** |
| 10 | Standalone GlassBilling boots | `composer install` + `php artisan route:list` | **PASS — Laravel 11.51, 97 routes** (structural only; no behavior claims) |

## 3. Commercial flow coverage (all Stripe-mocked, no external calls)

| # | Flow step | Test | Result |
| :- | :--- | :--- | :--- |
| 11 | Customer plan browsing | `CommercialPilotFlowTest`, `PortalCheckoutTest` | PASS |
| 12 | Checkout start persists session, grants nothing | same | PASS |
| 13 | Failed checkout persists nothing | `test_failed_checkout_start_persists_nothing` | PASS |
| 14 | Webhook → subscription, invoice, payment records | pilot flow test | PASS |
| 15 | Webhook idempotency (duplicate events) | pilot flow + `StripeWebhookServiceTest` | PASS |
| 16 | Entitlement activation from active subscription | pilot flow test | PASS |
| 17 | Provisioning request created approval-gated, never executed | pilot flow + boundary tests | PASS |
| 18 | Admin review/approve without infrastructure calls | pilot flow test (`Http::assertSentCount(1)` — the lone mocked checkout call) | PASS |
| 19 | Customer sees own status; cross-org isolation (404, no leak) | `test_customer_sees_own_billing_status_but_not_other_organizations` | PASS |

## 4. Boundary and drift guards

| # | Boundary | Test/check | Result |
| :- | :--- | :--- | :--- |
| 20 | Customers blocked from all staff/admin surfaces | `BoundaryEnforcementTest` | PASS |
| 21 | Staff/support blocked from owner/admin billing | same | PASS |
| 22 | Staff roles blocked from customer portal | same | PASS |
| 23 | No public admin registration route; admin creation CLI-only | same | PASS |
| 24 | Provisioning lifecycle transitions make zero network calls | same (`Http::assertNothingSent`) | PASS |
| 25 | No provider execution clients in codebase | same + readiness check | PASS |
| 26 | Contract fixtures match live model enums (9 fixtures) | `ContractFixturesTest` | PASS |
| 27 | App pointed at preserved :18180 runtime → blocker | `CommercialReadinessCommandTest` | PASS |

## 5. Explicitly NOT validated in this phase

Live Stripe traffic (test- or live-mode against real Stripe), production database migration state, the production docker runtime on the VPS, standalone GlassBilling behavior beyond boot, GlassPanel integration, and email delivery. Each requires production access or founder approval and is sequenced in `docs/runbooks/commercial-v1-launch.md`.

