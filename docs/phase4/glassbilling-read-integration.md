# Phase 4 — Read-Only GlassBilling Integration

This document describes what was added in Phase 4 to wire GlassPortal to the
GlassBilling API in read-only mode. No write actions are available through the
portal; all mutations continue to happen directly in GlassBilling.

## What was added

### config/glassbilling.php (new)

Canonical config file for the GlassBilling connector. All values are
env-driven.

| Env var                  | Default | Purpose                              |
|--------------------------|---------|--------------------------------------|
| `GLASSBILLING_BASE_URL`  | (empty) | Base URL of the GlassBilling API     |
| `GLASSBILLING_API_TOKEN` | (empty) | Bearer token for authenticated calls |
| `GLASSBILLING_TIMEOUT`   | 8       | HTTP request timeout in seconds      |
| `GLASSBILLING_VERIFY_TLS`| true    | Verify TLS certificates              |

**Note:** `GLASSBILLING_API_URL` (Phase 3 name) is replaced by
`GLASSBILLING_BASE_URL`. Update `.env` files accordingly.

### GlassBillingResult (new value object)

`App\Services\GlassBilling\GlassBillingResult` — `final readonly class`.

Every client method now returns this normalized object instead of plain arrays.

| Property     | Type      | Meaning                                     |
|--------------|-----------|---------------------------------------------|
| `ok`         | bool      | true on HTTP 2xx success                    |
| `status`     | ?int      | HTTP status code, null if no response       |
| `data`       | mixed     | Decoded JSON body on success, null otherwise|
| `error`      | ?string   | Sanitized error message on failure          |
| `latency_ms` | ?int      | Round-trip time in milliseconds             |

Factory methods: `GlassBillingResult::success()`, `::failure()`, `::unconfigured()`.

### GlassBillingClient (rewritten)

`App\Services\GlassBilling\GlassBillingClient`

- Reads from `config/glassbilling.php` (not glasshouse.php).
- Returns `GlassBillingResult` from all methods.
- Adds Bearer token only when `GLASSBILLING_API_TOKEN` is non-empty.
- Respects `GLASSBILLING_VERIFY_TLS` via `withOptions(['verify' => ...])`.
- Tracks latency with `microtime(true)`.
- Sanitizes error messages — no credentials in logs or output.
- Never throws into controllers.

#### Methods

| Method                              | GlassBilling endpoint                                      |
|-------------------------------------|------------------------------------------------------------|
| `health()`                          | `GET /api/health` → plain array (backward compat)          |
| `dashboardTiles()`                  | `GET /api/v1/admin/dashboard-tiles`                        |
| `customerServices(array $query)`    | `GET /api/v1/admin/customer-services`                      |
| `customerService(string $id)`       | `GET /api/v1/admin/customer-services/{id}`                 |
| `customerServiceTimeline(string $id)` | `GET /api/v1/admin/customer-services/{id}/timeline`      |
| `provisioningRequests(array $query)`| `GET /api/v1/admin/provisioning-requests`                  |
| `provisioningRequest(string $id)`   | `GET /api/v1/admin/provisioning-requests/{id}`             |
| `invoiceApprovals(array $query)`    | `GET /api/v1/admin/invoice-approvals`                      |
| `invoiceApproval(string $id)`       | `GET /api/v1/admin/invoice-approvals/{id}`                 |

### New admin routes and controllers

| Route                            | Name                         | Controller                         |
|----------------------------------|------------------------------|------------------------------------|
| `GET /admin/services/{id}`       | `admin.services.show`        | `ServicesController@show`          |
| `GET /admin/provisioning/{id}`   | `admin.provisioning.show`    | `ProvisioningController@show`      |
| `GET /admin/billing-approvals`   | `admin.billing-approvals`    | `BillingApprovalsController@index` |
| `GET /admin/billing-approvals/{id}` | `admin.billing-approvals.show` | `BillingApprovalsController@show` |

New controller: `App\Http\Controllers\Admin\BillingApprovalsController`

### Updated controllers

| Controller                          | Change                                                    |
|-------------------------------------|-----------------------------------------------------------|
| `Admin\DashboardController`         | Uses `dashboardTiles()`, service/provisioning/approval counts |
| `Admin\ServicesController`          | Uses `customerServices()` + new `show()` with timeline    |
| `Admin\ProvisioningController`      | Uses `provisioningRequests()` + new `show()`              |
| `Portal\ServicesController`         | Filters by `glassbilling_customer_id` if set; shows "no linked customer" otherwise |
| `Portal\DashboardController`        | Scopes service list to customer's `glassbilling_customer_id` |

### New and updated views

| View                                  | Type    |
|---------------------------------------|---------|
| `admin/dashboard.blade.php`           | Updated |
| `admin/services.blade.php`            | Updated |
| `admin/provisioning.blade.php`        | Updated |
| `admin/service-detail.blade.php`      | New     |
| `admin/provisioning-detail.blade.php` | New     |
| `admin/billing-approvals.blade.php`   | New     |
| `admin/billing-approval-detail.blade.php` | New |
| `portal/services.blade.php`           | Updated |
| `portal/dashboard.blade.php`          | Updated |

All views render safely when GlassBilling is unconfigured or offline. No
page errors on missing data — tables show empty-state messages, status cards
show the connector state.

### Updated staff sidebar

`Invoice Approvals` nav link added under the customer-facing section.
`Billing` stub updated from Phase 4 → Phase 5 label (write actions pending).

### Healthcheck updates (`glassportal:healthcheck`)

New `{--strict}` flag. New `db.auth_tables` check.

| Mode        | GlassBilling offline/auth error |
|-------------|----------------------------------|
| non-strict  | warn (yellow `!`), exit 0        |
| `--strict`  | fail (red `✗`), exit 1           |

New check `glassbilling.auth` fires when HTTP 401/403 is returned, warning
that the API token should be verified.

### Tests

| Test file                                    | Covers                                      |
|----------------------------------------------|---------------------------------------------|
| `tests/Unit/GlassBillingClientTest.php`       | Client config, success, auth failure, connection failure, bearer token presence |
| `tests/Feature/AdminRoutesTest.php`           | All admin routes render without GlassBilling; auth/RBAC guards; real data with Http::fake |
| `tests/Feature/HealthCheckCommandTest.php`    | Non-strict passes unconfigured; strict fails on offline/401 |

SQLite in-memory enabled in `phpunit.xml` for all feature tests.

## What remains stubbed / Phase 5+

- All write actions (approve/reject/provision) — modal stubs present in detail
  views with "Phase 5" label.
- Billing section in staff sidebar — still stub.
- Customer invoice view.
- GlassPanel, Aria, Proxmox, PowerDNS, Mailcow, Pterodactyl connectors.
- OAuth / SSO with GlassBilling.
- Organizations ↔ GlassBilling customer sync (webhook or login trigger).

## Module boundary rules (unchanged)

- GlassPortal reads state from GlassBilling; it does not own billing logic.
- No direct DB coupling to GlassBilling — API only.
- All connector calls go through `GlassBillingClient`; controllers never call
  `Http::` directly.
- No secrets in git. All credentials in `.env` only.

## Validation commands

```bash
composer validate
php artisan optimize:clear
php artisan route:list
php artisan glassportal:healthcheck
php artisan glassportal:healthcheck --strict   # requires GLASSBILLING_BASE_URL + TOKEN
php artisan test
find app routes config -name "*.php" | xargs php -l
```
