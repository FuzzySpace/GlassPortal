# Phase 19 — SIONA Live Launch + Account Linking Bridge

## Overview

Phase 19 completes the GlassPortal-side bridge for the SIONA AI Sales module. Phase 18 established the registry, health endpoint, and launch visibility. Phase 19 adds:

- `SionaConnectorClient` — a proper service layer that encapsulates all SIONA communication
- Full launch support for all three auth modes: `standalone`, `signed_launch`, `backchannel_launch`
- Enhanced admin connector status panel with live health summary
- Enhanced portal module card with backchannel_launch support
- SIONA workspace mapping via `organization_module_links.metadata`
- Four new Phase 19 healthcheck checks
- Expanded test coverage (30 unit + 30 feature tests for Phase 19)

SIONA source code remains in its own repository. GlassPortal owns registry metadata, connector health, launch orchestration, and audit trail only.

---

## Architecture

```
Customer browser
    │
    ├── GET /portal/modules
    │       ModulesController → ModuleLaunchService.mergeWithRegistry()
    │       → SIONA card: active | setup_required | not_linked
    │
    ├── GET /portal/modules/{link_id}/launch
    │       ModuleLaunchController → ModuleLaunchService.attemptLaunch()
    │       → standalone:          redirect to external_url (audited)
    │       → signed_launch:       SLP token → POST handoff form
    │       → backchannel_launch:  one-time code → POST handoff form
    │
Admin browser
    │
    ├── GET /admin/modules
    │       ModulesController → SionaConnectorClient.health()
    │       → SIONA status panel + supported auth modes
    │
    └── /admin/module-links (CRUD)
            OrganizationModuleLink (module_key=siona)
            metadata JSON → siona_workspace_id placeholder

External monitor
    │
    └── GET /api/connectors/siona/health
            SionaHealthController → SionaConnectorClient.health()
            → Always HTTP 200; status: unconfigured | ok | degraded | error
```

---

## Configuration Variables

| Variable | Default | Description |
|---|---|---|
| `SIONA_ENABLED` | `false` | Master switch — enables health probing and connector client when `true` |
| `SIONA_API_URL` | `""` | Base URL of the SIONA service (no trailing slash) |
| `SIONA_API_TOKEN` | `""` | Bearer token for SIONA API requests — **server-side only, never returned** |
| `SIONA_LAUNCH_URL` | `""` | Customer-facing UI URL (fallback for standalone launch links) |
| `SIONA_TIMEOUT` | `5` | HTTP request timeout in seconds |
| `SIONA_VERIFY_TLS` | `true` | Set `false` only in local dev |

Config file: `config/siona.php`

---

## SionaConnectorClient

**File:** `app/Services/Siona/SionaConnectorClient.php`

Registered as a singleton in `AppServiceProvider`.

### Methods

#### `health(): array`

Probes SIONA health. Never throws. Returns:

```php
[
    'ok'         => bool,
    'status'     => 'unconfigured'|'ok'|'degraded'|'error',
    'configured' => bool,
    'latency_ms' => int|null,
    'message'    => string,
    'data'       => array,   // safe, no credentials
]
```

#### `launchMetadata(): array`

Returns safe display metadata. Never includes credentials.

```php
[
    'configured'           => bool,
    'launch_url'           => string,
    'display_name'         => 'SIONA',
    'supported_auth_modes' => ['standalone', 'signed_launch', 'backchannel_launch'],
]
```

#### `isConfigured(): bool`

True when `SIONA_ENABLED=true` and `SIONA_API_URL` is non-empty.

### Security Invariants

- `SIONA_API_TOKEN` is read only to set the `Authorization: Bearer` header on outbound probe requests — never returned in any result array
- Exception messages are sanitised: credential-bearing URL patterns (`https://user:pass@host`) are redacted before appearing in `message`
- The `data` field is always an empty array — no SIONA response body is forwarded to callers

---

## Module Link Setup

