# Phase 5 — Live GlassBilling Customer Bridge

This document describes the Phase 5 additions to GlassPortal: the live
GlassBilling customer bridge. Phase 5 extends the read-only connector from
Phase 4 to surface GlassBilling customer data in both staff and customer-facing
views. All integration is read-only. No billing mutations are made from
GlassPortal in this phase.

## What was added

### GlassBillingClient — new methods

| Method                      | Endpoint                            | Purpose                        |
|-----------------------------|-------------------------------------|--------------------------------|
| `customers(array $query)`   | `GET /api/v1/admin/customers`       | List all GlassBilling customers|
| `customer(string $id)`      | `GET /api/v1/admin/customers/{id}`  | Single customer detail         |

All methods return `GlassBillingResult` and never throw into callers.

### GlassBilling customer mapping model

The customer mapping is stored in the `organizations` table, which already
carried `glassbilling_customer_id` from Phase 3. No new migrations were needed.

```
users ──▶ organizations.glassbilling_customer_id ──▶ GlassBilling customer
```

| Table          | Column                    | Type    | Meaning                         |
|----------------|---------------------------|---------|---------------------------------|
| `organizations`| `glassbilling_customer_id`| string? | GlassBilling customer ID or null|

`users.glassbilling_customer_id` is not needed — all customer users inherit
their billing identity through `users.organization_id → organizations →
glassbilling_customer_id`. This design allows multiple portal users per billing
customer (org admin, sub-accounts, etc.) without duplicating the mapping.

### Admin: Customer detail page (new)

**Route:** `GET /admin/customers/{id}` → `admin.customers.show`  
**Controller:** `Admin\CustomersController@show`  
**View:** `resources/views/admin/customer-detail.blade.php`

Shows:
- Local organization metadata (name, slug, billing email, status, member count)
- GlassBilling customer detail if linked and online (`id`, `name`, `email`,
  `status`, `balance_usd`)
- All portal users in the organization
- Customer services (scoped by `customer_id`)
- Provisioning requests (scoped by `customer_id`)
- Invoice approvals (scoped by `customer_id`)
- "Phase 6 controlled writes" stub for edit/link actions

Degrades gracefully in all offline/unconfigured states.

### Admin: Customer list (updated)

**Route:** `GET /admin/customers` → `admin.customers`  
**View:** `resources/views/admin/customers.blade.php`

New columns:
- **GlassBilling** — `linked` badge if `glassbilling_customer_id` is set,
  `not linked` otherwise.
- **View →** link to `/admin/customers/{id}` detail page.

Shows a warning banner when GlassBilling is not configured.

### Portal: Support page (updated)

**Route:** `GET /portal/support` → `portal.support`  
**View:** `resources/views/portal/support.blade.php`

Now shows customer context cards:
- **Your Account** — name, email, organization, billing email.
- **Billing Account** — `linked`/`not linked` badge, GlassBilling customer ID
  if linked, clear instructions when not linked.

Updated support notice from Phase 4 → Phase 6 label.

### Healthcheck (updated)

New check `db.customer_mapping` validates that
`organizations.glassbilling_customer_id` column is present.

```
✓ db.customer_mapping  organizations.glassbilling_customer_id column present
```

Fails if the column is missing and instructs the user to run migrations.

### Organization factory (new)

`database/factories/OrganizationFactory.php` added for use in tests.

```php
Organization::factory()->create();                              // no link
Organization::factory()->withGlassBillingId('gb_cust_1')->create(); // linked
Organization::factory()->suspended()->create();                 // suspended
```

## Routes added / changed

| Method | Path                    | Name                   | Change   |
|--------|-------------------------|------------------------|----------|
| GET    | `/admin/customers/{id}` | `admin.customers.show` | **New**  |

All other routes from Phase 4 are preserved and unchanged.

## Required env vars

No new env vars introduced in Phase 5. The same Phase 4 vars apply:

| Env var                   | Required for live data |
|---------------------------|------------------------|
| `GLASSBILLING_BASE_URL`   | Yes                    |
| `GLASSBILLING_API_TOKEN`  | Yes                    |
| `GLASSBILLING_TIMEOUT`    | Optional (default 8s)  |
| `GLASSBILLING_VERIFY_TLS` | Optional (default true)|

## What remains read-only (Phase 5)

- All GlassBilling data is read-only.
- Cannot link/unlink an organization to a GlassBilling customer from the portal.
- Cannot edit GlassBilling customer fields from the portal.
- Cannot approve/reject invoices from the portal.
- Cannot provision services from the portal.
- Cannot manage DNS, mail, game panels, or VMs from the portal.

## What moves to Phase 6 (controlled writes)

- Link / unlink organizations to GlassBilling customer IDs.
- Invoice approval / rejection actions.
- Provisioning request approve / reject actions.
- Customer account metadata edits (scoped, authorized).
- Support ticket integration.
- GlassPanel, Aria, Proxmox, PowerDNS, Mailcow connectors.

## Tests

| Test file                                          | Covers                                              |
|----------------------------------------------------|-----------------------------------------------------|
| `tests/Unit/GlassBillingClientTest.php`             | `customers()`, `customer()` — success, 404, unconfigured |
| `tests/Feature/AdminRoutesTest.php`                 | Customer detail renders, linked state, 404 for unknown org |
| `tests/Feature/PortalRoutesTest.php` (new)          | Portal dashboard/services/support with/without org, with/without GB link, offline degradation |
| `tests/Feature/HealthCheckCommandTest.php`          | New `db.customer_mapping` check |

49 tests / 101 assertions passing. SQLite in-memory, no live GlassBilling required.

## Validation commands

```bash
composer validate
php artisan optimize:clear
php artisan route:list
php artisan glassportal:healthcheck
# --strict warns/fails when GlassBilling is offline; safe to run without it:
php artisan glassportal:healthcheck --strict    # expect exit 0 only when GB online
php artisan test
find app routes config database/factories -name "*.php" | xargs php -l
grep -rn -E "(password|secret|token|key)\s*=\s*['\"][^'\"]{8,}" app config routes
```
