<?php

/*
|--------------------------------------------------------------------------
| Glasshouse Ecosystem Module Configuration
|--------------------------------------------------------------------------
|
| Phase 3–6: module registry with enabled flags, display names, health
| endpoints, and env-driven credentials. No hardcoded URLs or tokens.
|
| Each connector module (under 'modules') has:
|   enabled         — bool, controls whether the module is active
|   display_name    — human label for the UI
|   base_url        — env-driven; empty string = not configured
|   token           — env-driven API token
|   health_endpoint — relative path to call for health check
|   notes           — short human description of what this module owns
|
| Phase 6 adds 'launch_modules': the customer-facing module registry used
| by ModuleLaunchService. Keys align with organization_module_links.module_key.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Ecosystem connector modules (system-level registry)
    |--------------------------------------------------------------------------
    | These are the underlying service integrations. Each has health checks,
    | API credentials, and connector scaffolding.
    */
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

        // Phase 6: logical module keys surfaced to customers
        // These map to underlying connectors above or standalone services.

        'dns' => [
            'enabled'         => (bool) env('POWERDNS_ENABLED', false),
            'display_name'    => 'DNS',
            'base_url'        => env('POWERDNS_API_URL', ''),
            'token'           => env('POWERDNS_API_KEY', ''),
            'timeout'         => (int) env('POWERDNS_TIMEOUT', 5),
            'health_endpoint' => '/api/v1/servers',
            'notes'           => 'DNS zone and record management (PowerDNS backend).',
        ],

        'mail' => [
            'enabled'         => (bool) env('MAILCOW_ENABLED', false),
            'display_name'    => 'Mail',
            'base_url'        => env('MAILCOW_API_URL', ''),
            'token'           => env('MAILCOW_API_KEY', ''),
            'timeout'         => (int) env('MAILCOW_TIMEOUT', 5),
            'health_endpoint' => '/api/v1/get/status/containers',
            'notes'           => 'Mailbox and alias management (Mailcow backend).',
        ],

        'support' => [
            'enabled'         => env('SUPPORT_INBOX_PROVIDER', 'internal') !== 'internal',
            'display_name'    => 'Support',
            'base_url'        => env('SUPPORT_INBOX_HOST', ''),
            'token'           => '',
            'timeout'         => 5,
            'health_endpoint' => '',
            'notes'           => 'Support ticketing. Provider set via SUPPORT_INBOX_PROVIDER.',
        ],

        'infrastructure' => [
            'enabled'         => (bool) env('PROXMOX_ENABLED', false),
            'display_name'    => 'Infrastructure',
            'base_url'        => env('PROXMOX_API_URL', ''),
            'token'           => env('PROXMOX_API_TOKEN', ''),
            'timeout'         => (int) env('PROXMOX_TIMEOUT', 5),
            'health_endpoint' => '/api2/json/version',
            'notes'           => 'VM / VPS / container infrastructure (Proxmox backend).',
        ],

        // Phase 18: SIONA — external AI sales module
        // GlassPortal owns registry, health check, and launch bridge only.
        // SIONA source code lives in its own repository.
        'siona' => [
            'enabled'         => (bool) env('SIONA_ENABLED', false),
            'display_name'    => 'SIONA',
            'full_name'       => 'Sales Intelligence & Outreach Navigation Assistant',
            'category'        => 'ai_sales',
            'base_url'        => env('SIONA_API_URL', ''),
            'token'           => env('SIONA_API_TOKEN', ''),
            'timeout'         => (int) env('SIONA_TIMEOUT', 5),
            'health_endpoint' => '/api/health',
            'notes'           => 'AI-assisted ICP validation, prospect intelligence, outreach workflow, and sales pipeline generation. Configure SIONA_API_URL and SIONA_API_TOKEN to enable live health probing.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 6: Customer-facing launch module registry
    |--------------------------------------------------------------------------
    | Used by ModuleLaunchService to build the customer module launchpad.
    | Keys must match organization_module_links.module_key values.
    | Credentials are never included here — only display metadata.
    */
    'launch_modules' => [

        'glassbilling' => [
            'display_name' => 'Billing',
            'description'  => 'View invoices, subscriptions, and payment history.',
            'icon'         => '◈',
        ],

        'glasspanel' => [
            'display_name' => 'Game Panel',
            'description'  => 'Start, stop, and manage your game servers.',
            'icon'         => '▶',
        ],

        'aria' => [
            'display_name' => 'Aria (AI)',
            'description'  => 'AI-powered support and operations assistant.',
            'icon'         => '◎',
        ],

        'dns' => [
            'display_name' => 'DNS',
            'description'  => 'Manage DNS zones and records for your domains.',
            'icon'         => '⊙',
        ],

        'mail' => [
            'display_name' => 'Mail',
            'description'  => 'Mailboxes and aliases for your domains.',
            'icon'         => '✉',
        ],

        'support' => [
            'display_name' => 'Support',
            'description'  => 'Submit and track support tickets.',
            'icon'         => '◉',
        ],

        'infrastructure' => [
            'display_name' => 'Infrastructure',
            'description'  => 'VPS, VM, and container management.',
            'icon'         => '⊞',
        ],

        // Phase 18/19: SIONA customer-facing launchpad entry.
        // Appears only when an organization_module_link with module_key=siona exists.
        'siona' => [
            'display_name'         => 'SIONA',
            'description'          => 'AI-assisted sales intelligence, ICP validation, prospect research, and outreach workflow.',
            'icon'                 => '◆',
            'supported_auth_modes' => ['standalone', 'signed_launch', 'backchannel_launch'],
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
