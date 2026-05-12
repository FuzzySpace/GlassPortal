<?php
declare(strict_types=1);

return [
    // Unique identifier for this worker instance
    'worker_id' => gethostname() ?: 'worker-unknown',

    // Polling / locking
    'poll_interval_seconds' => 2,
    'lock_timeout_seconds' => 900,

    // Safety rails
    'max_targets_per_run' => 50,
    'allow_custom_commands' => false,

    // Execution mode
    // 'wsl'   = Windows PHP calling Ansible inside WSL
    // 'native' = Linux server running ansible-playbook directly
    'ansible_mode' => 'wsl',

    // Binary name/path
    // WSL mode still uses this inside the WSL shell
    'ansible_playbook_bin' => 'ansible-playbook',

    // Inventory path
    'inventory_dir' => __DIR__ . '/runtime/inventory',
];