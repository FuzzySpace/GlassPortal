# Phase 11 — Back-Channel SSO Launch Exchange

## Overview

Phase 11 replaces the browser-mediated signed token handoff (Phase 8–10) with a server-to-server code exchange. GlassPortal issues a short-lived one-time launch code that the module redeems directly — the signing secret never leaves GlassPortal, and the token never appears in a browser URL or server access log.

---

## What Phase 11 Adds

| Concern | Before Phase 11 | After Phase 11 |
|---|---|---|
| Auth mode | `signed_launch` (HMAC token in form POST) | + `backchannel_launch` (opaque code, server-to-server redeem) |
| Secret exposure | Module needs `GLASSPORTAL_SIGNED_LAUNCH_SECRET` | Module needs no secret — GlassPortal validates internally |
| Code format | 3-part JWT-like token | 64-char hex opaque code |
| Redemption | Browser verifies via middleware | Module calls `POST /api/sso/backchannel/redeem/{moduleKey}` |
| Replay protection | JTI cache (same as Phase 10) | Code tombstone in cache |
| Healthcheck | Phase 10 checks | + `sso.backchannel_service`, `sso.backchannel_cache`, `routes.backchannel_redeem`, `config.backchannel` |

---

## Exchange Flow

```
Browser  → GlassPortal  : GET /portal/modules/{link}/launch
GlassPortal → Cache     : store one-time code payload (60s TTL)
GlassPortal → Browser   : render handoff view (launch_code in POST form)

Browser  → Module       : POST {module_url}  {launch_code: <code>}
Module   → GlassPortal  : POST /api/sso/backchannel/redeem/{moduleKey}  {launch_code: <code>}
GlassPortal → Module    : {"ok":true, "user_id":..., "email":..., "role":...}
Module   → Browser      : establish session, redirect to dashboard
```

The `launch_code` travels **only** in POST bodies — never in URLs. Server access logs on the module side see the URL only, not the POST body.

---

## Required Environment Variables

```env
# On GlassPortal
GLASSPORTAL_BACKCHANNEL_SSO_ENABLED=true
GLASSPORTAL_BACKCHANNEL_CODE_TTL=60        # one-time code lifetime in seconds
GLASSPORTAL_BACKCHANNEL_REPLAY_TTL=600     # tombstone retention for replay detection
```

Modules receiving back-channel codes do **not** need any shared secret. They authenticate using their own API credentials when calling the redeem endpoint.

---

## Auth Mode: `backchannel_launch`

Add `backchannel_launch` as the `auth_mode` on an `organization_module_links` row. Set `external_url` to the module's redirect/receive endpoint that will accept the POST form submission.

---

## Redemption Endpoint

`POST /api/sso/backchannel/redeem/{moduleKey}`

Rate-limited: 30 requests/minute/IP.

### Request

```
Content-Type: application/x-www-form-urlencoded (or application/json)

launch_code=<64-char hex code>
```

### Success Response (200)

```json
{
  "ok": true,
  "module_key": "glasspanel",
  "user_id": "42",
  "org_id": "7",
  "email": "user@example.com",
  "name": "Alice Example",
  "role": "customer",
  "expires_at": 1717000000
}
```

### Error Response

```json
{
  "ok": false,
  "error": "Code redemption failed.",
  "reason": "<reason_code>"
}
```

### Reason Codes

| Reason | HTTP Status | Description |
|---|---|---|
| `backchannel_disabled` | 401 | `GLASSPORTAL_BACKCHANNEL_SSO_ENABLED` is false |
| `missing_code` | 401 | No `launch_code` in request body |
| `malformed_code` | 401 | Not a 64-character hex string |
| `code_not_found` | 401 | Code unknown or expired |
| `code_replayed` | 401 | Code was already consumed |
| `wrong_module` | 403 | Code was issued for a different module key |
| `inactive_module_link` | 403 | Module link is no longer active |
| `organization_mismatch` | 403 | Org on link changed since code was issued |
| `user_not_found` | 401 | User no longer exists |

---

## Code Lifecycle

