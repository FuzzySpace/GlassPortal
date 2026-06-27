# Phase 20 — SIONA Tenant Provisioning + Account Linking

## Overview

Phase 20 lets GlassPortal **provision a SIONA workspace (tenant)** for an
organization and link it automatically, replacing the manual
`/admin/module-links/create` step from Phase 19. It builds directly on the
Phase 18 registry and Phase 19 launch/connector work.

What Phase 20 adds:

- A dedicated, indexed `organizations.siona_workspace_id` column (promoted from
  the Phase 19 `organization_module_links.metadata` JSON).
- `SionaTenantProvisioningService` — orchestrates the provisioning handshake,
  idempotency, persistence, link upsert, and audit trail.
- `SionaConnectorClient::provisionTenant()` — the outbound server-to-server
  call that asks SIONA to create a workspace (no duplicate HTTP client).
- An **admin-only** (owner/admin) provision action on the customer detail page.
- Audit events recorded in the existing `module_launch_events` log.
- Three new healthcheck checks.

SIONA source code remains in its own repository. GlassPortal owns the workspace
mapping, the link record, the provisioning handshake, and the audit trail only.

---

## Architecture

```
Admin browser (owner/admin only)
    │
    └── POST /admin/customers/{organization}/siona/provision
            SionaProvisioningController
            → SionaTenantProvisioningService.provisionForOrganization()
                 1. audit: siona_provision_requested
                 2. idempotency check (workspace_id + active link) ──► already_linked
                 3. config gate (isProvisioningConfigured) ─────────► failed (unconfigured)
                 4. resolve workspace id:
                      known id (column / link / metadata)  ──► reuse, no outbound call
                      otherwise ──► SionaConnectorClient.provisionTenant()
                                       POST {SIONA_API_URL}{path}  (Bearer SIONA_API_TOKEN)
                                       ◄── { workspace_id }
                 5. persist organizations.siona_workspace_id
                 6. upsert organization_module_links (module_key=siona, active)
                      audit: siona_module_link_created | siona_module_link_updated
                 7. audit: siona_provision_succeeded
            → redirect back to customer detail with success/error flash
```

---

## Configuration

New `config/siona.php` `provisioning` block (all env-driven, no secrets in code):

| Variable | Default | Description |
|---|---|---|
| `SIONA_PROVISIONING_ENABLED` | `false` | Master switch for the provisioning feature. When false, the action returns `unconfigured` and makes no outbound call. |
| `SIONA_PROVISIONING_PATH` | `/api/tenants` | Relative path POSTed to for tenant creation: `{SIONA_API_URL}{path}`. |
| `SIONA_PROVISIONING_AUTH_MODE` | `signed_launch` | `auth_mode` assigned to the `organization_module_link` created on success. |

Provisioning also requires the existing Phase 18/19 SIONA credentials —
`SIONA_ENABLED=true`, `SIONA_API_URL`, and `SIONA_API_TOKEN` — because the
provisioning call uses the authenticated server-to-server back-channel.

```ini
SIONA_ENABLED=true
SIONA_API_URL=https://siona.internal.glasshouse.example
SIONA_API_TOKEN=…                       # server-side only, never returned
SIONA_PROVISIONING_ENABLED=true
SIONA_PROVISIONING_PATH=/api/tenants
SIONA_PROVISIONING_AUTH_MODE=signed_launch
```

---

## Data Model

### Migration

`2026_06_27_000001_add_siona_workspace_id_to_organizations_table.php`

```php
$table->string('siona_workspace_id')->nullable()->index();
```

- **Nullable** — organizations exist before SIONA is provisioned.
- **Indexed** — provisioning idempotency and reverse lookups query by it.
- Cross-DB safe (no `after()` modifier) — works on SQLite, MySQL, and Postgres.

The column stores only the opaque workspace id returned by SIONA — never a
credential or token.

### Organization model

- `siona_workspace_id` added to `$fillable`.
- `hasSionaWorkspace(): bool` — true when the column is populated.
- `sionaModuleLink(): ?OrganizationModuleLink` — the org's SIONA link (most
  recent), used for idempotency and link upsert.

---

## SionaTenantProvisioningService

**File:** `app/Services/Siona/SionaTenantProvisioningService.php`
Registered as a singleton in `AppServiceProvider`.

### `provisionForOrganization(Organization $org, ?User $actor = null, array $context = []): SionaTenantProvisioningResult`

Flow:

1. **Always** records `siona_provision_requested`.
2. **Idempotency** — if the org has a workspace id *and* an **active** SIONA
   link, returns `already_linked` and makes **no** outbound call.
3. **Config gate** — if `isProvisioningConfigured()` is false, records
   `siona_provision_failed` (reason `unconfigured`) and returns `unconfigured`.
4. **Workspace resolution** — reuses a known workspace id from the column, the
   link's `external_account_id`, or the Phase 19 `metadata.siona_workspace_id`;
   only calls SIONA when none is known.
5. Persists `organizations.siona_workspace_id`.
6. Upserts the `organization_module_links` row (`module_key=siona`, active,
   `external_account_id=workspace_id`, `metadata.siona_workspace_id`), recording
   `siona_module_link_created` or `siona_module_link_updated`.
7. Records `siona_provision_succeeded`.

### Result — `SionaTenantProvisioningResult`

```php
ok           : bool
outcome      : 'provisioned' | 'already_linked' | 'unconfigured' | 'failed'
workspaceId  : ?string
moduleLinkId : ?int
message      : string   // human-safe; never contains credentials
```

