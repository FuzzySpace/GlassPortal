# Runbook — SDK/API Parity Check

**Purpose:** verify, at any time, that GlassPortal's integration expectations still match what GlassBilling (standalone) and the contract fixtures say. Run before touching either repo's API surface, before Stage D/E consolidation work, and as part of release validation.

## 1. Preconditions

Work from clean checkouts of `FuzzySpace/GlassPortal` and `FuzzySpace/GlassBilling`. Do not modify the standalone repo during the check. Nothing in this runbook touches runtime.

## 2. Steps

**Step 1 — Regenerate the portal's expectation list.** In GlassPortal: `grep -n "'/api" app/Services/GlassBilling/GlassBillingClient.php`. Compare against §5 of `docs/state/sdk-contract-map.md`. Any new method in the client that is not in the contract map is undocumented drift — stop and update the map first.

**Step 2 — Regenerate the standalone's provided list.** In GlassBilling: `php artisan route:list --json` (or `grep -n "Route::" apps/billing/routes/api.php` if artisan is unavailable). Confirm every non-⚠ endpoint in the contract map §5 is present. Record any endpoint newly added server-side.

**Step 3 — Diff the two lists.** Expected known gap as of 2026-07-03: `admin/customers` and `admin/customers/{id}` missing server-side. Any *other* gap is new drift: file it in the reconciliation doc §5 table and do not proceed with consolidation work until dispositioned.

**Step 4 — Validate contract fixtures.** In GlassPortal run `php artisan test --filter=ContractFixtures`. This asserts the nine fixture payloads still match the frozen shapes (fields, enums, formats) in `docs/state/sdk-contract-map.md` §3.

**Step 5 — Verify enum parity.** Compare `ProvisioningRequest::STATUSES` in both repos and `BillingServiceEntitlement::STATUSES` (portal) vs. `CustomerService::STATUSES` (standalone) against contract map §3 `lifecycle-status.json`. Any new enum case on either side requires a contract map update plus fixture bump.

**Step 6 — Verify single webhook consumer.** In GlassPortal, `php artisan route:list | grep stripe` must show exactly one webhook route. In GlassBilling, confirm `StripeWebhookController` remains unreferenced in `routes/api.php`. If both are wired, this is a release blocker.

**Step 7 — Record the result.** Append date, commit SHAs of both repos, and pass/drift outcome to the parity log section below.

## 3. Pass criteria

The check passes when the only endpoint gap is the documented `admin/customers` drift (until Stage D closes it), fixture tests are green, enum sets match the contract map, and exactly one Stripe webhook consumer is wired estate-wide.

## 4. Parity log

| Date | GlassPortal SHA | GlassBilling SHA | Result |
| :--- | :--- | :--- | :--- |
| 2026-07-03 | `d0d663b` (+29C docs) | `f526a26` | Baseline recorded; known drift: `admin/customers` unrouted server-side; single webhook consumer confirmed |

