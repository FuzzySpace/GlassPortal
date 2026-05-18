# Phase 13 — `glasshouse/portal-auth` SDK

## Purpose

Modules in the Glasshouse ecosystem (GlassPanel, Aria, Siona, DNS adapters, Mailcow portal adapters) need to verify GlassPortal-issued launch tokens without copying application code from the GlassPortal monolith.

`glasshouse/portal-auth` is the canonical SDK for:

- **Signed launch token (SLP) verification** — HMAC-SHA256 compact token with JTI replay detection
- **Back-channel redeem result parsing** — typed DTO wrapping the JSON from `/api/sso/backchannel/redeem/{moduleKey}`
- **Secret resolution** — per-module → KID key rotation → global fallback
- **Laravel integration** — service provider, middleware, cache-backed replay store

The SDK is **framework-light by design**: core verification logic (`SignedLaunchVerifier`, `ModuleSecretResolver`, `ArrayReplayStore`) has zero external dependencies. Laravel-specific classes live under the `Laravel\` sub-namespace.

---

## Installation

### Via local path repository (monorepo / development)

In the consuming module's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/path/to/glassportal/packages/glasshouse/portal-auth",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "glasshouse/portal-auth": "^1.0"
  }
}
```

### Via private Packagist / Satis (production)

Publish the package to your internal Satis mirror, then:

```bash
composer require glasshouse/portal-auth:^1.0
```

---

## Expected Environment Variables

| Variable | Required for | Notes |
|---|---|---|
| `GLASSPORTAL_SIGNED_LAUNCH_SECRET` | Signed launch verification | Long random HMAC key; shared with GlassPortal |
| `GLASSPORTAL_MODULE_SECRET_<MODULE>` | Per-module isolation | Optional; overrides global secret for one module |
| `GLASSPORTAL_SIGNED_LAUNCH_KEY_ID` | KID rotation | Set when GlassPortal rotates keys |
| `GLASSPORTAL_SIGNED_LAUNCH_SECRET_V<N>` | KID rotation | Map KID → secret in `config/glasshouse_sso.php` |
| `GLASSPORTAL_SSO_ISSUER` | Issuer validation | Must match GlassPortal's `issuer` config (default: `glassportal`) |

**Security rule: secrets must be set in the environment only — never in source code, config files committed to VCS, or application logs.**

---

## Signed Launch Verification (framework-free)

```php
use GlassHouse\PortalAuth\Sso\SignedLaunchVerifier;
use GlassHouse\PortalAuth\Sso\ModuleSecretResolver;
use GlassHouse\PortalAuth\Sso\SignedLaunchTokenParser;
use GlassHouse\PortalAuth\Replay\ArrayReplayStore;

// Build the verifier (use LaravelCacheReplayStore in production)
$resolver = new ModuleSecretResolver(
    globalSecret:    getenv('GLASSPORTAL_SIGNED_LAUNCH_SECRET'),
    perModuleSecrets: [],
    keyMap:          [],
);

$verifier = new SignedLaunchVerifier(
    secretResolver: $resolver,
    replayStore:    new ArrayReplayStore(),   // swap for LaravelCacheReplayStore in prod
    parser:         new SignedLaunchTokenParser(),
    issuer:         'glassportal',
    clockSkew:      30,
    replayTtl:      600,
);

$token  = $_POST['signed_launch_token'] ?? '';   // NEVER read from $_GET
$result = $verifier->verify($token, 'glasspanel');

if (! $result->ok) {
    http_response_code(401);
    echo json_encode(['error' => $result->reason]);
    exit;
}

$ctx = $result->context;
echo "Welcome, {$ctx->name} — user ID {$ctx->userId}, org {$ctx->orgId}";
```

**Token must arrive in POST body only.** The middleware layer enforces this. Never read from `$_GET` or URL query strings — they appear in server access logs.

---

## Signed Launch Verification (Laravel with service provider)

### 1. Register the service provider

In `bootstrap/providers.php` (Laravel 11) or `config/app.php` providers:

```php
\GlassHouse\PortalAuth\Laravel\PortalAuthServiceProvider::class,
```

### 2. Register middleware aliases

In `bootstrap/app.php`:

```php
->withMiddleware(function ($middleware) {
    $middleware->alias([
        'portal.signed-launch' => \GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch::class,
        'portal.mtls'          => \GlassHouse\PortalAuth\Laravel\Middleware\VerifyBackChannelMtls::class,
    ]);
})
```

### 3. Apply to routes

```php
Route::post('/sso/consume/{moduleKey}', [SsoController::class, 'consume'])
    ->middleware('portal.signed-launch');
```

On success, the verified context is available as:

