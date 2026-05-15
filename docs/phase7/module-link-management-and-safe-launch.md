# Phase 7: Module Link Management + Safe Launch Flow

## Overview

Phase 7 makes module links **operationally manageable** and introduces the **audited launch flow**. Every launch attempt — regardless of outcome — is recorded in `module_launch_events`. SSO auth modes remain safe stubs with no token exchange.

---

## What is operational now

### Admin CRUD for module links

| Route | Action |
|---|---|
| `GET /admin/module-links` | List all links with filters |
| `GET /admin/module-links/create` | Create form |
| `POST /admin/module-links` | Store new link |
| `GET /admin/module-links/{link}/edit` | Edit form |
| `PATCH /admin/module-links/{link}` | Update link |
| `DELETE /admin/module-links/{link}` | Soft-delete (disable) |

Validation rejects:
- `module_key` values not present in `config('glasshouse.launch_modules')`
- `auth_mode` values not in the defined enum list
- Invalid URLs in `external_url`

Forms explicitly warn: **do not enter API tokens, passwords, or private keys**.

### Audited portal launch

| Route | Action |
|---|---|
| `GET /portal/modules/{moduleLink}/launch` | Attempt launch; record event; redirect or show stub |

Outcomes:
- **allowed** — redirect to `external_url` (only for safe auth modes with a configured URL)
- **denied** — redirect to `/portal/modules` with flash error (inactive link, missing URL, wrong org)
- **stubbed** — render `portal.module-launch-stub` view (SSO modes)

Every outcome creates a `ModuleLaunchEvent` row. The HTTP-layer org ownership check (403) fires before the service, so cross-org probes do not generate event rows.

### Audit log — `module_launch_events`

| Column | Purpose |
|---|---|
| `organization_id` | Nullable FK — preserved if org is deleted |
| `user_id` | Nullable FK — who attempted the launch |
| `module_link_id` | Nullable FK — which link was targeted |
| `module_key` | Denormalized — readable even if link is deleted |
| `auth_mode` | Denormalized — auth mode at time of attempt |
| `event_type` | `allowed` / `denied` / `stubbed` / `failed` |
| `reason` | Human-readable denial or stub reason |
| `ip_address` | Client IP (IPv6-safe, 45 char max) |
| `user_agent` | Browser UA string |
| `created_at` | Immutable timestamp only — no `updated_at` |

Events are never soft-deleted. FKs null-on-delete so event records survive parent deletion.

---

## What remains stubbed

### SSO auth modes

`shared_session`, `signed_launch`, and `oauth` links:
- Are accepted by the admin create/edit forms
- Are displayed on the portal modules page with "Setup required" and a "SSO Setup →" stub link
- Route through the audited launch endpoint
- Return a `stubbed` event and render `portal.module-launch-stub.blade.php`
- **Never** perform token exchange, session creation, or OAuth redirects

The stub page informs the customer that SSO is planned for a future release and that their attempt was logged.

---

## Security invariants (enforced in this phase)

1. `external_url` stored in `organization_module_links.external_url` is a plain URL — no credentials.
2. Admin create/edit forms validate `external_url` as a URL; all other fields are non-secret metadata.
3. `ModuleLaunchEvent.metadata` is JSON null by default — no credentials stored.
4. The service's `attemptLaunch()` re-checks org ownership even if the controller already checked (defense in depth).
5. No `external_url` is rendered directly in portal Blade — launch links go through the `/launch` route.
6. SSO modes never produce a `redirect_url` — only the stub page.

---

## Healthcheck additions

```
db.module_launch_events     module_launch_events table present
routes.module_launch        portal.module.launch route registered
config.launch_modules       All 7 expected launch module keys present
```

---

## Tests

| Suite | Count | Coverage |
|---|---|---|
| `Feature\AdminModuleLinkCrudTest` | 18 tests | CRUD, validation, role guards, soft delete |
| `Feature\PortalModuleLaunchTest` | 15 tests | Redirect, denied, stubbed, audit events, role guards |
| `Feature\HealthCheckPhase7Test` | 4 tests | New healthcheck assertions |

All 116 tests pass (Phase 4–7).

---

## What Phase 8 should do

1. **Implement `signed_launch`**: Generate a time-limited, HMAC-signed URL that the target module accepts. Secret lives in server config only.
2. **Implement `shared_session`**: Establish a shared session token across subdomain trust boundaries using a server-side cookie exchange.
3. **Implement `oauth`**: Full OAuth 2.0 / OIDC authorization code flow. Store client_id in config, never in the browser or this DB.
4. **Provision module accounts**: When a link is created with SSO mode, trigger provisioning in the external module.
5. **Audit log viewer**: Admin UI for browsing `module_launch_events` by org, user, module, date, or outcome.
6. **Rate limiting**: Apply throttle middleware to `portal.module.launch` to limit launch attempt frequency per user.