### Creating a SIONA module link

```php
OrganizationModuleLink::create([
    'organization_id'     => $org->id,
    'module_key'          => 'siona',
    'display_name'        => 'SIONA',
    'auth_mode'           => 'signed_launch',   // or standalone / backchannel_launch
    'external_url'        => config('siona.launch_url'),
    'external_account_id' => null,              // optional: SIONA workspace ID
    'status'              => 'active',
    'metadata'            => ['siona_workspace_id' => 'ws-abc123'],  // optional
]);
```

### Supported auth modes

| Mode | Use case | Prerequisites |
|---|---|---|
| `standalone` | External URL redirect (audited) | `external_url` set |
| `signed_launch` | HMAC-SHA256 SLP token handoff | `external_url` + `GLASSPORTAL_SIGNED_LAUNCH_SECRET` |
| `backchannel_launch` | One-time code, server-to-server redemption | `external_url` + `GLASSPORTAL_BACKCHANNEL_SSO_ENABLED=true` |

---

## Auth Modes in Detail

### `standalone`

The portal controller performs an audited redirect to `external_url`. No token is generated. SIONA must handle its own session. A `ModuleLaunchEvent` with `event_type=allowed` is recorded.

### `signed_launch`

1. Customer clicks "Secure launch" on the portal
2. `ModuleLaunchService.attemptLaunch()` calls `SignedLaunchTokenService.generate()`
3. SLP token (HMAC-SHA256 signed compact format) is issued
4. Portal renders a POST form with the token in a hidden field — never in a URL
5. Browser auto-submits the form to SIONA's handoff endpoint
6. SIONA verifies the token using the shared secret (via the PortalAuth SDK or equivalent)
7. A `ModuleLaunchEvent` with `event_type=signed_launch_issued` is recorded (JTI only, not the token)

Token format: `base64url(header).base64url(payload).base64url(HMAC-SHA256)`  
Token TTL: 60 seconds (default), max 300 seconds  
Replay protection: JTI tracked in cache; each token can only be consumed once

### `backchannel_launch`

1. Customer clicks "Secure launch" on the portal
2. `ModuleLaunchService.attemptLaunch()` calls `BackChannelLaunchService.issueCode()`
3. A 64-char hex one-time code is stored in cache (TTL: 60 seconds)
4. Portal renders a POST form with the code in a hidden field — never in a URL
5. Browser auto-submits the form to SIONA's redirect endpoint
6. SIONA's redirect handler POSTs the code to `POST /api/sso/backchannel/redeem/siona`
7. GlassPortal validates the code and returns identity data to SIONA
8. A `ModuleLaunchEvent` with `event_type=backchannel_code_issued` is recorded

---

## Customer Portal Behavior

SIONA appears on `/portal/modules` in these states:

| State | Trigger | Display |
|---|---|---|
| **Active — standalone** | `organization_module_link` with `auth_mode=standalone`, `external_url` set | "External launch →" button |
| **Active — signed_launch** | signed_launch link + signing secret configured | "Secure launch →" button |
| **Active — backchannel_launch** | backchannel link + backchannel SSO enabled | "Secure launch →" button |
| **Setup required** | Linked but missing URL or secret | "Setup required — contact support" |
| **Not linked** | No `organization_module_link` for this org | "Not linked to your account — contact support" |

Token values, signing secrets, and one-time codes are **never** rendered in portal HTML. They are transmitted only via POST form body to the module's handoff endpoint.

---

## Admin Modules View

`/admin/modules` shows:

1. **SIONA Connector status panel** (Phase 19) — live health result from `SionaConnectorClient.health()`, latency, and setup guidance when unconfigured
2. **Connector Registry table** — SIONA row shows auth modes `standalone / signed_launch / backchannel_launch`
3. **Customer Launch Registry table** — SIONA row shows `supported_auth_modes` from config
4. Link to `/api/connectors/siona/health` for monitoring