```php
$ctx = $request->attributes->get('signed_launch');
// $ctx is a GlassHouse\PortalAuth\DTO\VerifiedLaunchContext
```

---

## Back-Channel Redeem Verification Model

Modules using `backchannel_launch` auth mode:

1. Browser receives `launch_code` from GlassPortal handoff view (POST form field — **never URL**)
2. Browser POSTs `launch_code` to the module's SSO endpoint
3. Module calls GlassPortal server-to-server:
   ```
   POST /api/sso/backchannel/redeem/{moduleKey}
   Content-Type: application/x-www-form-urlencoded
   launch_code=<code>
   ```
4. Parse the response using the SDK DTO:

```php
use GlassHouse\PortalAuth\DTO\BackChannelRedeemResult;

$http     = new \GuzzleHttp\Client();
$response = $http->post("https://portal.example.com/api/sso/backchannel/redeem/glasspanel", [
    'form_params' => ['launch_code' => $launchCode],
]);

$data = json_decode($response->getBody(), true);

if ($response->getStatusCode() === 200 && ($data['ok'] ?? false)) {
    $result = BackChannelRedeemResult::fromResponse($data);
    // $result->userId, $result->email, $result->role
} else {
    $result = BackChannelRedeemResult::fromErrorResponse($data);
    // $result->reason — log but do not expose to browser
}
```

**Security:** The `launch_code` must never be logged, stored in the DB, or included in error responses.

---

## Laravel Middleware Registration Example

```php
// bootstrap/app.php
use GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch;
use GlassHouse\PortalAuth\Laravel\Middleware\VerifyBackChannelMtls;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function ($middleware) {
        $middleware->alias([
            'portal.signed-launch' => VerifySignedModuleLaunch::class,
            'portal.mtls'          => VerifyBackChannelMtls::class,
        ]);
    })
    ->create();
```

---

## Module Integration Checklist

- [ ] Set `GLASSPORTAL_SIGNED_LAUNCH_SECRET` in production environment
- [ ] Set `GLASSPORTAL_SSO_ISSUER` to match GlassPortal's issuer value (`glassportal`)
- [ ] Token is read from POST body only — **never** from URL query string
- [ ] `VerifiedLaunchContext` is used for identity — **never** trust user-supplied identity claims
- [ ] If using KID rotation: populate `keys` map in config to match GlassPortal's `glasshouse_sso.keys`
- [ ] If using per-module secret: set matching env var on both GlassPortal and the module
- [ ] Use `LaravelCacheReplayStore` (or equivalent persistent store) in production — not `ArrayReplayStore`
- [ ] Replay store uses a shared cache backend (Redis recommended) — in-memory only works on single-process
- [ ] If using back-channel: mTLS or network-level isolation between module and GlassPortal's redeem endpoint
- [ ] Audit: log `jti`, `userId`, `orgId`, and `expiresAt` on successful launch — never log raw token or secret
- [ ] Health check: verify `SignedLaunchVerifier` can be constructed and `ReplayStoreInterface::isHealthy()` returns true

---

## Security Rules

1. **No secrets in source code.** All signing secrets are env-only.
2. **Tokens in POST body only.** URL query strings appear in server access logs, proxy logs, and browser history.
3. **One-time use.** The JTI replay store prevents token reuse. Use a shared, persistent cache in production.
4. **Short TTL.** Default token lifetime is 60 seconds. Clock skew tolerance is 30 seconds.
5. **Audience locked.** A token issued for `glasspanel` cannot be used to authenticate to `aria`.
6. **Per-module secret isolation.** A compromise of one module's secret does not expose other modules.
7. **No PII in failure responses.** On verification failure, return only the reason code — not email, name, or user ID.

---

## What Is Intentionally Not Included Yet

| Capability | Reason deferred |
|---|---|
| OAuth 2.0 / OIDC | Phase 14+ — requires token endpoint infrastructure |
| Full mTLS certificate parsing | Trusted-header contract sufficient for current reverse-proxy topology |
| Token issuance (GlassPortal side) | Stays in GlassPortal monolith — modules only verify |
| Asymmetric signing (RS256/ES256) | Shared HMAC adequate for internal mesh; RS256 adds complexity without benefit at current scale |
| JWK Set (JWKS) endpoint client | Not needed while all modules share the secret via env |

---

## Phase 14 Recommendation

The next phase should add a **JWKS-compatible key distribution endpoint** to GlassPortal (`GET /.well-known/portal-auth/jwks.json`) serving RSA public keys. Modules would call this on startup to fetch verification keys, eliminating the need to share secrets via env. This is the foundation for external module support beyond the internal Glasshouse ecosystem.
