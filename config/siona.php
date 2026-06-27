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
| views, tests, or exceptions. The token field is used server-side only
| (bearer auth on health probe requests).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Switch
    |--------------------------------------------------------------------------
    |
    | When false (the default), all SIONA health probes are skipped and the
    | connector health endpoint returns status=unconfigured with HTTP 200.
    | Non-blocking by design — unconfigured SIONA never breaks GlassPortal.
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
    | Set via environment only — never hardcode.
    | This value is NEVER included in any HTTP response or log entry.
    |
    */
    'api_token' => env('SIONA_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Launch URL
    |--------------------------------------------------------------------------
    |
    | Customer-facing URL for launching the SIONA module UI.
    | Used as the external_url fallback for standalone module links.
    | Individual organization_module_links may override this per-org.
    | Leave empty to require a per-org external_url on the module link.
    |
    */
    'launch_url' => env('SIONA_LAUNCH_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Health Check Path
    |--------------------------------------------------------------------------
    |
    | Relative path appended to api_url for health probing.
    | The connector health controller calls: {api_url}{health_path}
    |
    */
    'health_path' => '/api/health',

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
    | Must be true in staging and production.
    |
    */
    'verify_tls' => filter_var(env('SIONA_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Tenant Provisioning (Phase 20)
    |--------------------------------------------------------------------------
    |
    | GlassPortal can provision a SIONA workspace/tenant for an organization
    | over the authenticated server-to-server back-channel (using api_url +
    | api_token). This is an admin-only, opt-in action.
    |
    |   enabled           — master switch for the provisioning feature. When
    |                       false, the admin action returns "unconfigured" and
    |                       no outbound call is made.
    |   path              — relative path POSTed to for tenant creation:
    |                       {api_url}{path}
    |   default_auth_mode — auth_mode assigned to the organization_module_link
    |                       created on successful provisioning.
    |
    | No secrets live here — api_token is read from the SIONA_API_TOKEN env
    | only (see 'api_token' above) and is NEVER logged or returned.
    |
    */
    'provisioning' => [
        'enabled'           => filter_var(env('SIONA_PROVISIONING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'path'              => env('SIONA_PROVISIONING_PATH', '/api/tenants'),
        'default_auth_mode' => env('SIONA_PROVISIONING_AUTH_MODE', 'signed_launch'),
    ],

];
