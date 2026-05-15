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

];
