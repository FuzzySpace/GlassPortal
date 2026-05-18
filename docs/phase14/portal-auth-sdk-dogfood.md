# Phase 14 — SDK Dogfood & Release Readiness

## What was proven

Phase 14 establishes that `glasshouse/portal-auth` is installable, testable, and safe for downstream module consumption. It does not introduce new product features — it proves the SDK contract is sound before modules depend on it.

### Proven outcomes

| Claim | Evidence |
|---|---|
| SDK autoloads outside path-repository symlink | PSR-4 in root `composer.json` + 14-class healthcheck |
| Core SDK runs without Laravel | 55 standalone PHPUnit tests in `packages/glasshouse/portal-auth/tests/` |
| SDK verifier correctly validates tokens from GlassPortal's `SignedLaunchTokenService` | 16 dogfood integration tests in `tests/Feature/SdkDogfoodTest.php` |
| Tampered tokens rejected with `invalid_signature` | Dogfood tests: tampered payload, wrong secret |
| Wrong audience tokens rejected | Dogfood test + standalone test |
| Replay detected and blocked | Dogfood + standalone tests |
| Secrets and raw tokens never appear in result objects | Dedicated leakage tests (`serialize($result)` assertions) |
| Per-module secret end-to-end — portal signs, SDK verifies | Dogfood tests for match and mismatch |
| SDK composer.json has correct name, PHP ^8.3, and PSR-4 namespace | Healthcheck `sso.portal_auth_sdk.composer` |
| CI validates both app tests and SDK standalone tests | `.github/workflows/tests.yml` |

---

## Package install path

The SDK lives at `packages/glasshouse/portal-auth/` in the GlassPortal monorepo. Downstream modules consume it via path repository (development) or Satis/Packagist (production).

### Path repository (current)

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

The root `composer.json` also declares `"GlassHouse\\PortalAuth\\": "packages/glasshouse/portal-auth/src/"` directly in `autoload.psr-4` so the classes are always available even if the path-repository symlink is not built (e.g., after fresh `git clone` before `composer install`).

---

## Module integration flow

### 1. Install the SDK

```bash
composer require glasshouse/portal-auth:^1.0
```

### 2. Register the service provider (optional but recommended)

```php
// bootstrap/providers.php
\GlassHouse\PortalAuth\Laravel\PortalAuthServiceProvider::class,
```

This binds `SecretResolverInterface`, `ReplayStoreInterface`, and `SignedLaunchVerifier` into the Laravel container wired to `config('glasshouse_sso')`.

### 3. Register middleware aliases

```php
// bootstrap/app.php
$middleware->alias([
    'portal.signed-launch' => \GlassHouse\PortalAuth\Laravel\Middleware\VerifySignedModuleLaunch::class,
    'portal.mtls'          => \GlassHouse\PortalAuth\Laravel\Middleware\VerifyBackChannelMtls::class,
]);
```

### 4. Protect routes

```php
Route::post('/sso/consume/{moduleKey}', [SsoConsumeController::class, 'handle'])
    ->middleware('portal.signed-launch');
```

### 5. Set environment variables

```
GLASSPORTAL_SIGNED_LAUNCH_SECRET=<shared-with-portal>
GLASSPORTAL_SSO_ISSUER=glassportal
```

See `examples/laravel-module-sso-consumer/.env.example`.

---

## Signed launch flow

```
GlassPortal                             Module
    │                                       │
    ├── generate() → SLP token ─────────────►
    │   HMAC-SHA256(header.payload, secret)  │
    │                                       ├── VerifySignedModuleLaunch middleware
    │                                       │   · reads from $_POST only (never $_GET)
    │                                       │   · query-string token → 400
    │                                       │   · calls SignedLaunchVerifier::verify()
    │                                       │
    │                                       ├── On ok=true:
    │                                       │   $ctx = $request->attributes->get('signed_launch')
    │                                       │   Session::put('user_id', $ctx->userId)
```

**Token format:** `base64url(header).base64url(payload).base64url(HMAC-SHA256)`  
**Claims:** `iss`, `aud`, `sub`, `org`, `mid`, `email`, `name`, `role`, `iat`, `exp`, `nonce`, `jti`  
**Validity checks:** signature, issuer, audience, expiry (with 30s clock skew), JTI replay

---

## Back-channel redeem flow

```
GlassPortal          Browser                  Module
    │                    │                        │
    ├── issues code ─────►                        │
    │                    ├── POST launch_code ────►
    │                    │                        ├── POST /api/sso/backchannel/redeem/{key}
    │◄───────────────────────────────────────────── launch_code=<code>
    ├── redeem() validates code                   │
    ├── returns identity JSON ───────────────────►│
    │                    │                        ├── BackChannelRedeemResult::fromResponse()
    │                    │                        ├── Session::put(userId, orgId, role)
    │                    │◄── redirect ───────────┤
```

The SDK does not make network calls — it only parses the JSON response. The actual HTTP call to GlassPortal is the module's responsibility (use `Http::post()` or Guzzle).

---

## Security boundaries

| Responsibility | GlassPortal | Module |
|---|---|---|
| Token issuance and signing | ✓ | — |
| Signing secret storage | ✓ | must mirror via env |
| HMAC-SHA256 verification | — | ✓ (SDK) |
| JTI replay detection | ✓ (issues JTI) | ✓ (SDK replay store) |
| Query-string token rejection | — | ✓ (SDK middleware, 400) |
| Session creation | — | ✓ (module code) |
| PII minimization in logs | ✓ | must enforce in module |
| mTLS enforcement (backchannel) | ✓ | optional header check |
| Token expiry enforcement | — | ✓ (SDK, with clock skew) |

### Security invariants proven by tests

1. `serialize($result)` does not contain the raw token string
2. `serialize($result)` does not contain the signing secret
3. Failure results have `null` for `email` and `name` fields
4. Tampered payloads return `invalid_signature`, not a partial success
5. A token issued for module A cannot verify for module B (`wrong_audience`)

---

## What is NOT done yet

| Item | Notes |
|---|---|
| JWKS endpoint on GlassPortal | Required for asymmetric signing (RS256/ES256); deferred to Phase 15 |
| Packagist / Satis publication | SDK is path-repository only; publication needs package registry setup |
| Module-side token cache invalidation API | Modules cannot force-expire a JTI before its TTL; requires portal webhook |
| OAuth 2.0 / OIDC | Explicitly out of scope — different trust model, deferred |
| Full mTLS cert validation | Trusted-header contract is sufficient for current reverse-proxy topology |
| SDK versioning / CHANGELOG | Still at 1.0.0; needs formal versioning process when first external module ships |
| Asymmetric signing (RS256) | Would eliminate shared-secret requirement; Phase 15 or later |
| SDK package discovery (auto-provider) | Not added intentionally — modules must opt-in to service provider |

---

## Next phase recommendation

**Phase 15: JWKS key distribution + asymmetric signing**

- Add `GET /.well-known/portal-auth/jwks.json` to GlassPortal exposing RSA/EC public keys
- Update `SignedLaunchVerifier` to support RS256 alongside HS256
- Modules fetch the JWKS on startup; no shared secret required
- This removes the biggest operational risk (secret rotation, blast radius)
- Prerequisite for external partner modules outside the Glasshouse internal network

**Parallel track: Satis registry**

- Publish `glasshouse/portal-auth` to an internal Satis mirror
- Remove the `repositories` path entry requirement from module `composer.json`
- Enables standard `composer require glasshouse/portal-auth`
