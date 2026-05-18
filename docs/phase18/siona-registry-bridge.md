# Phase 18 — SIONA Registry Bridge

## Overview

SIONA (Sales Intelligence & Outreach Navigation Assistant) is registered as a first-class external module in GlassPortal. SIONA's source code remains in its own repository. GlassPortal owns the registry metadata, connector health check, launch visibility, and config — nothing more.

---

## Integration boundary

```
GlassPortal                         SIONA (external repo)
    │                                       │
    ├── registry / config ──────────────────►
    ├── connector health probe ─────────────►
    │   GET {SIONA_API_URL}/api/health       │
    │◄── {status, ...} ─────────────────────┤
    │                                       │
    ├── module link (per-org) ───────────────►
    │   OrganizationModuleLink               │
    │   module_key = "siona"                │
    │                                       │
    ├── signed launch ───────────────────────►
    │   HMAC-SHA256 token (existing system)  │
    │   SLP → module handles session         │
    │                                       │
    └── back-channel launch ────────────────►
        one-time code → server redeem        │
```

GlassPortal does NOT:
- Store SIONA user data or workspace state
- Import SIONA application classes
- Expose `SIONA_API_TOKEN` in any response, log, view, or exception

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `SIONA_ENABLED` | `false` | Master switch — enables health probing when `true` |
| `SIONA_API_URL` | `""` | Base URL of the SIONA service (no trailing slash) |
| `SIONA_API_TOKEN` | `""` | Bearer token for SIONA API requests — server-side only |
| `SIONA_LAUNCH_URL` | `""` | Customer-facing UI URL (used as `external_url` on module links) |
| `SIONA_TIMEOUT` | `5` | HTTP request timeout in seconds |
| `SIONA_VERIFY_TLS` | `true` | Set `false` only in local dev |

### Example `.env` (development)

```
SIONA_ENABLED=true
SIONA_API_URL=http://siona.internal.test
SIONA_API_TOKEN=siona-dev-token
SIONA_LAUNCH_URL=http://siona.internal.test/app
SIONA_TIMEOUT=5
SIONA_VERIFY_TLS=false
```

---

## Connector health flow

```
GET /api/connectors/siona/health
```

Always returns HTTP 200. The `status` field carries health state:

| `status` | Meaning |
|---|---|
| `unconfigured` | `SIONA_ENABLED=false` or `SIONA_API_URL` is empty |
| `ok` | Probe to `{SIONA_API_URL}/api/health` returned 2xx |
| `degraded` | Probe returned non-2xx HTTP response |
| `error` | Connection failure or unexpected exception |

**Response shape:**
```json
{
  "connector": "siona",
  "status": "ok",
  "configured": true,
  "latency_ms": 42,
  "message": "SIONA responded successfully."
}
```

`SIONA_API_TOKEN` is sent as a `Bearer` header on the probe request. It is **never** included in the response.

---

## Module registry

SIONA is present in both config registries:

### `config/glasshouse.php` → `modules.siona`

System-level connector entry for admin display and health routing.

```
key:      siona
display:  SIONA
full:     Sales Intelligence & Outreach Navigation Assistant
category: ai_sales
health:   /api/connectors/siona/health (GlassPortal endpoint)
```

### `config/glasshouse.php` → `launch_modules.siona`

Customer-facing module registry entry. Drives the portal module launchpad.

---

## Launch flow

SIONA can be launched via any auth mode supported by `OrganizationModuleLink.auth_mode`:

| Mode | Use case |
|---|---|
| `standalone` | External URL redirect (audited) |
| `signed_launch` | HMAC-signed SLP token, no shared session |
| `backchannel_launch` | One-time code, server-to-server identity exchange |
| `shared_session` / `oauth` | Stub — Phase 19+ |

### Creating a SIONA module link

```php
OrganizationModuleLink::create([
    'organization_id'     => $org->id,
    'module_key'          => 'siona',
    'display_name'        => 'SIONA',
    'auth_mode'           => 'signed_launch',
    'external_url'        => config('siona.launch_url'),
    'external_account_id' => $orgSionaWorkspaceId,  // optional
    'status'              => 'active',
]);
```

No per-org SIONA credentials are stored in GlassPortal. The module link only records the relationship, auth mode, and optional workspace identifier.

---

## Portal module visibility

SIONA appears on the customer module launchpad (`/portal/modules`) **only when an `OrganizationModuleLink` exists for the user's organization with `module_key = 'siona'`**. Unlinked orgs see the card as "not linked".

Incomplete setup (missing `external_url` or signing secret) shows "Setup required — contact support."

Token values (`SIONA_API_TOKEN`) are never rendered in any portal view.

---

## Admin modules view

SIONA appears in both tables at `/admin/modules`:

- **Connector Registry**: shows display name, status (`disabled` by default), notes with setup hint
- **Customer Launch Registry**: shows module key, display name, linked org count, description

The `token` field from config is never rendered in any admin view.

---

## Healthcheck (`php artisan glassportal:healthcheck`)

Three SIONA checks are included:

| Check | Pass condition | Warn condition |
|---|---|---|
| `siona.module_registry` | Present in both registries | Missing from either registry |
| `siona.config` | Enabled + API URL set | Disabled or URL missing |
| `siona.connector_route` | Route registered | Route not found |

All three are **warn-only** — an unconfigured SIONA never causes the healthcheck to exit non-zero.

---

## Security invariants

1. `SIONA_API_TOKEN` is read only by `SionaHealthController` for the probe request — never returned
2. `SIONA_API_TOKEN` does not appear in logs, views, or exceptions
3. The admin modules view never renders the `token` field from config
4. The portal modules view never renders the `token` field from config
5. Raw HTTP client exceptions are caught and sanitised before reaching the browser
6. The health endpoint always returns HTTP 200, preventing false-alarm alerting on unconfigured state

---

## What is NOT done yet

| Item | Notes |
|---|---|
| Tenant/workspace provisioning handshake | Phase 19 — GlassPortal initiates SIONA workspace creation on org provisioning |
| Per-org SIONA workspace metadata | Phase 19 — store workspace ID on org record, not on module link |
| SIONA-specific signed launch secret | Currently uses global or key_registry secret; Phase 19 adds per-module secret support |
| OAuth 2.0 / OIDC with SIONA | Explicitly out of scope — different trust model |
| SIONA webhook events | Not yet wired — Phase 20+ |

---

## Phase 19 recommendation: tenant provisioning handshake

When a new organization is provisioned in GlassPortal and SIONA is enabled, GlassPortal should:

1. POST to `{SIONA_API_URL}/api/tenants` with org metadata (name, ID) using `SIONA_API_TOKEN`
2. Receive a `workspace_id` in the response
3. Store the workspace ID on the `organizations` record (new `siona_workspace_id` column)
4. Auto-create an `OrganizationModuleLink` with `module_key=siona`, `auth_mode=signed_launch`, `external_account_id=workspace_id`, and `status=active`
5. Record a provisioning audit event

This removes the manual step of creating the module link and provides a foundation for SIONA workspace lifecycle events (suspension, deletion).
