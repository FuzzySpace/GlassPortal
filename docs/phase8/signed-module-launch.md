# Phase 8: Signed Module Launch Foundation

## Why signed launch exists

GlassPortal manages access to multiple ecosystem modules (GlassPanel, Aria, DNS, Mail, etc.). Without a trust mechanism, each module must either manage its own user database or share session cookies — both operationally fragile.

**Signed launch** allows GlassPortal to assert a user's identity to a module via a cryptographically signed payload. The module verifies the signature and trusts the identity without requiring a callback to GlassPortal. It is a delegation pattern, not a full SSO flow — Phase 9 adds back-channel exchange for higher-security modules.

---

## Auth mode reference

| Mode | Phase | Behavior |
|---|---|---|
| `local` | Operational | User already has credentials in the module — link is informational |
| `standalone` | Operational | Module has its own login; GlassPortal issues a direct launch URL |
| `api_token` | Operational | Service-level API token (server-side only, never browser) |
| `signed_launch` | **Operational (Phase 8)** | GlassPortal signs a time-limited identity token and POSTs it to the module |
| `shared_session` | Stub (Phase 9+) | Shared cookie/JWT domain SSO — not yet implemented |
| `oauth` | Stub (Phase 9+) | OAuth 2.0 / OIDC authorization code flow — not yet implemented |

---

## Signed payload schema (SLP — Signed Launch Payload)

Token format: `base64url(header).base64url(payload).base64url(signature)`

```
Header: {"alg":"HS256","typ":"SLP"}

Payload claims:
  iss   string   Issuer — portal identifier (GLASSPORTAL_SSO_ISSUER)
  aud   string   Audience — module_key (e.g. "glasspanel")
  sub   string   Subject — portal user ID
  org   string   Organization ID
  mid   string   Module link ID (for audit correlation)
  email string   User email address
  name  string   User display name
  role  string   Portal role (customer, staff, admin, …)
  iat   int      Issued-at Unix timestamp
  exp   int      Expiry Unix timestamp (iat + TTL, default 60s, max 300s)
  nonce string   Random 24-hex-char nonce
  jti   string   Unique token ID (32-hex-char), used for replay detection
```

No secrets, passwords, API tokens, or signing keys appear in any claim.

---

## Token generation (GlassPortal side)

```
secret ← env('GLASSPORTAL_SIGNED_LAUNCH_SECRET')    ← server-side only
header ← base64url({"alg":"HS256","typ":"SLP"})
payload ← base64url(JSON(claims))
sigInput ← header + "." + payload
signature ← base64url(HMAC-SHA256(sigInput, secret))
token ← sigInput + "." + signature
```

The token is then POST-submitted to `external_url` as `slt=<token>`.

---

## Verification pseudocode (module side)

```
parts ← token.split(".")
assert len(parts) == 3

header, payload_b64, sig = parts

# Verify signature
expected_sig = base64url(HMAC-SHA256(header + "." + payload_b64, shared_secret))
assert constant_time_equals(expected_sig, sig)

# Decode payload
payload = JSON.decode(base64url_decode(payload_b64))

# Validate claims
assert payload.iss == PORTAL_ISSUER
assert payload.aud == THIS_MODULE_KEY
assert now() <= payload.exp + CLOCK_SKEW
assert all required claims present

# Replay protection (module side)
assert jti not in used_jti_store
store jti with TTL = payload.exp - now() + buffer
```

---

## Replay protection design

### GlassPortal side (Phase 8)
- On `generate()`: stores `signed-launch:issued:{jti}` in Laravel cache with TTL = `nonce_cache_ttl_seconds` (600s).
- On `verify()` (test/integration use): checks JTI exists in cache, then consumes it (delete). Second call fails.
- If cache is unavailable: degraded mode — replay detection skipped, token otherwise valid.

### Module side (Phase 9 recommendation)
Each module should maintain its own JTI store (Redis/DB set). On first use: accept and record. On second use: reject as replay. TTL = token expiry + clock skew + buffer.

### Window analysis
- Default TTL: 60s — a stolen token is valid for at most 60s.
- Clock skew tolerance: 30s — safe for NTP-synchronized hosts.
- Replay window before consumption: 60s (TTL) — short enough to limit damage from token theft in transit.

---

## POST handoff flow

