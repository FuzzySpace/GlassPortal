<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/auth/bootstrap.php';
require_once __DIR__ . '/database/connection.php';

$u = current_user();
$role = $u['role'] ?? 'operator';
$canCustomScript = in_array($role, ['owner', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$csrf = (string)($_POST['csrf'] ?? '');
if (!csrf_verify($csrf)) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$targetMode = (string)($_POST['target_mode'] ?? 'single');
if (!in_array($targetMode, ['single', 'multi'], true)) {
    $targetMode = 'single';
}
$singleNodeId = (int)($_POST['single_node_id'] ?? 0);

$siteGroups = $_POST['site_groups'] ?? [];
$providerGroups = $_POST['provider_groups'] ?? [];
$nodeIds = $_POST['node_ids'] ?? [];

if (!is_array($siteGroups)) $siteGroups = [];
if (!is_array($providerGroups)) $providerGroups = [];
if (!is_array($nodeIds)) $nodeIds = [];

$scriptId = (string)($_POST['script_id'] ?? '');
$customCmd = trim((string)($_POST['custom_command'] ?? ''));
$saveCustom = (int)($_POST['save_custom'] ?? 0) === 1;
$customName = trim((string)($_POST['custom_name'] ?? ''));
$customDesc = trim((string)($_POST['custom_desc'] ?? ''));

$runLabel = trim((string)($_POST['run_label'] ?? ''));
$initiatedVia = trim((string)($_POST['initiated_via'] ?? 'manual'));
$allowedInitiatedVia = ['manual', 'web', 'api'];
if (!in_array($initiatedVia, $allowedInitiatedVia, true)) {
    $initiatedVia = 'manual';
}

$userId = (int)($u['id'] ?? 0);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Validate targets
$targets = [
    'mode' => $targetMode,
    'single_node_id' => null,
    'site_groups' => array_values(array_filter(array_map('strval', $siteGroups))),
    'provider_groups' => array_values(array_filter(array_map('strval', $providerGroups))),
    'node_ids' => array_values(array_filter(array_map('intval', $nodeIds))),
];

if ($targetMode === 'single') {
    if ($singleNodeId <= 0) {
        http_response_code(400);
        exit('Single node required');
    }
    $targets['single_node_id'] = $singleNodeId;
} else {
    if (
        count($targets['site_groups']) === 0 &&
        count($targets['provider_groups']) === 0 &&
        count($targets['node_ids']) === 0
    ) {
        http_response_code(400);
        exit('Select at least one group or node for multi-run');
    }
}

// Resolve script
$resolved = [
    'script_source' => null,
    'script_type' => null,
    'script_id' => null,
    'command' => null,
    'saved_new_script_id' => null,
];

try {
    $pdo->beginTransaction();

    if ($scriptId === '__custom__') {
        if (!$canCustomScript) {
            throw new RuntimeException('Custom scripts restricted to owner/admin');
        }
        if ($customCmd === '') {
            throw new RuntimeException('Custom script cannot be empty');
        }

        $resolved['script_source'] = 'custom';
        $resolved['script_type'] = 'adhoc';
        $resolved['command'] = $customCmd;

        if ($saveCustom) {
            if ($customName === '') {
                throw new RuntimeException('Provide a name to save the script');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO ansible_scripts (name, description, script_type, command, is_active, created_by_user_id)
                 VALUES (?, ?, 'adhoc', ?, 1, ?)"
            );
            $stmt->execute([$customName, $customDesc ?: null, $customCmd, $userId ?: null]);
            $resolved['saved_new_script_id'] = (int)$pdo->lastInsertId();
        }
    } else {
        $sid = (int)$scriptId;
        if ($sid <= 0) {
            throw new RuntimeException('Select a saved script (or custom)');
        }

        $stmt = $pdo->prepare("SELECT id, command, script_type FROM ansible_scripts WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$sid]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Saved script not found or inactive');
        }

        $resolved['script_source'] = 'saved';
        $resolved['script_id'] = (int)$row['id'];
        $resolved['command'] = (string)$row['command'];
        $resolved['script_type'] = (string)$row['script_type'];
    }

    // Ensure automation exists
    $stmt = $pdo->prepare("SELECT id FROM automations WHERE name = 'Run Ansible' LIMIT 1");
    $stmt->execute();
    $automationId = $stmt->fetchColumn();

    if (!$automationId) {
        $stmt = $pdo->prepare(
            "INSERT INTO automations (name, description, enabled, trigger_type)
             VALUES ('Run Ansible', 'Executes selected Ansible script against selected targets', 1, 'manual')"
        );
        $stmt->execute();
        $automationId = (int)$pdo->lastInsertId();
    } else {
        $automationId = (int)$automationId;
    }

    $meta = [
        'label' => $runLabel ?: null,
        'targets' => $targets,
        'script' => $resolved,
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO automation_runs
         (automation_id, status, initiated_by_user_id, initiated_via, meta, created_at)
         VALUES (?, 'queued', ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $automationId,
        $userId ?: null,
        $initiatedVia,
        json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    $runId = (int)$pdo->lastInsertId();

    // Run logs
    $logStmt = $pdo->prepare(
        "INSERT INTO automation_run_logs (run_id, level, message, context)
         VALUES (?, ?, ?, ?)"
    );

    $logStmt->execute([
        $runId,
        'info',
        'Run created (queued)',
        json_encode(['meta' => $meta], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $logStmt->execute([
        $runId,
        'info',
        'Targets resolved',
        json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $logStmt->execute([
    $runId,
    'info',
    'Script selected',
    json_encode([
        'source' => $resolved['script_source'],
        'script_type' => $resolved['script_type'],
        'script_id' => $resolved['script_id'],
        'saved_new_script_id' => $resolved['saved_new_script_id'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
]);

    // Audit log
    $aStmt = $pdo->prepare(
        "INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, ip, user_agent, meta)
         VALUES (?, 'automation.run.create', 'automation_run', ?, ?, ?, ?)"
    );
    $aStmt->execute([
        $userId ?: null,
        (string)$runId,
        $ip,
        $ua,
        json_encode([
            'automation_id' => $automationId,
            'run_id' => $runId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $pdo->commit();

    header("Location: /run.php?id=" . $runId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    exit('Failed to create automation run: ' . $e->getMessage());
}