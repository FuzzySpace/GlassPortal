# Phase 15 — JWKS/Key Rotation Foundation

## What was delivered

Phase 15 establishes the key lifecycle infrastructure for GlassPortal signed launch tokens. It introduces a rich `key_registry` config with per-key status tracking, a `SigningKeyResolver` service, a public JWKS endpoint, and SDK support for status-aware kid verification — all while preserving full backward compatibility with existing single-secret deployments.

### Delivered outcomes

| Claim | Evidence |
|---|---|
| Key registry with status lifecycle (active/previous/disabled) | `config/glasshouse_sso.php` → `key_registry` |
| Active kid drives token issuance | `SignedLaunchTokenService::generate()` → `ModuleSecretResolver::activeKeyInfo()` |
| Previous kid still verifies in-flight tokens | `SigningKeyResolver::resolveByKid()` returns secret for previous |
| Disabled kid rejected on verify | `resolveByKid()` returns `null`; empty secret causes HMAC mismatch |
| JWKS endpoint publishes safe metadata | `GET /.well-known/glassportal/jwks.json` |
| Raw secrets never appear in JWKS response | Unit test: `assertStringNotContainsString(secret, response)` |
| Disabled keys excluded from JWKS | `publicKeyMetadata()` filters by status |
| Per-module secrets bypass kid embedding | `generate()` skips kid when `hasPerModuleSecret()` is true |
| SDK `fromConfig()` reads key_registry | Status-aware merge; disabled → `''` sentinel |
| Legacy single-secret mode preserved | All existing tests pass unchanged (358 app + 66 SDK) |
| Healthcheck warns on missing active kid or legacy-only setup | `sso.keys_configured`, `sso.active_kid`, `sso.jwks_route`, `sso.legacy_secret_fallback` |

---

## Architecture

### Key lifecycle states

```
active   — signs new tokens; valid for verification
previous — no longer signs; valid for verification (tokens in flight)
disabled — explicitly revoked; rejected on verification
```

### Priority chain for issuance

```
generate(link, user):
  1. per_module_secrets[moduleKey]       — isolated per-module secret (no kid embedded)
  2. SigningKeyResolver::activeSigningKey() — active key_registry entry (kid embedded)
  3. signing_secret                      — legacy global fallback (no kid)
```

### Priority chain for verification

```
verify(token, audience):
  1. per_module_secrets[audience]        — per-module (no kid check)
  2. key_registry[kid] (status-aware):
       active/previous → use secret
       disabled        → return '' → HMAC mismatch → invalid_signature
  3. keys[kid]                           — flat legacy map (Phase 9 compat)
  4. signing_secret                      — global fallback (no-kid tokens)
```

### Services

| Class | Role |
|---|---|
| `App\Services\Sso\SigningKeyResolver` | Reads key_registry; status-aware resolution; JWKS metadata |
| `App\Services\Sso\ModuleSecretResolver` | Priority chain orchestration; integrates SigningKeyResolver |
| `App\Services\Sso\SignedLaunchTokenService` | Token issuance and verification (updated for registry) |
| `App\Http\Controllers\Api\JwksController` | Public JWKS endpoint |
| `GlassHouse\PortalAuth\Sso\ModuleSecretResolver` | SDK equivalent; fromConfig reads key_registry |

---

## Configuration

### `config/glasshouse_sso.php` additions

```php
// Phase 15: active kid for signing new tokens
'active_kid' => env('GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID', ''),

// Phase 15: rich key registry with status lifecycle
'key_registry' => [
    // 'v1' => [
    //     'secret'     => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1', ''),
    //     'algorithm'  => 'HS256',
    //     'status'     => 'previous',
    //     'created_at' => '2025-01-01',
    //     'rotated_at' => '2026-01-01',
    // ],
    // 'v2' => [
    //     'secret'     => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2', ''),
    //     'algorithm'  => 'HS256',
    //     'status'     => 'active',
    //     'created_at' => '2026-01-01',
    // ],
],
```

### Environment variables

```
# Active signing key
GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID=v2

# Key secrets (never hardcode — always from env)
GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1=<old-secret>
GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2=<new-secret>
```

