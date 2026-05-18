# GlassPortal Module SSO Consumer — Example

This directory is a **reference example** showing how a downstream module (GlassPanel, Aria, Siona, etc.) integrates with GlassPortal's SSO via `glasshouse/portal-auth`.

It is **not a runnable application**. All source files are example code with explanatory comments.

---

## What this covers

| Flow | Mode | Description |
|---|---|---|
| Signed launch | `signed_launch` | GlassPortal redirects the browser to the module with a short-lived signed token in a POST form body |
| Back-channel | `backchannel_launch` | GlassPortal gives the browser a one-time code; the module calls GlassPortal server-to-server to redeem it |

---

## Prerequisites

- PHP 8.3+
- `glasshouse/portal-auth` installed (see below)
- Signing secret shared with GlassPortal (env-only — never in source)

---

## Installation

### Option A — Path repository (monorepo / pre-release)

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/srv/glassportal/packages/glasshouse/portal-auth",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "glasshouse/portal-auth": "^1.0"
  }
}
```

### Option B — Satis / private Packagist (production)

```bash
composer require glasshouse/portal-auth:^1.0
```

---

## Environment variables

Copy `.env.example` to `.env` and fill in the values:

```
GLASSPORTAL_SIGNED_LAUNCH_SECRET=<shared-secret-from-glassportal>
GLASSPORTAL_SSO_ISSUER=glassportal
GLASSPORTAL_BACKCHANNEL_REDEEM_URL=https://portal.example.com/api/sso/backchannel/redeem/glasspanel
```

**Security rule:** The secret must match the `GLASSPORTAL_SIGNED_LAUNCH_SECRET` (or per-module override) configured on the GlassPortal side. Never commit it to source control.

---

## Signed launch flow

```
GlassPortal                             Module (this example)
    │                                        │
    │──── POST /sso/launch (form) ──────────►│
    │     signed_launch_token=<token>        │
    │                                        │── VerifySignedModuleLaunch middleware
    │                                        │   reads token from POST body only
    │                                        │   rejects query-string tokens (400)
    │                                        │
    │                                        │── SDK verifies:
    │                                        │   · HMAC-SHA256 signature
    │                                        │   · aud == module key
    │                                        │   · exp + clock_skew not in past
    │                                        │   · JTI not replayed
    │                                        │
    │                                        │── On success: create local session
    │                                        │   $ctx = $request->attributes->get('signed_launch')
    │                                        │   Session::put('user_id', $ctx->userId)
    │                                        │
    │◄──── redirect to dashboard ────────────│
```

See `src/SsoConsumeController.php` for the handler.

---

## Back-channel redeem flow

```
GlassPortal                Browser                Module
    │                          │                     │
    │── issues launch_code ────►│                     │
    │                          │── POST /sso/bc ─────►│
    │                          │   launch_code=<code> │
    │                          │                     │── calls GlassPortal API ──►│
    │                          │                     │   POST /api/sso/backchannel/redeem/glasspanel
    │                          │                     │   launch_code=<code>
    │◄──────────────────────────────────────────────────── return identity JSON ──│
    │                          │                     │── BackChannelRedeemResult::fromResponse()
    │                          │                     │── create local session
    │                          │◄── redirect ────────│
```

See `src/BackChannelHandler.php` for the handler.

---

## Laravel middleware registration

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

```php
// routes/web.php
Route::post('/sso/consume', [SsoConsumeController::class, 'handle'])
    ->middleware('portal.signed-launch:glasspanel');

Route::post('/sso/backchannel', [BackChannelHandler::class, 'handle']);
```

---

## Service provider registration (optional)

If you want the SDK to bind its contracts into the Laravel DI container automatically:

```php
// bootstrap/providers.php
return [
    \GlassHouse\PortalAuth\Laravel\PortalAuthServiceProvider::class,
];
```

This binds `SecretResolverInterface`, `ReplayStoreInterface`, and `SignedLaunchVerifier` into the container, wired to your `glasshouse_sso` config.

---

## Module integration checklist

- [ ] `GLASSPORTAL_SIGNED_LAUNCH_SECRET` set and matches GlassPortal
- [ ] `GLASSPORTAL_SSO_ISSUER` set to `glassportal` (or your portal's issuer)
- [ ] Token is consumed from POST body only — **never** from URL query string
- [ ] `LaravelCacheReplayStore` (Redis-backed) used in production, not `ArrayReplayStore`
- [ ] Replay store cache is shared across all module instances (not in-process memory)
- [ ] Session is created only after `$result->ok === true`
- [ ] `$result->context->userId` and `$result->context->orgId` are authoritative — never trust user-submitted IDs
- [ ] Back-channel launch code is never logged, stored, or returned to browser
- [ ] Audit log records `jti`, `userId`, `orgId` on success — never the raw token or secret
- [ ] In production: consider per-module signing secret for blast-radius isolation

---

## Security boundaries

| Responsibility | GlassPortal | Module (this SDK) |
|---|---|---|
| Token issuance | ✓ | — |
| Secret management | ✓ | reads from env only |
| Signature verification | — | ✓ |
| Replay detection | ✓ (JTI cache) | ✓ (separate replay store) |
| Session creation | — | ✓ |
| mTLS enforcement | ✓ (backchannel) | optional (via middleware) |
| PII logging prevention | ✓ | must ensure in module code |
