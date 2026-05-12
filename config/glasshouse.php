<?php

/*
|--------------------------------------------------------------------------
| Glasshouse Ecosystem Module Configuration
|--------------------------------------------------------------------------
|
| Phase 2: stubs only. All connector URLs and tokens are environment-driven.
| No live integrations yet. Each module section will grow into a full
| connector service class in Phase 3+.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | GlassBilling
    |--------------------------------------------------------------------------
    | Billing system of record: invoices, subscriptions, product catalog,
    | service lifecycle state, and credential brokerage.
    */
    'glassbilling' => [
        'url'   => env('GLASSBILLING_API_URL', ''),
        'token' => env('GLASSBILLING_API_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | GlassPanel
    |--------------------------------------------------------------------------
    | Game/server runtime: start/stop/console, Pterodactyl migration,
    | future eggless runtime, idle/suspend/wake.
    */
    'glasspanel' => [
        'url'   => env('GLASSPANEL_API_URL', ''),
        'token' => env('GLASSPANEL_API_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aria (GlassAI)
    |--------------------------------------------------------------------------
    | Internal AI ops assistant + customer-facing support workflows.
    | CPU-only baseline; GPU burst mode for expensive workloads.
    | All infrastructure-changing actions require explicit approval.
    */
    'aria' => [
        'url'   => env('ARIA_API_URL', ''),
        'token' => env('ARIA_API_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxmox
    |--------------------------------------------------------------------------
    | Infrastructure inventory: VPS/CT/VM inventory, hypervisor provisioning.
    | Credentials must NOT be stored in the portal DB. Obtain short-lived
    | tokens via GlassBilling or a dedicated secret broker.
    */
    'proxmox' => [
        'url'   => env('PROXMOX_API_URL', ''),
        'token' => env('PROXMOX_API_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | PowerDNS
    |--------------------------------------------------------------------------
    | DNS zone/record lifecycle. Portal surfaces read/write for customer
    | zones; authoritative logic stays in PowerDNS.
    */
    'powerdns' => [
        'url' => env('POWERDNS_API_URL', ''),
        'key' => env('POWERDNS_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailcow
    |--------------------------------------------------------------------------
    | Paid-domain mailbox/alias services + abuse monitoring.
    | Not a free public email platform.
    */
    'mailcow' => [
        'url' => env('MAILCOW_API_URL', ''),
        'key' => env('MAILCOW_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support Inbox
    |--------------------------------------------------------------------------
    | Staff-side centralized communications. Provider is pluggable.
    | Examples: imap, helpscout, freshdesk, zammad, internal.
    */
    'support_inbox' => [
        'provider' => env('SUPPORT_INBOX_PROVIDER', 'internal'),
        'host'     => env('SUPPORT_INBOX_HOST', ''),
        'username' => env('SUPPORT_INBOX_USERNAME', ''),
        'password' => env('SUPPORT_INBOX_PASSWORD', ''),
    ],

];