---

## SionaConnectorClient::provisionTenant()

**File:** `app/Services/Siona/SionaConnectorClient.php`

```php
provisionTenant(array $payload): array
// → ['ok' => bool, 'status' => 'unconfigured'|'ok'|'degraded'|'error',
//    'workspace_id' => ?string, 'message' => string, 'http_status' => ?int]
```

- Never throws — all errors are caught and normalized (mirrors `health()`).
- Uses `SIONA_API_TOKEN` only to set the `Authorization: Bearer` header — the
  token is **never** returned or echoed into the result/message.
- Tolerates several response envelope shapes when extracting the workspace id
  (`workspace_id`, `id`, `data.*`, `tenant.*`).
- Sanitises credential-bearing URL patterns out of exception messages.

New config helpers on the same client:

- `isBackChannelReady(): bool` — `SIONA_ENABLED` + `SIONA_API_URL` + `SIONA_API_TOKEN` present.
- `isProvisioningConfigured(): bool` — back-channel ready **and** `SIONA_PROVISIONING_ENABLED`.

---

## Admin Action

| | |
|---|---|
| Route | `POST /admin/customers/{organization}/siona/provision` |
| Name | `admin.customers.siona.provision` |
| Controller | `Admin\SionaProvisioningController@store` |
| RBAC | **owner/admin only** |

The surrounding admin route group allows `owner,admin,staff,support`. This route
adds a **stacked** `role:owner,admin` middleware; the two intersect, so
staff/support receive **403** and customers/guests are blocked as usual.

The customer detail page (`/admin/customers/{id}`) shows a **SIONA Workspace**
panel with the workspace/link state. The "Provision SIONA workspace" /
"Re-sync SIONA link" button is rendered only for owner/admin and only when
provisioning is configured.

---

## Audit Trail

All provisioning events are appended to the existing authoritative audit log,
`module_launch_events` — **no parallel audit system is introduced**. Each row is
denormalized with `module_key=siona` and the resolved `auth_mode`, and carries
the acting admin (`user_id`), the org, and the link id where applicable.

| `event_type` | When |
|---|---|
| `siona_provision_requested` | Every provision attempt (recorded first). |
| `siona_already_linked` | Org already had a workspace + active link (no-op). |
| `siona_provision_failed` | Unconfigured, or SIONA returned an error. `reason` carries the cause. |
| `siona_module_link_created` | A new SIONA link was created. |
| `siona_module_link_updated` | An existing SIONA link was repaired/updated. |
| `siona_provision_succeeded` | Workspace provisioned and linked. |

`metadata` carries only safe fields (`workspace_id`, `http_status`). The
`SIONA_API_TOKEN` is never read by the service and never written to the audit.

---

## Healthcheck

`php artisan glassportal:healthcheck` gains three Phase 20 checks (section 7l).
All are **warn-only** — unconfigured provisioning never fails the healthcheck.

| Check | Pass condition |
|---|---|
| `siona.tenant_provisioning_config` | `SIONA_PROVISIONING_ENABLED` true, path set, and back-channel ready. |
| `siona.workspace_mapping_column` | `organizations.siona_workspace_id` column present. |
| `siona.backchannel_ready` | Server-to-server channel ready (`SIONA_API_URL` + `SIONA_API_TOKEN` present). Reports presence only — never the token. |

---

## Security Invariants

1. `SIONA_API_TOKEN` is read only by `SionaConnectorClient` for the outbound
   Bearer header — never returned, logged, viewed, or written to the audit.
2. The provisioning service never handles the token directly.
3. `organizations.siona_workspace_id` and link `metadata` store only the opaque
   workspace id — no secrets, credentials, or PII.
4. The provision action is owner/admin only (stacked role middleware) and
   CSRF-protected like all portal writes.
5. Provisioning is **idempotent** — repeated calls never create duplicate links
   or workspaces and make no redundant outbound call once linked.
6. Healthcheck back-channel readiness reports presence of credentials only,
   never their values.

---

## Testing

| Suite | File | Coverage |
|---|---|---|
| Feature | `tests/Feature/SionaTenantProvisioningTest.php` | admin success, customer/staff forbidden, auth required, idempotency, missing config, link create/update, no token leakage, view surface, healthcheck. |
| Unit (service) | `tests/Unit/Siona/SionaTenantProvisioningServiceTest.php` | provisioned/already_linked/unconfigured/failed outcomes, workspace reuse, audit events, no token in audit. |
| Unit (client) | `tests/Unit/Siona/SionaConnectorClientTest.php` | `provisionTenant` success/nested-id/degraded/error/never-throws/no-leak, config helpers. |

Run: `php artisan test`

---

## What Is NOT Done Yet

| Item | Notes |
|---|---|
| SIONA per-module signing key | `GLASSPORTAL_MODULE_SECRET_SIONA` is scaffolded in `.env.example` but not yet wired into `per_module_secrets` — provisioned `signed_launch` links currently use the global secret. |
| Workspace lifecycle (suspend/delete/transfer) | Phase 21+ — only create is implemented. |
| Inbound SIONA webhooks | Phase 21+ — GlassPortal does not yet receive events from SIONA. |
| De-provisioning / unlink action | Use `/admin/module-links` to disable the link; no workspace teardown call yet. |
| OAuth 2.0 / OIDC with SIONA | Out of scope — different trust model. |
