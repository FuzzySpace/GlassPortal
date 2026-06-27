<?php

/*
|--------------------------------------------------------------------------
| Provisioning Driver Registry (Phase 26)
|--------------------------------------------------------------------------
|
| A METADATA-ONLY registry of provisioning drivers that a future driver
| execution layer (Phase 27+) will consume. In Phase 26 NOTHING here executes:
| no driver makes infrastructure calls. The registry only labels which driver
| a provisioning request targets.
|
| `executable` is intent metadata, not a capability. Even `manual` does not
| touch infrastructure in this phase — it denotes an operator-performed action.
|
*/

return [

    'default_driver' => env('PROVISIONING_DEFAULT_DRIVER', 'manual'),

    'drivers' => [
        'manual' => [
            'label'       => 'Manual',
            'executable'  => true,
            'description' => 'Operator performs the action by hand; engine tracks state only.',
        ],
        'siona' => [
            'label'       => 'SIONA',
            'executable'  => false,
            'description' => 'AI sales module tenant provisioning (driver execution: future phase).',
        ],
        'glasspanel' => [
            'label'       => 'GlassPanel / GamePanel',
            'executable'  => false,
            'description' => 'Game/server panel provisioning (driver execution: future phase).',
        ],
        'webhosting' => [
            'label'       => 'Web Hosting',
            'executable'  => false,
            'description' => 'Web hosting provisioning (driver execution: future phase).',
        ],
        'dns' => [
            'label'       => 'DNS',
            'executable'  => false,
            'description' => 'DNS zone/record provisioning (driver execution: future phase).',
        ],
        'mail' => [
            'label'       => 'Mail',
            'executable'  => false,
            'description' => 'Mailbox provisioning (driver execution: future phase).',
        ],
        'netbox' => [
            'label'       => 'NetBox',
            'executable'  => false,
            'description' => 'IPAM/DCIM records (driver execution: future phase).',
        ],
    ],

];
