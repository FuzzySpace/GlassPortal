# Phase 10 — Module-Side Signed Launch Verification Kit

## Overview

GlassPortal issues signed launch tokens (SLP — Signed Launch Payload) and POST-hands them to downstream modules via browser form submission. Phase 10 standardizes how those modules verify the tokens so that GlassBilling, GlassPanel, Aria, SIONA, PowerDNS, Mailcow, and future Glasshouse modules all follow the same security contract without copy-paste drift.

---

## What Phase 10 Adds

| Concern | Before Phase 10 | After Phase 10 |
|---|---|---|
| Middleware token fields | `launch_token`, `slt` | `signed_launch_token`, `launch_token`, `slt` |
| Query-string token rejection | Not enforced | Explicit 400 response |
| Verification result type | Throws exceptions | Typed `SignedLaunchVerificationResult` |
| Failure reason codes | String in exception | Normalized reason constants |
| Cache probe | Not exposed | `ModuleSignedLaunchVerifier::isCacheUsable()` |
| Healthcheck | Phase 9 checks | + `sso.module_verifier`, `sso.replay_cache` |

---

## Verification Contract

### Accepted POST Fields (in order of precedence)

1. `signed_launch_token` — Phase 10 canonical name
2. `launch_token` — Phase 9 alias (kept for backward compatibility)
3. `slt` — Phase 8 original name (kept for backward compatibility)

**Tokens in URL query strings are always rejected.** Server access logs record query strings; a token in the URL is permanently logged on every machine the request passes through.

### Expected Claims

| Claim | Field | Description |
|---|---|---|
| `iss` | issuer | `"glassportal"` (or configured issuer) |
| `aud` | audience | Module key, e.g. `"glasspanel"` |
| `sub` | userId | GlassPortal user ID (string) |
| `org` | orgId | GlassPortal organization ID (string) |
| `mid` | moduleLinkId | `organization_module_links.id` (string) |
| `email` | email | User email address |
| `name` | name | User display name |
| `role` | role | User role: `owner`, `admin`, `staff`, `support`, `customer` |
| `iat` | issuedAt | Unix timestamp — token creation time |
| `exp` | expiresAt | Unix timestamp — token expiry (default +60s) |
| `nonce` | nonce | Random hex — additional replay entropy |
| `jti` | jti | Unique token ID — consumed on first verify |

### Failure Reason Codes

| Reason | HTTP Status | Description |
|---|---|---|
| `missing_token` | 401 | No token field in POST body |
| `query_string_token` | 400 | Token found in URL query string |
| `malformed_token` | 401 | Wrong number of segments, or unparsable payload |
| `invalid_signature` | 401 | HMAC check failed, wrong issuer, or missing required claims |
| `expired_token` | 401 | `exp` + clock skew is in the past |
| `wrong_audience` | 403 | `aud` claim does not match expected module key |
| `replay_detected` | 401 | JTI already consumed — replay or double-submit |
| `secret_missing` | 401 | `GLASSPORTAL_SIGNED_LAUNCH_SECRET` not configured |
| `inactive_module_link` | *(caller-supplied)* | Module link is suspended — checked after verification |
| `organization_mismatch` | *(caller-supplied)* | `org` claim doesn't match caller's expected org |

`inactive_module_link` and `organization_mismatch` are not checked by the verifier — they require database lookups that the caller performs against module data after a successful cryptographic verification.

---

## Required Environment Variables

```env
# On GlassPortal (token issuer)
GLASSPORTAL_SIGNED_LAUNCH_SECRET=<base64 random 64 bytes>
GLASSPORTAL_SSO_ISSUER=glassportal

# Optional — token lifetime and clock tolerance
GLASSPORTAL_SIGNED_LAUNCH_TTL=60
```

Modules receiving tokens need the **same secret** and the **same issuer string** to verify. In Phase 11, this will be replaced by a back-channel exchange so modules don't need the secret at all.

Generate a strong secret:
```bash
php artisan tinker --execute="echo base64_encode(random_bytes(64));"
# OR
openssl rand -base64 64
```

---

## Example: Laravel Module Integration

### 1. Register the middleware alias

In your module's `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'signed.launch' => \App\Http\Middleware\VerifySignedModuleLaunch::class,
    ]);
})
```

### 2. Protect the SSO receive endpoint

```php
// routes/web.php or routes/api.php
Route::post('/sso/receive', [SsoReceiveController::class, 'handle'])
    ->middleware('signed.launch:glasspanel'); // ← your module key