```
GlassPortal issues code
  → code = bin2hex(random_bytes(32))   [64 hex chars, 256-bit entropy]
  → key  = sha256(code)                [cache key never contains raw code]
  → Cache::put("glassportal:sso:backchannel:p:{key}", payload, 60s)

Module redeems (first call)
  → Cache::has("glassportal:sso:backchannel:u:{key}") → false → not replayed
  → Cache::get("glassportal:sso:backchannel:p:{key}") → payload → code found
  → validate module_key matches payload
  → Cache::put("glassportal:sso:backchannel:u:{key}", 1, 600s)  [tombstone]
  → Cache::forget("glassportal:sso:backchannel:p:{key}")         [consume]
  → return identity data

Module redeems (second call — replay)
  → Cache::has("glassportal:sso:backchannel:u:{key}") → true → code_replayed
```

---

## Security Boundaries

```
GlassPortal (issuer)                    Module (redeemer)
─────────────────────────────────────   ────────────────────────────────────
Owns:  signing_secret (not shared)      Does NOT need signing_secret
       code issuance cache              Uses: any HTTP client
       code consumption tombstones      Calls: POST /api/sso/backchannel/redeem
       user identity + role

Does NOT:                               Does NOT:
  ∙ Return the code in URLs               ∙ Share sessions with GlassPortal
  ∙ Log the code value                    ∙ Have access to GlassPortal DB
  ∙ Store the code in the DB              ∙ Need to verify any HMAC
```

### What a Valid Redemption Proves

1. **Origin**: The code was issued by this GlassPortal instance (only GlassPortal can write to the code cache).
2. **Freshness**: The code has not expired (default 60s TTL).
3. **Audience**: The code was issued specifically for `{moduleKey}`.
4. **Single use**: This code has not been redeemed before on this GlassPortal instance.
5. **Identity**: The user and org data are as they were at issuance time (up to 60s ago).

### What a Valid Redemption Does NOT Prove

- That the user is still active (status may have changed after issuance).
- That the user's email or role is current (may have changed within the 60s TTL).

---

## Example: Laravel Module Integration

```php
// Module's receive controller (Laravel)

public function receive(Request $request): RedirectResponse
{
    $code = $request->post('launch_code', '');

    // Call GlassPortal back-channel redeem endpoint
    $response = Http::post(config('glassportal.base_url') . '/api/sso/backchannel/redeem/glasspanel', [
        'launch_code' => $code,
    ]);

    if (! $response->ok() || ! $response->json('ok')) {
        abort(401, 'SSO launch code invalid: ' . $response->json('reason'));
    }

    $data = $response->json();

    // Establish session for this user
    session([
        'user_id' => $data['user_id'],
        'email'   => $data['email'],
        'role'    => $data['role'],
    ]);

    return redirect('/dashboard');
}
```

---

## Why Codes Must Not Be Sent in URLs

Same reasoning as Phase 10 signed tokens:

1. **Server access logs** record the full URL on every hop.
2. **Browser history** records the URL.
3. **`Referer` header** leaks the URL to linked resources.

The handoff form uses `method="POST"` so `launch_code` appears only in the request body.

---

## Comparison: `signed_launch` vs `backchannel_launch`

| Property | `signed_launch` | `backchannel_launch` |
|---|---|---|
| Module needs secret | Yes | No |
| Token/code in browser | Yes (in form POST body) | Yes (in form POST body) |
| Verification | Module verifies HMAC locally | Module calls GlassPortal API |
| Secret compromise radius | All modules sharing secret | None — secret stays on GlassPortal |
| Network dependency | None at verify time | Module must reach GlassPortal at verify time |
| Suitable for | Modules with shared secret | Modules without access to shared secret |

---

## What Remains for Phase 12+

- **mTLS on redemption endpoint**: Require module client certificates so only known modules can call `/api/sso/backchannel/redeem`.
- **Per-module secrets on `signed_launch`**: Remove the need for a shared secret by allowing per-module HMAC keys.
- **OAuth 2.0 / OIDC**: For third-party identity provider interoperability.
- **`glasshouse/portal-auth` Composer package**: Package the back-channel client and signed launch verifier for module reuse.
