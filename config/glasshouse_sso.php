<?php

/**
 * Signed Module Launch (SSO Phase 8) configuration.
 *
 * Security note: signing_secret must be a long, random secret stored in the
 * environment. It must NEVER appear in source code, version control, logs,
 * audit records, or browser-visible responses.
 *
 * Generating a strong secret:
 *   php artisan tinker --execute="echo base64_encode(random_bytes(64));"
 *   OR: openssl rand -base64 64
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Signing Secret
    |--------------------------------------------------------------------------
    |
    | HMAC-SHA256 key used to sign and verify launch tokens.
    | Set GLASSPORTAL_SIGNED_LAUNCH_SECRET in .env — never hardcode here.
    | Required whenever any organization_module_links row uses auth_mode=signed_launch.
    |
    */
    'signing_secret' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Issuer
    |--------------------------------------------------------------------------
    |
    | Included as the "iss" claim in every signed payload.
    | Modules use this to verify they are accepting tokens from the right portal.
    |
    */
    'issuer' => env('GLASSPORTAL_SSO_ISSUER', 'glassportal'),

    /*
    |--------------------------------------------------------------------------
    | Token TTL
    |--------------------------------------------------------------------------
    |
    | Tokens expire after default_ttl_seconds (default 60s).
    | max_ttl_seconds caps requests that attempt to specify a longer TTL.
    | clock_skew_seconds gives leeway for clock drift between server and module.
    |
    */
    'default_ttl_seconds' => (int) env('GLASSPORTAL_SIGNED_LAUNCH_TTL', 60),
    'max_ttl_seconds'     => 300,
    'clock_skew_seconds'  => 30,

    /*
    |--------------------------------------------------------------------------
    | Algorithms
    |--------------------------------------------------------------------------
    |
    | Signing algorithm used. HS256 (HMAC-SHA256) is the only supported value.
    | Listed as an array for forward compatibility.
    |
    */
    'allowed_algorithms' => ['HS256'],

    /*
    |--------------------------------------------------------------------------
    | Nonce / JTI Replay Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long issued JTIs are tracked in the cache for replay detection.
    | Should be >= max_ttl_seconds + clock_skew_seconds + buffer.
    |
    */
    'nonce_cache_ttl_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | Key ID (KID) — Phase 9
    |--------------------------------------------------------------------------
    |
    | When set, the token header includes "kid": key_id so the receiving module
    | can select the correct verification key when multiple keys are in rotation.
    |
    | If empty, tokens are issued without a kid claim (single-secret backward
    | compatibility mode). On verification, tokens without a kid are verified
    | against signing_secret regardless of the keys array.
    |
    | Phase 15 note: prefer active_kid + key_registry over this flat setting.
    | key_id is preserved for backward compatibility.
    |
    */
    'key_id' => env('GLASSPORTAL_SIGNED_LAUNCH_KEY_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Key Map — Phase 9 (flat, legacy)
    |--------------------------------------------------------------------------
    |
    | Maps key IDs to signing secrets for key rotation support.
    | Phase 15 introduces key_registry (below) which supersedes this for new
    | deployments. Entries here are still consulted as a fallback during
    | verification when key_registry does not contain the requested kid.
    |
    | Example:
    |   'keys' => [
    |       'v1' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1', ''),
    |       'v2' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2', ''),
    |   ],
    |
    */
    'keys' => [],

    /*
    |--------------------------------------------------------------------------
    | Active Key ID — Phase 15
    |--------------------------------------------------------------------------
    |
    | The kid that should be used to SIGN new tokens. Must match an entry in
    | key_registry with status "active". Leave empty to fall back to the legacy
    | key_id / signing_secret pair.
    |
    */
    'active_kid' => env('GLASSPORTAL_SIGNED_LAUNCH_ACTIVE_KID', ''),

    /*
    |--------------------------------------------------------------------------
    | Key Registry — Phase 15
    |--------------------------------------------------------------------------
    |
    | Rich key map with lifecycle metadata. Each entry has:
    |   secret      — HMAC-SHA256 signing secret (from env, never hardcoded)
    |   algorithm   — signing algorithm (currently only "HS256" is supported)
    |   status      — "active" | "previous" | "disabled"
    |                   active   : used for new token issuance; valid for verify
    |                   previous : no longer used for issuance; still valid for verify
    |                   disabled : rejected on verify (token tampering guard)
    |   created_at  — ISO 8601 date (informational)
    |   rotated_at  — ISO 8601 date when this key stopped being active (informational)
    |
    | JWKS endpoint exposes kid, alg, status, and iss — NEVER the raw secret.
    | Disabled keys are excluded from the JWKS response entirely.
    |
    | Example (add to environment-specific config or override in .env):
    |   'key_registry' => [
    |       'v1' => [
    |           'secret'     => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1', ''),
    |           'algorithm'  => 'HS256',
    |           'status'     => 'previous',
    |           'created_at' => '2025-01-01',
    |           'rotated_at' => '2026-01-01',
    |       ],
    |       'v2' => [
    |           'secret'     => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2', ''),
    |           'algorithm'  => 'HS256',
    |           'status'     => 'active',
    |           'created_at' => '2026-01-01',
    |       ],
    |   ],
    |
    */
    'key_registry' => [],

    /*
    |--------------------------------------------------------------------------
    | Per-Module Signing Secrets — Phase 12
    |--------------------------------------------------------------------------
    |
    | Maps module keys to dedicated HMAC signing secrets for stronger
    | isolation between modules. When a per-module secret is set for a module,
    | it takes priority over the global signing_secret for both issuance and
    | verification.
    |
    | Priority for issuance:  per_module_secrets[moduleKey] → signing_secret
    | Priority for verify:    per_module_secrets[audience] → keys[kid] → signing_secret
    |
    | Example:
    |   'per_module_secrets' => [
    |       'glasspanel' => env('GLASSPORTAL_MODULE_SECRET_GLASSPANEL', ''),
    |       'aria'       => env('GLASSPORTAL_MODULE_SECRET_ARIA', ''),
    |   ],
    |
    */
    'per_module_secrets' => [
        // Add entries for each module that should use its own signing secret.
        // 'glasspanel' => env('GLASSPORTAL_MODULE_SECRET_GLASSPANEL', ''),
        // 'aria'       => env('GLASSPORTAL_MODULE_SECRET_ARIA', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dev SSO Consume Route — Phase 9
    |--------------------------------------------------------------------------
    |
    | Enables POST /_dev/sso/consume/{moduleKey} outside of local/testing envs.
    | The route is always available in local and testing environments.
    | Set GLASSPORTAL_ENABLE_DEV_SSO_CONSUME=true in staging to enable it there.
    | Never enable in production.
    |
    */
    'enable_dev_sso_consume' => (bool) env('GLASSPORTAL_ENABLE_DEV_SSO_CONSUME', false),

    /*
    |--------------------------------------------------------------------------
    | Portal Launch Rate Limit — Phase 9
    |--------------------------------------------------------------------------
    |
    | Maximum launch attempts per user per module link per minute.
    | Exceeding this limit records a rate_limited audit event and redirects
    | the user back to /portal/modules with an error message.
    |
    */
    'rate_limit_per_minute' => (int) env('GLASSPORTAL_LAUNCH_RATE_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | Back-Channel SSO Exchange — Phase 11
    |--------------------------------------------------------------------------
    |
    | When enabled, modules may use the back-channel exchange instead of the
    | browser-mediated signed launch handoff. GlassPortal issues a short-lived
    | one-time launch code that the module redeems server-to-server.
    |
    | The launch code NEVER appears in a URL — it is posted in a form body
    | to the module's redirect endpoint, which then calls GlassPortal's
    | /api/sso/backchannel/redeem/{moduleKey} to exchange it for identity data.
    |
    | Generating a strong secret (same as signed launch):
    |   openssl rand -base64 64
    |
    */
    'backchannel' => [

        // Master switch — must be explicitly enabled
        'enabled' => (bool) env('GLASSPORTAL_BACKCHANNEL_SSO_ENABLED', false),

        // One-time code lifetime in seconds (default 60, max 300)
        'code_ttl_seconds' => (int) env('GLASSPORTAL_BACKCHANNEL_CODE_TTL', 60),

        // How long used-code tombstones are retained for replay detection
        'replay_cache_ttl_seconds' => (int) env('GLASSPORTAL_BACKCHANNEL_REPLAY_TTL', 600),

        // When true: the module_key in the redeem request must exactly match the
        // module_key encoded in the code payload. Always true in production.
        'strict_module_match' => (bool) env('GLASSPORTAL_BACKCHANNEL_STRICT_MODULE', true),

        // mTLS client-certificate enforcement (Phase 12).
        // When require_mtls is true, the reverse proxy must verify the client
        // certificate and forward the result in the configured header.
        // Set GLASSPORTAL_BACKCHANNEL_REQUIRE_MTLS=true in production.
        'require_mtls'         => (bool)   env('GLASSPORTAL_BACKCHANNEL_REQUIRE_MTLS', false),
        'mtls_verified_header' => (string) env('GLASSPORTAL_BACKCHANNEL_MTLS_HEADER', 'X-Client-Cert-Verified'),
        'mtls_verified_value'  => (string) env('GLASSPORTAL_BACKCHANNEL_MTLS_VERIFIED_VALUE', 'SUCCESS'),

    ],

];
