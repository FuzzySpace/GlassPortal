# Phase 9: Module-Side SSO Verification Integration Contract

## Overview

This document describes how downstream modules (GlassPanel, Aria, DNS, Mail, etc.) should verify and consume Signed Launch Payloads (SLP) issued by GlassPortal.

A signed launch occurs when a customer clicks "Secure launch →" in GlassPortal:

1. GlassPortal generates a short-lived HMAC-SHA256 SLP token.
2. The browser auto-submits a hidden POST form to the module's `external_url` with the token in `slt`.
3. The module verifies the token and establishes a session for the identified user.

---

## Token format

```
base64url(header) . base64url(payload) . base64url(signature)
```

### Header

```json
{"alg":"HS256","typ":"SLP"}
```

When key rotation is active (Phase 9+), the header also includes:

```json
{"alg":"HS256","typ":"SLP","kid":"v1"}
```

### Payload claims

| Claim   | Type   | Description                                      |
|---------|--------|--------------------------------------------------|
| `iss`   | string | Portal issuer (`GLASSPORTAL_SSO_ISSUER`)         |
| `aud`   | string | Module key (e.g. `glasspanel`, `dns`, `mail`)    |
| `sub`   | string | Portal user ID                                   |
| `org`   | string | Organization ID                                  |
| `mid`   | string | Module link ID (for audit correlation)           |
| `email` | string | User email address                               |
| `name`  | string | User display name                                |
| `role`  | string | Portal role (`customer`, `staff`, `admin`, …)    |
| `iat`   | int    | Issued-at Unix timestamp                         |
| `exp`   | int    | Expiry Unix timestamp                            |
| `nonce` | string | 24-hex-char random nonce                         |
| `jti`   | string | 32-hex-char unique token ID (replay detection)   |

No secrets, passwords, signing keys, or session cookies appear in any claim.

---

## Verification steps

### 1. Extract and check token structure

```
parts = token.split(".")
assert len(parts) == 3
header_b64, payload_b64, sig_b64 = parts
```

### 2. Resolve signing secret

If the header contains a `kid` claim, look up the secret from your configured key map:

```
header = json_decode(base64url_decode(header_b64))
if header.kid:
    secret = key_map[header.kid]   # KeyError → reject with "unknown kid"
else:
    secret = shared_secret          # backward compat — single-secret mode
```

### 3. Verify signature

```
expected_sig = base64url(HMAC-SHA256(header_b64 + "." + payload_b64, secret))
assert constant_time_equals(expected_sig, sig_b64)   # reject: invalid signature
```

### 4. Decode and validate payload

```
payload = json_decode(base64url_decode(payload_b64))

assert payload.iss == PORTAL_ISSUER          # reject: wrong issuer
assert payload.aud == THIS_MODULE_KEY        # reject: wrong audience
assert now() <= payload.exp + CLOCK_SKEW     # reject: expired (recommend 30s skew)
assert all required claims present
```

### 5. Replay protection

```
if jti_store.contains(payload.jti):
    reject("replay detected")

jti_store.set(payload.jti, ttl = payload.exp - now() + 30s)
```

GlassPortal's own `verify()` method already consumes the JTI on the portal side. However, **modules must also maintain their own JTI store** — this provides defense-in-depth in case a token is captured and replayed before the portal can detect it.

Recommended store: Redis `SET NX EX` with TTL = `exp - now() + 30s`.

---

## Receiving the POST handoff

GlassPortal sends a hidden form POST to `external_url`:

```
POST https://your-module.example/sso/receive
Content-Type: application/x-www-form-urlencoded

slt=<signed_launch_token>
```

The token is in the **POST body** — never in the URL, so it does not appear in access logs or browser history.

### Recommended module endpoint

```
POST /sso/receive
→ verify SLP token
→ map portal user identity to local account (by email or sub)
→ establish module session
→ redirect to dashboard
```

---

