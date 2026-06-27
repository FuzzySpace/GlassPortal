# Phase 21A — SIONA Per-Module Signing Secret Hardening

## Purpose

Phase 20 scaffolded `GLASSPORTAL_MODULE_SECRET_SIONA` in `.env.example` but left
it unwired — SIONA signed-launch tokens still used the global signing secret.
Phase 21A closes that gap by wiring SIONA into GlassPortal's existing
per-module secret system, so SIONA can have its own dedicated HMAC signing
secret. Compromise of one module's secret no longer exposes SIONA (and vice
versa).

This phase introduces **no new resolver and no parallel secret system** — it
reuses `ModuleSecretResolver` and the Phase 12 `per_module_secrets` map.

---

## Environment Variable

| Variable | Default | Description |
|---|---|---|
| `GLASSPORTAL_MODULE_SECRET_SIONA` | `""` (empty) | Dedicated HMAC signing secret for SIONA signed-launch tokens. Empty → global fallback. **Never commit a real value.** |

Documented (commented, valueless) in `.env.example` alongside the other
per-module secrets.

---

## Config Wiring

`config/glasshouse_sso.php` → `per_module_secrets` now contains an active SIONA
entry (previously only commented examples existed):

```php
'per_module_secrets' => [
    // 'glasspanel' => env('GLASSPORTAL_MODULE_SECRET_GLASSPANEL', ''),
    // 'aria'       => env('GLASSPORTAL_MODULE_SECRET_ARIA', ''),
    'siona' => env('GLASSPORTAL_MODULE_SECRET_SIONA', ''),
],
```

This is the single source of truth for SIONA's secret. No code reads the env
var directly.

---

## Resolver Behavior

`ModuleSecretResolver` is unchanged — it already resolves per-module secrets
first. With SIONA wired in:

**Issuance** (`resolveForIssuance('siona')`):
1. `per_module_secrets['siona']` if non-empty → **dedicated SIONA secret**
2. active `key_registry` entry (if configured)
3. global `signing_secret`

**Verification** (`resolveForVerification('siona', $kid)`):
1. `per_module_secrets['siona']` if non-empty → **dedicated SIONA secret**
2. `key_registry[$kid]` (status-aware)
3. flat `keys[$kid]`
4. global `signing_secret`

`SignedLaunchTokenService::generate()` already omits the `kid` header for
per-module tokens (the `aud` claim — `siona` — unambiguously selects the
secret), so a SIONA token signed with the dedicated secret verifies cleanly via
the audience and **fails** against the global secret.

---

## Fallback Behavior

| `GLASSPORTAL_MODULE_SECRET_SIONA` | Effective behavior |
|---|---|
| Non-empty | SIONA tokens are signed/verified with the dedicated secret. Other modules are unaffected. |
| Empty / unset | `ModuleSecretResolver` falls back (active key_registry → global `signing_secret`). Existing behavior is preserved — no breakage. |

Other modules (`glasspanel`, `aria`, `dns`, …) continue to use their own
per-module secret if set, or the global secret otherwise — **unchanged**.

---

## Rotation Guidance

1. Generate a new high-entropy secret (e.g. `openssl rand -hex 32`).
2. Coordinate with SIONA: SIONA must verify GlassPortal-issued tokens with the
   same secret. Because signed-launch tokens are short-lived (default 60s, max
   300s), there is no long-lived token population to drain.
3. Set `GLASSPORTAL_MODULE_SECRET_SIONA` on the GlassPortal side and the
   matching value on the SIONA side, then deploy both.
4. During the brief cutover, in-flight tokens signed with the old secret will
   fail verification — acceptable given the sub-minute TTL. For zero-gap
   rotation, prefer the `key_registry` mechanism (kid-based) rather than the
   per-module secret; per-module secrets are single-value by design.
5. Verify with `php artisan glassportal:healthcheck` →
   `siona.per_module_secret` should report **dedicated**.

> Limitation: per-module secrets are a single value with no built-in overlap
> window. For rolling rotation with overlap, use `key_registry` + `active_kid`
> (Phase 15). The two mechanisms compose — `key_registry` is the issuance
> fallback when no per-module secret is set.

---

## Healthcheck Behavior

`php artisan glassportal:healthcheck` adds `siona.per_module_secret`
(section 7m). It reports **presence/absence and fallback mode only — never the
secret value**:

| Condition | Result |
|---|---|
| `GLASSPORTAL_MODULE_SECRET_SIONA` set | **pass** — "SIONA uses a dedicated per-module signing secret" |
| No dedicated secret, **no** active SIONA signed_launch/backchannel links | **warn** — not yet required (global fallback in effect) |
| No dedicated secret, active SIONA SSO links, global secret present | **warn** — using GLOBAL fallback; set the dedicated secret for isolation |
| No dedicated secret, active SIONA SSO links, **no** secret at all | **fail** — launches will fail (also flagged by `sso.keys_configured`) |

The warn-only paths keep the check non-blocking until SIONA signed_launch /
backchannel links actually exist, matching existing healthcheck strictness
conventions.

---

## Admin Visibility

The SIONA connector panel at `/admin/modules` shows a **Signed launch secret**
status badge (label only, never the value):

- `Dedicated SIONA signing secret configured` (dedicated)
- `Using global fallback secret` (fallback)
- `Missing signing secret` (no secret anywhere)

`ModulesController` derives this from `ModuleSecretResolver::hasPerModuleSecret()`
and fallback presence — it never reads or passes the secret value to the view.

---

## Tests

| Suite | File | Coverage |
|---|---|---|
| Unit | `tests/Unit/Sso/SionaModuleSecretWiringTest.php` | config wires env var (set + default-empty), resolver dedicated/fallback/absent, other modules unaffected, `.env.example` documents the variable with no value. |
| Feature | `tests/Feature/SionaPerModuleSecretTest.php` | SIONA token verifies with the SIONA secret; does **not** verify with the global secret only; falls back when empty; other modules' tokens unaffected; healthcheck dedicated/fallback/fail + never prints the secret; admin labels + secret never rendered. |

Existing `tests/Unit/Sso/ModuleSecretResolverTest.php` continues to pass
unchanged (resolver logic untouched).

Run: `php artisan test` → **483 passed**.

---

## Known Limitations / TODOs

- **Single-value secret, no overlap window** — per-module secrets don't support
  rolling rotation with two valid secrets at once. Use `key_registry` for
  zero-gap rotation.
- **SIONA-side coordination is out of band** — GlassPortal cannot verify that
  SIONA holds the matching secret; the healthcheck only confirms the GlassPortal
  side. (SIONA repo is intentionally untouched.)
- **No automatic provisioning of the secret** — Phase 20 tenant provisioning
  creates `signed_launch` links but does not generate/distribute a per-module
  secret; that remains an operator action.