```

Or using the route parameter pattern:
```php
Route::post('/sso/receive/{module}', [SsoReceiveController::class, 'handle'])
    ->middleware('signed.launch'); // module key resolved from {module} route param
```

### 3. Read the verified context in your controller

```php
use App\Data\Sso\VerifiedLaunchContext;
use Illuminate\Http\Request;

class SsoReceiveController extends Controller
{
    public function handle(Request $request): Response
    {
        /** @var VerifiedLaunchContext $ctx */
        $ctx = $request->attributes->get('signed_launch');

        // Establish a session for this user
        $session = $this->sessionService->createFor(
            userId:   $ctx->userId,
            orgId:    $ctx->orgId,
            email:    $ctx->email,
            name:     $ctx->name,
            role:     $ctx->role,
        );

        return redirect('/dashboard')->with('session_id', $session->id);
    }
}
```

### 4. Using the service directly (without middleware)

```php
use App\Services\Sso\ModuleSignedLaunchVerifier;

class SsoReceiveController extends Controller
{
    public function __construct(private ModuleSignedLaunchVerifier $verifier) {}

    public function handle(Request $request): JsonResponse
    {
        // Read token from POST body only — never from query string
        $token = (string) ($request->post('signed_launch_token')
                        ?: $request->post('launch_token')
                        ?: $request->post('slt')
                        ?: '');

        $result = $this->verifier->verify($token, 'glasspanel');

        if (! $result->ok) {
            return response()->json(['error' => $result->reason], 401);
        }

        // $result->safeContext is a VerifiedLaunchContext
        // $result->claims is an array of all claim key/values
        // $result->jti is the token's unique ID
        // $result->expiresAt is the expiry Unix timestamp
    }
}
```

---

## Example: Non-Laravel Pseudocode

For modules not built on Laravel:

```python
import hmac, hashlib, base64, json, time

def b64url_decode(s):
    pad = 4 - len(s) % 4
    return base64.urlsafe_b64decode(s + '=' * (pad % 4))

def verify_slp_token(token, module_key, secret, issuer='glassportal',
                     clock_skew=30):
    parts = token.split('.')
    if len(parts) != 3:
        raise ValueError('malformed_token')

    header_b64, payload_b64, sig_b64 = parts

    # 1. Signature
    expected = hmac.new(
        secret.encode(), f'{header_b64}.{payload_b64}'.encode(), hashlib.sha256
    ).digest()
    expected_b64 = base64.urlsafe_b64encode(expected).rstrip(b'=').decode()
    if not hmac.compare_digest(expected_b64, sig_b64):
        raise ValueError('invalid_signature')

    # 2. Decode
    payload = json.loads(b64url_decode(payload_b64))

    # 3. Required claims
    for c in ['iss', 'aud', 'sub', 'org', 'email', 'iat', 'exp', 'jti']:
        if c not in payload:
            raise ValueError('malformed_token')

    # 4. Issuer
    if payload['iss'] != issuer:
        raise ValueError('invalid_signature')

    # 5. Audience
    if payload['aud'] != module_key:
        raise ValueError('wrong_audience')

    # 6. Expiry
    if time.time() > payload['exp'] + clock_skew:
        raise ValueError('expired_token')

    # 7. Replay — store and check JTI in your own cache/DB
    jti = payload['jti']
    if jti_store.exists(jti):
        raise ValueError('replay_detected')
    jti_store.set(jti, 1, ex=600)

    return payload
```

---

## Security Boundaries

```
GlassPortal (issuer)                    Module (receiver)
─────────────────────────────────────   ────────────────────────────────────
Owns:  signing_secret                   Owns:  signing_secret (shared)
       JTI issuance cache                      JTI consumption cache (own)
       organization_module_links
       user identity + role

Does NOT:                               Does NOT:
  ∙ Store the signed token                ∙ Know the signing algorithm
  ∙ Log the signed token                  ∙ Have access to GlassPortal DB
  ∙ Send the token in URLs                ∙ Share sessions with GlassPortal

Trust boundary: the token itself.
A valid token proves GlassPortal vouched for this user for this module
at this moment. Nothing more.
```

### What a Valid Token Proves

1. **Origin**: The token was signed by the holder of `GLASSPORTAL_SIGNED_LAUNCH_SECRET`.
2. **Freshness**: The token was issued at most `exp - iat` seconds ago (default 60s).
3. **Audience**: The token was intended specifically for `aud` (your module key).
4. **Identity**: The claims (email, org, role) are the values GlassPortal had at issuance time.
5. **Single use**: The JTI has not been verified before on this verifier.

### What a Valid Token Does NOT Prove

- That the user is still active in GlassPortal (role or org may have changed after issuance).
- That the module link is still active (check your own data).
- That the user's email is current (email may have changed since the 60s TTL started).

---

## Replay Protection

The JTI (token ID) lifecycle:

```
GlassPortal issues token
  → Cache::put("signed-launch:issued:{jti}", exp, 600s)