## Using the built-in middleware (Laravel modules)

GlassPortal ships `VerifySignedModuleLaunch` middleware that can be used by downstream Laravel modules:

```php
// In your module's AppServiceProvider or RouteServiceProvider:
$middleware->alias(['verify.signed.launch' => \App\Http\Middleware\VerifySignedModuleLaunch::class]);

// In routes:
Route::post('/sso/receive', SsoReceiveController::class)
    ->middleware('verify.signed.launch:glasspanel');
```

On success, the verified context is available via:

```php
$context = $request->attributes->get('sso_context');
// $context is a VerifiedLaunchContext instance
$userId = $context->userId;
$email  = $context->email;
$role   = $context->role;
$orgId  = $context->orgId;
```

On failure, the middleware returns `401 JSON` — it does not redirect, so module access logs do not contain destination URLs.

---

## Dev/test consumer endpoint

In local and testing environments, GlassPortal registers:

```
POST /_dev/sso/consume/{moduleKey}
```

This simulates a downstream module receiving a signed launch. It verifies the token and returns the identity context as JSON. Use this to test the full handoff flow without a real module server.

```bash
# Example: test a glasspanel launch
curl -X POST http://localhost:8000/_dev/sso/consume/glasspanel \
  -d "slt=<token_from_launch_handoff_page>"
```

Response:

```json
{
  "verified": true,
  "context": {
    "iss": "glassportal",
    "aud": "glasspanel",
    "sub": "42",
    "org": "7",
    "mid": "3",
    "email": "alice@example.com",
    "name": "Alice Example",
    "role": "customer",
    "iat": 1716000000,
    "exp": 1716000060,
    "nonce": "a1b2c3d4e5f6a1b2c3d4e5f6",
    "jti":   "deadbeefdeadbeefdeadbeefdeadbeef"
  }
}
```

**This endpoint is not registered in production.**

---

## Key rotation (KID support)

### Issuing tokens with a kid

Set `GLASSPORTAL_SIGNED_LAUNCH_KEY_ID=v1` in `.env`. Tokens will include `"kid":"v1"` in the header.

### Verifying with a key map

During rotation:
1. Generate a new secret and assign it `GLASSPORTAL_SIGNED_LAUNCH_KEY_ID=v2`.
2. Keep the old key in the `keys` map so in-flight tokens from `v1` can still verify:

```php
// config/glasshouse_sso.php
'keys' => [
    'v1' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1', ''),
    'v2' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2', ''),
],
```

3. After the old key's max TTL (300s) has elapsed, remove `v1` from the map.

Modules receiving tokens must apply the same key map logic: check `header.kid`, resolve the secret, verify.

---

## Security notes

- Require HTTPS on all module endpoints that accept SLP tokens.
- Never log the `slt` parameter value — log the `jti` claim instead for audit correlation.
- Implement module-side JTI replay protection. GlassPortal's portal-side consumption does not substitute for this.
- The token contains only identity claims — no capability grants. Role-based authorization decisions are made by the module based on the `role` claim.
- If a signing key is compromised: rotate immediately by changing the environment variable and restarting. All previously issued tokens will fail verification after rotation.

---

## Phase 10 recommendations

1. **Back-channel exchange** — GlassPortal issues a one-time opaque code. The module calls back to `POST /api/sso/redeem` to exchange it for identity. The token never reaches the browser.
2. **Module-side middleware package** — Publish `VerifySignedModuleLaunch` as a standalone Composer package (`glasshouse/portal-auth`) so modules can install it rather than copy-pasting.
3. **OAuth 2.0 / OIDC** — Full authorization-code flow with PKCE. GlassPortal acts as the authorization server.
4. **Shared-session hardening** — `__Host-` prefixed `SameSite=Strict` cookies, requires all modules on a shared domain.
5. **Audit log viewer** — Admin UI for searching `module_launch_events` by org, user, module, event_type, and date range.