```
1. Customer clicks "Secure launch →" on /portal/modules
2. Browser → GET /portal/modules/{link}/launch (authenticated, portal session)
3. GlassPortalHealthCheck verifies:
   - Link belongs to user's org (HTTP 403 if not)
   - Link is active (deny + audit if not)
   - Signing secret is configured (fail + audit if not)
4. SignedLaunchTokenService.generate() creates token
5. ModuleLaunchService records signed_launch_issued event (jti, expires_at only)
6. Controller renders portal.module-launch-handoff view
7. View contains a hidden POST form targeting external_url with slt=<token>
8. JavaScript auto-submits the form after 400ms
9. Module receives POST, verifies signature, establishes session
```

The token travels in the **POST body** — not in the URL, not in browser history, not in server access logs on the module side.

---

## Threat model

### Token theft (in transit)
**Risk**: An attacker intercepts the POST body and reuses the token.

**Mitigations**:
- Short TTL (60s default) limits replay window.
- Replay protection via JTI tracking on module side.
- HTTPS required for all module endpoints.
- Phase 9: back-channel exchange removes token from browser entirely.

### Token replay
**Risk**: A captured token is submitted a second time.

**Mitigations**:
- GlassPortal's `verify()` consumes JTI on first use.
- Module side should maintain its own JTI store (Phase 9 enforcement).
- Short TTL makes replays time-bounded.

### Cross-org launch
**Risk**: Customer A launches a module link belonging to Organization B.

**Mitigations**:
- HTTP-layer ownership check (403) before service is invoked.
- Service re-checks ownership (defense in depth).
- Token claims include `org` and `mid` — module can verify these match its configuration.
- No event is recorded for the 403 case (controller-layer block).

### Secrets in URL / logs
**Risk**: Signing secret or token appears in logs, audit records, or URLs.

**Mitigations**:
- `GLASSPORTAL_SIGNED_LAUNCH_SECRET` exists only in environment — never in config files or source.
- Token is POST-submitted (not in URL).
- Audit event metadata stores only `jti` and `expires_at` — never the token itself.
- View layer: `portal.module-launch-handoff` uses a hidden form, not visible data attributes.

### Prompt-injection / social-engineering risk
**Risk**: A malicious actor tricks an admin into setting `external_url` to a hostile endpoint.

**Mitigations**:
- `external_url` validation rejects non-URL values.
- Admin forms display explicit warnings: "Do not paste tokens or credentials in this field."
- The token contains identity data only — no capability grants beyond what the module interprets.

### Signing key compromise
**Risk**: `GLASSPORTAL_SIGNED_LAUNCH_SECRET` is leaked.

**Mitigations**:
- Rotate the secret immediately via environment update and portal restart.
- All previously issued tokens will fail verification after rotation.
- Healthcheck warns if secret is missing; alerts should be set for key-age SLOs.
- Phase 9: implement key versioning with `kid` header claim.

---

## Environment variables

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `GLASSPORTAL_SIGNED_LAUNCH_SECRET` | When any `signed_launch` link is active | — | HMAC-SHA256 signing key |
| `GLASSPORTAL_SSO_ISSUER` | No | `glassportal` | `iss` claim value |
| `GLASSPORTAL_SIGNED_LAUNCH_TTL` | No | `60` | Token TTL in seconds |

---

## Phase 9 recommendations

1. **Back-channel token exchange** — GlassPortal issues a one-time opaque code. The module calls back to `POST /api/sso/redeem` to exchange it for user identity. The signed token never reaches the browser. Mandatory for high-security modules.

2. **Module-side middleware package** — A Laravel/PHP package (`glasshouse/portal-auth`) that handles: SLP verification, JTI replay store, role mapping, session creation. Reduces per-module implementation burden.

3. **OAuth 2.0 / OIDC** — Full authorization-code flow with PKCE. GlassPortal acts as the authorization server. Enables federated login from third-party systems.

4. **Shared session cookie hardening** — Domain-bound `__Host-` prefixed cookies with `SameSite=Strict`. Requires all modules to share a domain (e.g., `*.glasshouse.io`).

5. **Token rate limiting** — Throttle `portal.module.launch` per-user per-link (e.g., 10 launches/minute) to limit token farming attacks.

6. **Device/session binding** — Include the portal session fingerprint in the token; modules reject tokens from mismatched devices.

7. **Key versioning** — Add `kid` (key ID) to the token header. Support a rolling key window (current + previous) to enable zero-downtime key rotation.

8. **Audit log viewer** — Admin UI for searching `module_launch_events` by org, user, module, event_type, and date range.
