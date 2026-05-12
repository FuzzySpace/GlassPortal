<?php

/*
|--------------------------------------------------------------------------
| Glasshouse Ecosystem Module Configuration
|--------------------------------------------------------------------------
|
| Phase 3: module registry with enabled flags, display names, health
| endpoints, and env-driven credentials. No hardcoded URLs or tokens.
|
| Each module has:
|   enabled         — bool, controls whether the module is active
|   display_name    — human label for the UI
|   base_url        — env-driven; empty string = not configured
|   token           — env-driven API token
|   health_endpoint — relative path to call for health check
|   notes           — short human description of what this module owns
|
*/

return [

    'modules' => [

        'glassbilling' => [
            'enabled'         => (bool) env('GLASSBILLING_ENABLED', false),
            'display_name'    => 'GlassBilling',
            'base_url'        => env('GLASSBILLING_BASE_URL', ''),
            'token'           => env('GLASSBILLING_API_TOKEN', ''),
            'timeout'         => (int) env('GLASSBILLING_TIMEOUT', 8),
            'health_endpoint' => '/api/health',
            'notes'           => 'Billing SoR: subscriptions, invoices, products, lifecycle, credential broker.',
        ],

        'glasspanel' => [
            'enabled'         => (bool) env('GLASSPANEL_ENABLED', false),
            'display_name'    => 'GlassPanel',
            'base_url'        => env('GLASSPANEL_API_URL', ''),
            'token'           => env('GLASSPANEL_API_TOKEN', ''),
            'timeout'         => (int) env('GLASSPANEL_TIMEOUT', 5),
            'health_endpoint' => '/api/health',
            'notes'           => 'Game/server runtime: start/stop/console, Pterodactyl migration, future eggless runtime.',
        ],

        'aria' => [
            'enabled'         => (bool) env('ARIA_ENABLED', false),
            'display_name'    => 'Aria (GlassAI)',
            'base_url'        => env('ARIA_API_URL', ''),
            'token'           => env('ARIA_API_TOKEN', ''),
            'timeout'         => (int) env('ARIA_TIMEOUT', 10),
            'health_endpoint' => '/api/health',
            'notes'           => 'Internal AI ops + customer support. CPU baseline; GPU burst mode. Disclosure required.',
        ],

        'proxmox' => [
            'enabled'         => (bool) env('PROXMOX_ENABLED', false),
            'display_name'    => 'Proxmox',
            'base_url'        => env('PROXMOX_API_URL', ''),
            'token'           => env('PROXMOX_API_TOKEN', ''),
            'timeout'         => (int) env('PROXMOX_TIMEOUT', 5),
            'health_endpoint' => '/api2/json/version',
            'notes'           => 'Hypervisor inventory: VPS/CT/VM. No raw tokens in portal DB.',
        ],

        'powerdns' => [
            'enabled'         => (bool) env('POWERDNS_ENABLED', false),
            'display_name'    => 'PowerDNS',
            'base_url'        => env('POWERDNS_API_URL', ''),
            'token'           => env('POWERDNS_API_KEY', ''),
            'timeout'         => (int) env('POWERDNS_TIMEOUT', 5),
            'health_endpoint' => '/api/v1/servers',
            'notes'           => 'DNS zone/record lifecycle.',
        ],

        'mailcow' => [
            'enabled'         => (bool) env('MAILCOW_ENABLED', false),
            'display_name'    => 'Mailcow',
            'base_url'        => env('MAILCOW_API_URL', ''),
            'token'           => env('MAILCOW_API_KEY', ''),
            'timeout'         => (int) env('MAILCOW_TIMEOUT', 5),
            'health_endpoint' => '/api/v1/get/status/containers',
            'notes'           => 'Paid-domain mailbox/alias + abuse monitoring. Not a free public email platform.',
        ],

        'pterodactyl' => [
            'enabled'         => (bool) env('PTERODACTYL_ENABLED', false),
            'display_name'    => 'Pterodactyl',
            'base_url'        => env('PTERODACTYL_API_URL', ''),
            'token'           => env('PTERODACTYL_API_TOKEN', ''),
            'timeout'         => (int) env('PTERODACTYL_TIMEOUT', 5),
            'health_endpoint' => '/api/client',
            'notes'           => 'Legacy game panel. Migration target -> GlassPanel.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | GlassBilling connector shortcut
    |--------------------------------------------------------------------------
    | GlassBillingClient reads directly from config/glassbilling.php.
    | This alias keeps module-registry lookups consistent.
    */
    'glassbilling' => [
        'base_url'   => env('GLASSBILLING_BASE_URL', ''),
        'token'      => env('GLASSBILLING_API_TOKEN', ''),
        'timeout'    => (int) env('GLASSBILLING_TIMEOUT', 8),
        'verify_tls' => filter_var(env('GLASSBILLING_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support inbox
    |--------------------------------------------------------------------------
    */
    'support_inbox' => [
        'provider' => env('SUPPORT_INBOX_PROVIDER', 'internal'),
        'host'     => env('SUPPORT_INBOX_HOST', ''),
        'username' => env('SUPPORT_INBOX_USERNAME', ''),
        'password' => env('SUPPORT_INBOX_PASSWORD', ''),
    ],

];
