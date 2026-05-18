# Phase 12: SSO Trust Hardening

## Overview

Phase 12 adds two orthogonal hardening layers on top of the Phase 11 back-channel SSO exchange:

| Feature | What it adds | Config key |
|---|---|---|
| Per-module signing secrets | Each module can use a dedicated HMAC key, limiting blast radius if a secret is compromised | `glasshouse_sso.per_module_secrets` |
| mTLS enforcement | Back-channel redeem endpoint can require client-certificate verification forwarded by the reverse proxy | `glasshouse_sso.backchannel.require_mtls` |
| `failureWithContext()` | Post-consumption failures carry identity context for richer audit records | `BackChannelLaunchCodeResult::failureWithContext()` |
| Audit trail | `BackChannelRedeemController` writes `ModuleLaunchEvent` rows for all auditable redemption outcomes | `backchannel_redeem_success`, `backchannel_replay_blocked`, `backchannel_redeem_failed` |

---

## Per-Module Secret Strategy

### Configuration path

```php
// config/glasshouse_sso.php
'per_module_secrets' => [
    'glasspanel' => env('GLASSPORTAL_MODULE_SECRET_GLASSPANEL', ''),
    'aria'       => env('GLASSPORTAL_MODULE_SECRET_ARIA', ''),
],
```

### Environment variable names

```
GLASSPORTAL_MODULE_SECRET_GLASSPANEL=<base64-64-bytes>
GLASSPORTAL_MODULE_SECRET_ARIA=<base64-64-bytes>
```

Generate a strong secret: `openssl rand -base64 64`

### Priority order

**Issuance** (`SignedLaunchTokenService::generate()`):

```
per_module_secrets[moduleKey]  →  signing_secret (global fallback)
```

**Verification** (`SignedLaunchTokenService::verify()`):

```
per_module_secrets[audience]  →  keys[kid]  →  signing_secret (global fallback)
```

The resolver is implemented in `App\Services\Sso\ModuleSecretResolver`.

### Production guidance

- **Recommended**: set a per-module secret for every module that uses `auth_mode=signed_launch`.
- **Why**: if the global `signing_secret` is compromised, an attacker can forge tokens for all modules. Per-module secrets limit the blast radius to one module at a time.
- **Rotation**: update the per-module secret (zero-downtime if you add the new secret to `keys[]` first, then cut over `per_module_secrets`).
- The health check (`php artisan glassportal:healthcheck`) will warn in production when modules use the global fallback.

---

## mTLS Reverse Proxy Deployment

### Assumptions

The back-channel redeem endpoint (`POST /api/sso/backchannel/redeem/{moduleKey}`) is called server-to-server by each module. In production, the reverse proxy should:

1. Require a valid client TLS certificate from the module.
2. Verify the certificate against a trusted CA.
3. Forward the verification result in a header (default: `X-Client-Cert-Verified: SUCCESS`).

GlassPortal trusts this header — it does not perform its own TLS termination. Therefore **the back-channel endpoint must never be directly reachable by untrusted clients** (only via the reverse proxy).

### Nginx example

```nginx
server {
    listen 443 ssl;
    ssl_client_certificate /etc/ssl/module-ca.crt;
    ssl_verify_client       on;

    location /api/sso/backchannel/ {
        proxy_set_header X-Client-Cert-Verified $ssl_client_verify;
        proxy_pass http://glassportal_backend;
    }
}
```

`$ssl_client_verify` is `SUCCESS` when the certificate is valid, which matches the default `GLASSPORTAL_BACKCHANNEL_MTLS_VERIFIED_VALUE`.

### Caddy example

```caddy
reverse_proxy /api/sso/backchannel/* glassportal:8000 {
    header_up X-Client-Cert-Verified {tls_client_certificate_status}
}
```

### Trusted proxies note

Set `TRUSTED_PROXIES` (or configure `App\Http\Middleware\TrustProxies`) to only trust the addresses of your reverse proxy. This prevents external clients from spoofing the `X-Client-Cert-Verified` header.

---

## Failure Reason Contract

Complete table of `BackChannelLaunchCodeResult::$reason` values returned by the redeem flow:

| Reason | HTTP status | Audited? | Audit event type |
|---|---|---|---|
| `ok` | 200 | Yes | `backchannel_redeem_success` |
| `backchannel_disabled` | 401 | Yes | `backchannel_redeem_failed` |
| `missing_code` | 401 | No — format error | — |
| `malformed_code` | 401 | No — format error | — |
| `code_replayed` | 401 | Yes | `backchannel_replay_blocked` |
| `code_not_found` | 401 | No — format error | — |
| `wrong_module` | 403 | Yes | `backchannel_redeem_failed` |
| `inactive_module_link` | 403 | Yes | `backchannel_redeem_failed` |
| `organization_mismatch` | 403 | Yes | `backchannel_redeem_failed` |
| `user_not_found` | 401 | Yes | `backchannel_redeem_failed` |
| `mtls_required` | 401 | No — middleware rejects before controller | — |

Format errors (`missing_code`, `malformed_code`, `code_not_found`) are not audited to prevent log flooding and to avoid creating timing oracles.

---

## Audit Trail Behavior

### What is recorded

Each `ModuleLaunchEvent` row for back-channel events contains:

| Field | Value |
|---|---|
| `auth_mode` | `'backchannel_launch'` |
| `event_type` | One of: `backchannel_redeem_success`, `backchannel_replay_blocked`, `backchannel_redeem_failed` |
| `reason` | `null` on success; the failure reason string otherwise |
| `organization_id` | From `$result->orgId` (populated via `failureWithContext()` on post-consumption failures) |
| `user_id` | From `$result->userId` |
| `module_link_id` | From `$result->moduleLinkId` |
| `module_key` | The `{moduleKey}` route parameter |
| `ip_address` | `$request->ip()` |
| `user_agent` | `$request->userAgent()` |
| `metadata` | On success: `{'expires_at': <unix>}`; on failure: `null` |

### What is never stored

- Raw launch code
- Signing secrets (global or per-module)
- User email
- User name
- Token bytes

This is enforced both in the controller (`recordAudit()` does not include `$result->email` or `$result->name`) and tested in `BackChannelRedeemHardeningTest`.

---

## Phase 13 Recommendations

1. **Key-version pinning per module link**: store the `kid` used at issuance in `organization_module_links` to enable audit-time secret reconstruction.
2. **Module certificate pinning**: store the expected client certificate fingerprint per module link and verify it against the forwarded cert header for stronger module identity.
3. **Audit retention policy**: add a scheduled job to archive or purge `module_launch_events` older than a configurable retention window.
4. **Secret rotation helper command**: `php artisan sso:rotate-module-secret {moduleKey}` to automate the zero-downtime key rotation workflow.
5. **JWKS endpoint**: expose a `GET /.well-known/jwks.json` endpoint so modules can fetch current public keys automatically during rotation, removing the need for manual key synchronization.