The admin view never renders `SIONA_API_TOKEN` values — only the environment variable name appears in setup guidance text.

---

## SIONA Account / Workspace Mapping

GlassPortal does not store SIONA CRM data, pipeline data, or workspace configuration. The lightweight mapping approach uses the existing `organization_module_links.metadata` JSON column:

```json
{
  "siona_workspace_id": "ws-abc123"
}
```

This is sufficient for Phase 19. The `external_account_id` field on `OrganizationModuleLink` can alternatively hold the workspace ID if it needs to be indexed.

No new migration is required — the `metadata` column is already present and JSON-decoded automatically by Eloquent's `$casts`.

---

## Healthcheck

`php artisan glassportal:healthcheck` includes seven SIONA checks total:

| Check | Section | Pass condition |
|---|---|---|
| `siona.module_registry` | 7j-i | Present in both registries |
| `siona.config` | 7j-ii | Enabled + URL set |
| `siona.connector_route` | 7j-iii | Named route registered |
| `siona.connector_client` | 7k-i | SionaConnectorClient resolvable |
| `siona.launch_registry` | 7k-ii | `supported_auth_modes` present in launch_modules |
| `siona.module_link_support` | 7k-iii | `metadata` column present on organization_module_links |
| `siona.health_probe` | 7k-iv | Live probe returns `ok` |

All seven are **warn-only** — unconfigured or unreachable SIONA never causes the healthcheck to exit non-zero.

---

## Security Invariants

1. `SIONA_API_TOKEN` is read only by `SionaConnectorClient` for the probe request — never returned
2. `SIONA_API_TOKEN` does not appear in logs, views, responses, or exceptions
3. Signed launch tokens are transmitted via POST form body only — never in URLs, headers, or logs
4. Back-channel one-time codes are transmitted via POST form body only — never in URLs, headers, or logs
5. Raw HTTP exceptions are caught and sanitised; credential-bearing URL patterns are stripped
6. The health endpoint always returns HTTP 200 — prevents false-alarm alerting on unconfigured state
7. `organization_module_links.metadata` stores only workspace ID references — no secrets, credentials, or PII
8. All launch attempts (allowed, denied, stubbed, rate_limited) are recorded in `module_launch_events`

---

## What Is NOT Done Yet

| Item | Notes |
|---|---|
| Tenant/workspace provisioning handshake | Phase 20 — GlassPortal initiates SIONA workspace creation on org provisioning |
| Per-org `siona_workspace_id` on organizations table | Phase 20 — dedicated column rather than metadata JSON |
| SIONA-specific signing key | Phase 20 — per-module key_registry entry (`GLASSPORTAL_MODULE_SECRET_SIONA`) for key isolation |
| SIONA webhook events | Phase 21+ — inbound events from SIONA to GlassPortal |
| OAuth 2.0 / OIDC with SIONA | Explicitly out of scope — different trust model |
| Admin "Create SIONA link" shortcut | Use existing `/admin/module-links/create` with `module_key=siona` |

---

## Phase 20 Recommendation: Tenant Provisioning Handshake

When a new organization is provisioned and SIONA is enabled, GlassPortal should:

1. POST to `{SIONA_API_URL}/api/tenants` with org metadata using `SIONA_API_TOKEN`
2. Receive `workspace_id` in the response
3. Store `workspace_id` on the `organizations` record (new `siona_workspace_id` column)
4. Auto-create an `OrganizationModuleLink` with `module_key=siona`, `auth_mode=signed_launch`, `external_account_id=workspace_id`, `status=active`
5. Record a provisioning audit event in `module_launch_events`

This removes the manual link-creation step and provides a foundation for SIONA workspace lifecycle events (suspension, deletion, workspace transfer).

Additionally Phase 20 should add a per-module signing key for SIONA:

```
GLASSPORTAL_MODULE_SECRET_SIONA=<unique-secret>
```

This isolates SIONA's token signing from the global secret, preventing cross-module token reuse.
