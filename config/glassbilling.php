<?php

/*
|--------------------------------------------------------------------------
| GlassBilling Connector Configuration
|--------------------------------------------------------------------------
|
| Canonical config for the GlassBilling integration. All values are
| environment-driven. No credentials committed to source.
|
| Env vars:
|   GLASSBILLING_BASE_URL   — base URL of the GlassBilling API instance
|   GLASSBILLING_API_TOKEN  — Bearer token for authenticated requests
|   GLASSBILLING_TIMEOUT    — HTTP request timeout in seconds (default 8)
|   GLASSBILLING_VERIFY_TLS — verify TLS certificates (disable only in dev)
|
*/

return [

    'base_url'   => env('GLASSBILLING_BASE_URL', ''),

    'token'      => env('GLASSBILLING_API_TOKEN', ''),

    'timeout'    => (int) env('GLASSBILLING_TIMEOUT', 8),

    'verify_tls' => filter_var(env('GLASSBILLING_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),

];
