# Phase 9 — Signed Launch Verification

## What Phase 9 Adds Beyond Phase 8

Phase 8 introduced the **token generation** side: GlassPortal signs a compact SLP token and POST-hands it off to a module via a browser form. The module received the token but had no standard way to verify it.

Phase 9 adds the **token verification** side:

| Concern | Phase 8 | Phase 9 |
|---|---|---|
| Token generation | ✅ SignedLaunchTokenService | unchanged |
| Token handoff | ✅ POST form (slt field) | unchanged |
| Token verification service | stub only | ✅ SignedLaunchVerifierService |
| Value object for verified claims | basic | ✅ VerifiedLaunchContext + rawClaims |
| Verification middleware | not registered | ✅ signed.launch alias |
| Local dev consume route | not middleware-backed | ✅ /_dev/sso/consume/{moduleKey} |
| Config flag for dev route | absent | ✅ GLASSPORTAL_ENABLE_DEV_SSO_CONSUME |
| Healthcheck coverage | token secret only | ✅ middleware + verifier + dev route |

---

## Token Generation vs Token Verification

### Generation (GlassPortal → Browser → Module)

```
User clicks "Secure Launch"
  → ModuleLaunchController.launch()
  → ModuleLaunchService.handleSignedLaunch()
  → SignedLaunchTokenService.generate()
       Returns: token, jti, expires_at
  → Renders module-launch-handoff.blade.php
       Hidden form: POST {moduleUrl} with field slt={token}
       Auto-submits after 400ms
  → Browser POSTs to module
```

Token format (SLP — Signed Launch Payload):
```
base64url(header) . base64url(payload) . base64url(HMAC-SHA256 signature)
```

Header:
```json
{"alg": "HS256", "typ": "SLP", "kid": "v1"}
```

Payload claims:
| Claim | Description |
|---|---|
| iss | Issuer — always "glassportal" (or GLASSPORTAL_SSO_ISSUER) |
| aud | Audience — the module_key (e.g. "glasspanel") |
| sub | Subject — user ID |
| org | Organization ID |
| mid | Module link ID (organization_module_links.id) |
| email | User email |
| name | User display name |
| role | User role value |
| iat | Issued-at Unix timestamp |
| exp | Expiry Unix timestamp (iat + TTL, default 60s) |
| nonce | Random hex (additional replay entropy) |
| jti | Unique token ID — tracked in cache, consumed on first verify |

### Verification (Module receives token)

```
Module endpoint receives POST with field slt (or launch_token)
  → signed.launch middleware
  → SignedLaunchVerifierService.verify(token, moduleKey)
  → SignedLaunchTokenService.verify(token, expectedAudience)
       Checks: signature, iss, aud, exp+clock_skew, required claims, JTI replay
       Returns: raw payload array
  → VerifiedLaunchContext::fromPayload(payload)
  → Attached to request as "signed_launch"
  → Controller reads $request->attributes->get('signed_launch')
```

---

## POST Body Handoff

The SLP token travels in the POST body — never in the URL query string. This means:

- The token does not appear in browser address bar history
- The token does not appear in module server access logs (typically only path is logged)
- The token is not visible in `Referer` headers on subsequent navigations

**Field names accepted by the middleware:**
1. `launch_token` — Phase 9 standard name
2. `slt` — Phase 8 backward-compatible name (read as fallback)

Both are accepted for compatibility with the Phase 8 handoff form which sends `slt`.

---

## Dev Consume Route

The route `POST /_dev/sso/consume/{moduleKey}` simulates a downstream module receiving a signed launch. It:

1. Applies the `signed.launch` middleware (full token verification + JTI consumption)
2. Returns the verified identity context as safe JSON

**When is it registered?**
- Always in `local` and `testing` environments
- In other environments when `GLASSPORTAL_ENABLE_DEV_SSO_CONSUME=true`

**Sample successful response:**
```json
{
  "ok": true,
  "module_key": "glasspanel",
  "organization_id": "42",
  "user_id": "7",
  "user_email": "alice@example.com",
  "user_name": "Alice",
  "role": "customer",
  "jti": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4",
  "expires_at": 1747345678
}
```

The raw token and signing secret are **never** included in the response.

**Failure responses:**
| Code | Cause |
|---|---|
| 401 | Missing token, invalid signature, expired token, replayed JTI, malformed token |
| 403 | Token is valid but audience/module_key does not match |
| 405 | GET (or other non-POST method) |
| 500 | Module key not resolvable (configuration error) |

