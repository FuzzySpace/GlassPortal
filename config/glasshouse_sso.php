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
    */
    'key_id' => env('GLASSPORTAL_SIGNED_LAUNCH_KEY_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Key Map — Phase 9
    |--------------------------------------------------------------------------
    |
    | Maps key IDs to signing secrets for key rotation support.
    | Add entries here (or override in environment-specific config files).
    | Example:
    |   'keys' => [
    |       'v1' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V1', ''),
    |       'v2' => env('GLASSPORTAL_SIGNED_LAUNCH_SECRET_V2', ''),
    |   ],
    |
    | During rotation: set key_id to the new kid, keep the old kid in keys[].
    | Old tokens (from the previous kid) will verify against their key in this map.
    |
    */
    'keys' => [],

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

    ],

];
