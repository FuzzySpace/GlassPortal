<?php
declare(strict_types=1);

/**
 * Automation Worker Configuration
 * Glasshouse NOC Provisioning Portal
 */

return [
    // Unique identifier for this worker process (useful when running multiple workers)
    'worker_id'              => gethostname() . '-' . getmypid(),

    // Seconds between polls for new queued runs
    'poll_interval_seconds'  => 5,

    // Seconds before a locked run is considered stale and can be re-claimed
    'lock_timeout_seconds'   => 300,

    // Maximum number of target servers per single run
    'max_targets_per_run'    => 50,

    // Directory for runtime PID files and temp data
    'runtime_dir'            => __DIR__ . '/runtime',

    // Directory for ephemeral Ansible inventory files
    'inventory_dir'          => __DIR__ . '/runtime/inventory',

    // Path to the ansible-playbook binary (override if not in PATH)
    'ansible_playbook_bin'   => 'ansible-playbook',

    // Path to the ansible binary (for ad-hoc commands)
    'ansible_bin'            => 'ansible',

    // Default extra options appended to every ansible-playbook run
    'ansible_extra_opts'     => '',
];