---

## How Modules Should Integrate Later (Phase 10+)

Each downstream module (GlassBilling, GlassPanel, Aria, etc.) should:

1. Apply the `signed.launch` middleware (or equivalent) to their SSO receive endpoint.
2. Read `VerifiedLaunchContext` from request attributes under `signed_launch`.
3. Use `context->userId`, `context->orgId`, `context->role` to establish a session.
4. Never re-use the token — replay protection is enforced at first verification.

For a reusable module-side integration, Phase 10 should package this as a standalone Composer package (`glasshouse/portal-auth`) with its own middleware, so modules don't need to vendor GlassPortal code directly.

---

## Threat Model

| Threat | Mitigation |
|---|---|
| Token eavesdropping | HMAC-SHA256 signature — any modification invalidates the token |
| Token replay | JTI tracked in cache; consumed on first successful verify. Second use → 401 |
| Stale token reuse | Default 60s TTL (max 300s) + 30s clock-skew tolerance |
| Token in URL | Forbidden by design — POST body only; middleware rejects absent tokens |
| Secret exposure in token | HMAC signs but does not encrypt; no secret can be extracted from the token |
| Secret in logs | Middleware never logs the raw token; only jti/expires_at stored in audit log |
| Secret in DB | Full tokens are never stored; only jti + expires_at in module_launch_events |
| Wrong module trust | Audience (`aud`) claim verified against expected module key — 403 if mismatch |
| Cross-org token use | `org` claim carries organization ID; modules must validate this claim |
| Clock manipulation | clock_skew_seconds (30) provides tolerance; tokens expire quickly |

---

## Replay Protection

The JTI (unique token ID) lifecycle:

1. **Generation**: `Cache::put("signed-launch:issued:{jti}", exp, cacheTtl)` — tracked for 600s
2. **First verification**: `Cache::has(jtiKey)` → true → `Cache::forget(jtiKey)` → token consumed
3. **Second verification**: `Cache::has(jtiKey)` → false → `InvalidArgumentException("replay")`

If the cache is unavailable, replay detection degrades gracefully (the token is still verified by signature and expiry). This is logged as a degraded mode condition.

---

## Known Limitations

1. **Browser-mediated handoff**: The token passes through the browser. A compromised browser extension or XSS could intercept it within its 60s window. Phase 10 back-channel exchange eliminates this.

2. **Shared secret**: All signed-launch links share one `GLASSPORTAL_SIGNED_LAUNCH_SECRET`. A secret compromise affects all modules. Key-per-module isolation requires Phase 10 back-channel or OIDC.

3. **Cache dependency for replay protection**: Redis or DB-backed cache recommended in production. File cache loses replay state on restart.

4. **No module identity verification**: GlassPortal trusts `external_url` from the database. If a module URL is compromised, tokens may be sent to the wrong endpoint. TLS + pinned certs in Phase 10.

5. **No rate limiting on the consume route**: The dev consume route is unthrottled. In staging with the flag enabled, add infrastructure-level rate limiting.

---

## Phase 10 Recommendations

### Back-Channel Token Exchange
Replace browser-mediated handoff with a one-time code that the module redeems by calling GlassPortal directly:

```
Browser → GlassPortal  : request launch
GlassPortal → DB/Cache : store one-time code (OTC, 10s TTL)
GlassPortal → Browser  : redirect to module with OTC in URL
Module → GlassPortal   : POST /api/sso/redeem {code: OTC}
GlassPortal → Module   : return identity JSON (no token in browser)
```

This eliminates the token from the browser entirely and enables full audit logging.

### Module SDK / Middleware Package
Package `glasshouse/portal-auth` (Composer):
- `VerifyPortalLaunchMiddleware` — wraps verification
- `PortalIdentity` — typed access to verified claims
- Versioned to track GlassPortal API changes

### Key Rotation with KID
The `kid` (Key ID) header claim is already parsed. Phase 10 should:
1. Support multiple active keys in `glasshouse_sso.keys`
2. Provide a `/api/sso/.well-known/keys` endpoint (JWKS-style for HS256)
3. Automated key rotation via scheduled command

### Rate Limiting
Add per-IP and per-user rate limiting to:
- The portal launch endpoint (`/portal/modules/{link}/launch`)
- Any future back-channel redeem endpoint

### OAuth 2.0 / OIDC
For modules that integrate with third-party systems, implementing an Authorization Code flow (with PKCE) using a proper OIDC library is the long-term target. The SLP token approach is an interim foundation.