Module first verify
  → Cache::has(jtiKey)  → true  → consume → Cache::forget(jtiKey)
  → Returns: ok=true

Module second verify (same token)
  → Cache::has(jtiKey)  → false → replay_detected
  → Returns: ok=false, reason=replay_detected
```

**Important**: Each deployment (GlassPortal + module) must use the **same cache store** to share JTI state, or modules must maintain their own independent JTI consumption store. If GlassPortal and GlassBilling share a Redis instance, replay detection is shared. If they use separate Redis instances, the module's verifier should maintain its own JTI log.

The safest design (Phase 11) is a back-channel exchange where GlassPortal marks the JTI consumed before the module ever sees the token.

---

## Why Tokens Must Not Be Sent in URLs

1. **Server access logs** record the full URL on every server, load balancer, proxy, and CDN the request traverses. A token in the URL is permanently logged.
2. **Browser history** records the URL including query string.
3. **`Referer` header** leaks the full URL to any resource linked from the page.
4. **HTTP caches** may cache a URL-containing response and serve the token to another client.

The SLP token handoff uses `<form method="POST">` specifically to prevent these leaks. The middleware explicitly rejects tokens found in query strings with HTTP 400.

---

## Why Secrets Must Be Environment-Only

`GLASSPORTAL_SIGNED_LAUNCH_SECRET` must never appear in:

| Location | Risk |
|---|---|
| Source code | Permanent, indexed in version control history |
| `config/*.php` files | Config cache may be world-readable |
| Log files | Logs are shipped to aggregators |
| Database rows | Audit trail is not encrypted at rest |
| HTTP responses | Browser devtools / proxies capture responses |
| Test fixtures | Test repos are often public |

Rotate the secret immediately if it ever appears in any of these locations.

---

## Dev/Test Consume Route

`POST /_dev/sso/consume/{moduleKey}`

Available when:
- `APP_ENV=local` or `APP_ENV=testing` (always), OR
- `GLASSPORTAL_ENABLE_DEV_SSO_CONSUME=true` (for staging)

This endpoint simulates a downstream module receiving a signed launch. It applies the full `signed.launch` middleware verification stack and returns the safe identity context as JSON.

**Never enable in production.** There is no authentication on this endpoint beyond the signed token itself.

---

## What Remains for Phase 11

### Back-Channel Token Exchange (Priority 1)

Replace browser-mediated handoff with a one-time code pattern:

```
Browser → GlassPortal  : GET /portal/modules/{link}/launch
GlassPortal → Cache    : store one-time code (OTC, 10s TTL) → jti
GlassPortal → Browser  : redirect to {module_url}?otc={OTC}
Browser → Module       : GET {module_url}?otc={OTC}
Module → GlassPortal   : POST /api/sso/redeem {otc: OTC}  (server-to-server)
GlassPortal → Module   : {"user_id":..., "email":..., "role":...}
```

This removes the token from the browser entirely. The secret is only needed on GlassPortal's side.

### Module Package (`glasshouse/portal-auth`)

Package the verification middleware, result object, and pseudocode examples as a standalone Composer package. Modules should not vendor GlassPortal's app code directly.

### Key Rotation with KID

The `kid` header claim is already generated when `GLASSPORTAL_SIGNED_LAUNCH_KEY_ID` is set. Phase 11 should add:
- Automated key rotation via `php artisan sso:rotate-key`
- A `/api/sso/.well-known/keys` endpoint for modules to fetch the current public key map
- Configurable key overlap window so tokens signed with the old key remain valid during rotation

### Rate Limiting on Verification

Add per-IP rate limiting to the module-side verification endpoint to prevent brute-force signature attempts.

### Per-Module Secrets

Currently all modules share one `GLASSPORTAL_SIGNED_LAUNCH_SECRET`. Phase 11 should allow per-module-key secrets so a compromise of one module's verification key doesn't affect others.

### OAuth 2.0 / OIDC

For modules that need to interoperate with third-party identity providers, implement the Authorization Code flow (with PKCE). The SLP approach is an interim foundation, not a permanent SSO architecture.
