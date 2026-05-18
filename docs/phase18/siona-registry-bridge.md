# Phase 18 — SIONA Registry Bridge

## Overview

SIONA (Sales Intelligence & Outreach Navigation Assistant) is registered in GlassPortal as a first-class external module. SIONA's source code lives entirely in its own repository. GlassPortal owns only:

- Module registry metadata
- Connector health check endpoint
- Customer-facing launchpad entry
- Per-org module link support
- Configuration and environment scaffolding

GlassPortal does **not** contain SIONA application code, TypeScript, or any SIONA business logic.

---

## Integration boundary

```
GlassPortal                               SIONA (external repo/service)
    │                                           │
    ├── registry / config ────────────────────►
    │                                           │
    ├── connector health probe ──────────────►
    │   GET {SIONA_API_URL}/api/health           │
    │◄── { status } ────────────────────────┤
    │                                           │
    ├── per-org module link (in DB) ─────────►
    │   OrganizationModuleLink                  │
    │   module_key = "siona"                   │
    │                                           │
    ├── signed launch ─────────────────────►
    │   HMAC-SHA256 SLP token                   │
    │                                           │
    └── back-channel launch ────────────────►
        one-time code, server-to-server          │
```

GlassPortal does NOT:
- Store SIONA user/workspace/session data
- Import SIONA application classes or TypeScript
- Expose `SIONA_API_TOKEN` in any HTTP response, log, view, test output, or exception

---

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `SIONA_ENABLED` | `false` | Master switch. `false` → health returns `unconfigured`, HTTP 200 |
| `SIONA_API_URL` | `""` | Base URL of the SIONA service (no trailing slash) |
| `SIONA_API_TOKEN` | `""` | Bearer token for API requests. **Server-side only. Never in responses.** |
| `SIONA_LAUNCH_URL` | `""` | Customer-facing UI URL. Used as `external_url` on module links |
| `SIONA_TIMEOUT` | `5` | HTTP timeout in seconds for health probes |
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

## Connector health endpoint

```
GET /api/connectors/siona/health
```

Always returns HTTP 200. Check the `status` field:

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

`SIONA_API_TOKEN` is sent as `Authorization: Bearer <token>` on probe requests. It is **never** returned in the response.

---

## Module registry

### `config/glasshouse.php` → `modules.siona`

System-level connector entry shown in the admin module registry.

```php
'siona' => [
    'enabled'      => (bool) env('SIONA_ENABLED', false),
    'display_name' => 'SIONA',
    'full_name'    => 'Sales Intelligence & Outreach Navigation Assistant',
    'category'     => 'ai_sales',
    'base_url'     => env('SIONA_API_URL', ''),
    'token'        => env('SIONA_API_TOKEN', ''),  // never rendered in views
    ...
]
```

### `config/glasshouse.php` → `launch_modules.siona`

Customer-facing launchpad entry. Drives `/portal/modules` display.
Credentials are **not** present here — display metadata only.

```php
'siona' => [
    'display_name' => 'SIONA',
    'description'  => 'AI-assisted sales intelligence, ICP validation, ...',
    'icon'         => '◆',
]
```

---

## Launch flow

SIONA supports the same auth modes as all GlassPortal external modules:

| Mode | Use case |
|---|---|
| `standalone` | Plain external URL redirect (audited via `ModuleLaunchService`) |
| `signed_launch` | HMAC-SHA256 SLP token, browser-posted to SIONA |
| `backchannel_launch` | One-time code, SIONA calls back to redeem server-to-server |
| `shared_session` / `oauth` | Stub — Phase 19+ |

### Creating a SIONA module link (admin/provisioning)

```php
OrganizationModuleLink::create([
    'organization_id'     => $org->id,
    'module_key'          => 'siona',
    'display_name'        => 'SIONA',
    'auth_mode'           => 'signed_launch',
    'external_url'        => config('siona.launch_url'),
    'external_account_id' => $sionaWorkspaceId,  // optional
    'status'              => 'active',
]);
```

No per-org SIONA credentials are stored on the module link. The link holds only the relationship, auth mode, and optional workspace identifier.

---

## Admin module visibility

SIONA appears automatically in `/admin/modules` because the admin controller reads `config('glasshouse.modules')` dynamically.

- **Connector Registry table**: shows `SIONA`, status (`disabled` by default), notes with setup hint
- **Customer Launch Registry table**: shows `siona` key, display name, linked org count

The `token` field from config is never rendered in any admin view.

---

## Customer portal visibility

SIONA appears on `/portal/modules` for a customer **only when** an `OrganizationModuleLink` with `module_key=siona` exists for their organization. Unlinked orgs see the card as “not linked.”

Incomplete setup (missing `external_url` or signing secret) shows “Setup required — contact support.” No token values are rendered in the portal view.

---

## Healthcheck (`php artisan glassportal:healthcheck`)

Three SIONA checks run automatically:

| Check key | Condition |
|---|---|
| `siona.module_registry` | SIONA present in both `modules` and `launch_modules` registries |
| `siona.config` | `SIONA_ENABLED=true` and `SIONA_API_URL` is set |
| `siona.connector_route` | Named route `api.connectors.siona.health` is registered |

All three are **warn-only**. An unconfigured SIONA never causes the healthcheck to exit non-zero.

---

## Security invariants

1. `SIONA_API_TOKEN` is read only by `SionaHealthController` for probe auth — never returned
2. Admin views render `base_url` and `notes` only; `token` field is never output
3. Portal views never reference config token fields
4. HTTP client exceptions are caught and sanitised before any browser response
5. Health endpoint always returns HTTP 200 (no 5xx for unconfigured state)
6. Tests assert the absence of token values from all response bodies

---

## Token leakage grep

To verify no token leakage in source:
```bash
grep -rn "SIONA_API_TOKEN" app resources routes tests docs \
  | grep -v config/siona.php \
  | grep -v .env.example
```

Expected matches: only doc comments and config references — never raw values.

---

## What is NOT done yet

| Item | Notes |
|---|---|
| Tenant/workspace provisioning handshake | Phase 19 — GlassPortal calls SIONA on org provisioning |
| Per-org SIONA workspace metadata column | Phase 19 — `siona_workspace_id` on `organizations` |
| Auto-create module link on provisioning | Phase 19 |
| SIONA-specific per-module signing secret | Use `GLASSPORTAL_MODULE_SECRET_SIONA` via Phase 12 mechanism |
| OAuth 2.0 / OIDC | Out of scope — different trust model |
| SIONA webhook events | Phase 20+ |

---

## Phase 19 recommendation: tenant provisioning handshake

When a new organization is provisioned in GlassPortal and SIONA is enabled:

1. POST to `{SIONA_API_URL}/api/tenants` with org metadata using `SIONA_API_TOKEN`
2. Receive a `workspace_id` in the response
3. Store the workspace ID on the `organizations` record (`siona_workspace_id` column)
4. Auto-create `OrganizationModuleLink` with `module_key=siona`, `auth_mode=signed_launch`, `external_account_id=workspace_id`, `status=active`
5. Record provisioning audit event

This removes the manual link-creation step and provides a foundation for workspace lifecycle events (suspension, deletion).