---

## JWKS endpoint

```
GET /.well-known/glassportal/jwks.json
```

**Response (example):**
```json
{
  "keys": [
    {
      "kid": "v1",
      "alg": "HS256",
      "use": "sig",
      "kty": "oct",
      "status": "previous",
      "iss": "glassportal"
    },
    {
      "kid": "v2",
      "alg": "HS256",
      "use": "sig",
      "kty": "oct",
      "status": "active",
      "iss": "glassportal"
    }
  ]
}
```

**Security invariants:**
- `secret` and `k` (raw key material) are **never** included
- Disabled keys are excluded entirely
- No authentication required — key metadata is intentionally public (no secrets exposed)
- `Cache-Control: public, max-age=300` allows module-side caching

---

## Rotation runbook

### Adding a new active key (v1 → v2)

1. **Generate new secret:**
   ```bash
   openssl rand -base64 64
   ```

2. **Add v2 to `config/glasshouse_sso.php`:**
   ```php
   'key_registry' => [
       'v1' => ['secret' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1'), 'algorithm' => 'HS256', 'status' => 'previous', ...],
       'v2' => ['secret' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2'), 'algorithm' => 'HS256', 'status' => 'active',   ...],
   ],
   'active_kid' => env('GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID', ''),
   ```

3. **Set env vars:**
   ```
   GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID=v2
   GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2=<new-secret>
   ```

4. **Deploy:** new tokens now carry `kid: v2`; v1 tokens (in flight) still verify.

5. **After all v1 tokens expire** (max TTL 300s + clock skew 30s = ~6 minutes):
   ```php
   'v1' => ['status' => 'disabled', ...],
   ```
   Redeploy. v1 tokens are now rejected.

6. **Optional cleanup:** remove v1 from registry once confident no v1 tokens can still arrive.

### Disabling a compromised key

1. Set the entry's `status` to `'disabled'` in `key_registry`
2. Deploy immediately — all tokens bearing that kid are rejected on the next verify
3. Rotate to a new active kid if not already done

---

## Security boundaries

| Responsibility | GlassPortal | Module |
|---|---|---|
| Key issuance and signing | ✓ | — |
| Key status enforcement | ✓ (SigningKeyResolver) | ✓ (SDK ModuleSecretResolver) |
| Disabled key rejection | ✓ | ✓ (SDK) |
| JWKS metadata publication | ✓ (JwksController) | — |
| Secret-free JWKS response | ✓ (enforced by design) | — |
| Per-module secret isolation | ✓ | must mirror via env |

### Security invariants proven by tests

1. `publicKeyMetadata()` does not contain `secret` or `k` fields
2. JWKS HTTP response does not contain the raw signing secret string
3. Disabled key tokens receive `invalid_signature` (HMAC mismatch), not a partial success
4. Previous key tokens still verify (rotation grace period)
5. Per-module secret tokens do not embed a kid

---

## What is NOT done yet

| Item | Notes |
|---|---|
| Asymmetric signing (RS256/ES256) | Requires JWKS to expose public key material (`n`, `e`, `x`, `y`); Phase 16 |
| JWKS fetch on module side | Modules currently configure secrets via env; async JWKS polling deferred |
| Automatic key expiry | Registry status is set manually; automated TTL-based promotion/demotion deferred |
| Packagist / Satis publication | SDK is path-repository only |
| Webhook for module-side token invalidation | Modules cannot force-expire a JTI before TTL |

---

## Next phase recommendation

**Phase 16: Asymmetric signing (RS256/ES256)**

- Generate RSA/EC key pairs on GlassPortal
- Sign tokens with private key; expose public key in JWKS (`kty: RSA`, `n`, `e`)
- Modules verify with public key — no shared secret required
- Eliminates shared-secret distribution and rotation blast radius
- Required for external partner modules outside the Glasshouse network

**Parallel track: module-side JWKS client**

- SDK downloads and caches the JWKS JSON at startup
- Verifies tokens using public key from JWKS (Phase 16 prerequisite)
- Falls back to configured secret for backward compat during migration
