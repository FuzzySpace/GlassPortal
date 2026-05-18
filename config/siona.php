<?php

/*
|--------------------------------------------------------------------------
| SIONA Connector Configuration
|--------------------------------------------------------------------------
|
| SIONA (Sales Intelligence & Outreach Navigation Assistant) is an external
| AI sales module. GlassPortal acts as a registry and launch bridge only —
| SIONA source code never lives here.
|
| Security note: SIONA_API_TOKEN must NEVER appear in logs, responses,
| views, or exceptions. The token field is server-side only.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Switch
    |--------------------------------------------------------------------------
    |
    | When false (the default), all SIONA health probes are skipped and the
    | health endpoint returns status=unconfigured with HTTP 200. Non-blocking.
    |
    */
    'enabled' => (bool) env('SIONA_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the running SIONA service. Omit trailing slash.
    | Example: https://siona.internal.glasshouse.example
    |
    */
    'api_url' => env('SIONA_API_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | Bearer token for authenticating requests to SIONA's API.
    | Set in environment — never hardcode.
    |
    */
    'api_token' => env('SIONA_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Launch URL
    |--------------------------------------------------------------------------
    |
    | Customer-facing URL for launching the SIONA module UI.
    | Used as the external_url for standalone or signed_launch module links.
    | Leave empty to require a per-org external_url on the module link.
    |
    */
    'launch_url' => env('SIONA_LAUNCH_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('SIONA_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | TLS Verification
    |--------------------------------------------------------------------------
    |
    | Set to false only in local development environments.
    | Always true in production.
    |
    */
    'verify_tls' => filter_var(env('SIONA_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),

];
