<?php
declare(strict_types=1);

/**
 * Glasshouse Automation Worker (v1)
 * - Claims queued runs
 * - Resolves targets
 * - Executes ansible playbook/command
 * - Streams logs into automation_run_logs
 */

require_once __DIR__ . '/../auth/require.php'; // optional, remove if worker runs without web auth
require_once __DIR__ . '/../database/connection.php'; // provides $pdo

$config = require __DIR__ . '/worker_config.php';

$workerId = (string)$config['worker_id'];
$poll = (int)$config['poll_interval_seconds'];
$lockTimeout = (int)$config['lock_timeout_seconds'];
$maxTargets = (int)$config['max_targets_per_run'];

@mkdir($config['runtime_dir'], 0777, true);
@mkdir($config['inventory_dir'], 0777, true);

function now(): string { return date('Y-m-d H:i:s'); }

function log_run(PDO $pdo, int $runId, string $level, string $message, array $context = []): void {
  $stmt = $pdo->prepare(
    "INSERT INTO automation_run_logs (run_id, level, message, context, created_at)
     VALUES (?, ?, ?, ?, NOW())"
  );
  $stmt->execute([$runId, $level, $message, json_encode($context, JSON_UNESCAPED_SLASHES)]);
}

function mark_run_running(PDO $pdo, int $runId, string $workerId): void {
  $stmt = $pdo->prepare(
    "UPDATE automation_runs
     SET status='running', started_at=NOW()
     WHERE id=?"
  );
  $stmt->execute([$runId]);
  log_run($pdo, $runId, 'info', 'Run marked running', ['worker_id' => $workerId]);
}

function mark_run_done(PDO $pdo, int $runId, string $status, ?string $errorCode, ?string $errorMessage, int $durationMs): void {
  $stmt = $pdo->prepare(
    "UPDATE automation_runs
     SET status=?, finished_at=NOW(), duration_ms=?, error_code=?, error_message=?
     WHERE id=?"
  );
  $stmt->execute([$status, $durationMs, $errorCode, $errorMessage, $runId]);
}

function unlock_run(PDO $pdo, int $runId): void {
  $stmt = $pdo->prepare("UPDATE automation_runs SET locked_by=NULL, locked_at=NULL WHERE id=?");
  $stmt->execute([$runId]);
}

/**
 * Atomically claim a queued run (single-worker safe)
 */
function claim_run(PDO $pdo, string $workerId, int $lockTimeoutSeconds): ?array {
  // Reclaim stale locks (safety)
  $pdo->prepare(
    "UPDATE automation_runs
     SET locked_by=NULL, locked_at=NULL
     WHERE status='queued'
       AND locked_at IS NOT NULL
       AND locked_at < (NOW() - INTERVAL ? SECOND)"
  )->execute([$lockTimeoutSeconds]);

  // Find a candidate
  $row = $pdo->query(
    "SELECT id, meta
     FROM automation_runs
     WHERE status='queued'
       AND (locked_by IS NULL OR locked_by='')
     ORDER BY created_at ASC
     LIMIT 1"
  )->fetch(PDO::FETCH_ASSOC);

  if (!$row) return null;

  $runId = (int)$row['id'];

  // Claim it (atomic)
  $stmt = $pdo->prepare(
    "UPDATE automation_runs
     SET locked_by=?, locked_at=NOW()
     WHERE id=? AND status='queued' AND (locked_by IS NULL OR locked_by='')"
  );
  $stmt->execute([$workerId, $runId]);

  if ($stmt->rowCount() !== 1) return null;

  // Reload full run record
  $stmt = $pdo->prepare("SELECT * FROM automation_runs WHERE id=? LIMIT 1");
  $stmt->execute([$runId]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Resolve run targets into node rows.
 * Uses run.meta.targets structure created by automation_run_handler.php
 */
function resolve_targets(PDO $pdo, array $meta, int $maxTargets): array {
  $targets = $meta['targets'] ?? [];
  $mode = (string)($targets['mode'] ?? 'single');

  $nodeIds = [];
  $siteGroups = $targets['site_groups'] ?? [];
  $providerGroups = $targets['provider_groups'] ?? [];

  if ($mode === 'single') {
    $sid = (int)($targets['single_node_id'] ?? 0);
    if ($sid <= 0) throw new RuntimeException('single_node_id missing');
    $nodeIds[] = $sid;
  } else {
    foreach (($targets['node_ids'] ?? []) as $id) $nodeIds[] = (int)$id;
  }

  $where = [];
  $params = [];

  if (count($nodeIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
    $where[] = "id IN ($placeholders)";
    array_push($params, ...$nodeIds);
  }

  if (count($siteGroups) > 0) {
    $placeholders = implode(',', array_fill(0, count($siteGroups), '?'));
    $where[] = "site IN ($placeholders)";
    array_push($params, ...array_map('strval', $siteGroups));
  }

  if (count($providerGroups) > 0) {
    $placeholders = implode(',', array_fill(0, count($providerGroups), '?'));
    $where[] = "provider IN ($placeholders)";
    array_push($params, ...array_map('strval', $providerGroups));
  }

  if (!$where) throw new RuntimeException('No targets selected');

  $sql = "SELECT id, name, site, provider, status, mgmt_ip FROM nodes WHERE " . implode(" OR ", $where) . " ORDER BY site ASC, name ASC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Deduplicate by id
  $uniq = [];
  foreach ($rows as $r) $uniq[(int)$r['id']] = $r;
  $rows = array_values($uniq);

  if (count($rows) === 0) throw new RuntimeException('Target resolution returned zero nodes');
  if (count($rows) > $maxTargets) throw new RuntimeException("Target count exceeds limit ($maxTargets)");

  // Ensure mgmt_ip exists
  foreach ($rows as $r) {
    if (empty($r['mgmt_ip'])) {
      throw new RuntimeException("Node {$r['name']} missing mgmt_ip; cannot execute");
    }
  }

  return $rows;
}

/**
 * Build ephemeral INI inventory file for this run.
 */
function write_inventory(string $inventoryDir, int $runId, array $nodes): string {
  $path = rtrim($inventoryDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "run_$runId.ini";

  $lines = [];
  $lines[] = "[targets]";
  foreach ($nodes as $n) {
    // ansible host value = mgmt_ip
    $host = (string)$n['mgmt_ip'];
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string)$n['name']);
    $lines[] = "{$name} ansible_host={$host}";
  }
  $lines[] = "";

  file_put_contents($path, implode("\n", $lines));
  return $path;
}

/**
 * Execute ansible command and stream output to DB logs.
 * NOTE: This is v1. Later we’ll replace with a safer exec wrapper + redaction.
 */
function run_process(PDO $pdo, int $runId, array $cmd, array $env = []): int {
  log_run($pdo, $runId, 'info', 'Executing command', ['cmd' => $cmd]);

  $descriptorspec = [
    1 => ["pipe", "w"], // stdout
    2 => ["pipe", "w"], // stderr
  ];

  $procEnv = array_merge($_ENV, $env);
  $process = proc_open($cmd, $descriptorspec, $pipes, null, $procEnv);

  if (!is_resource($process)) {
    log_run($pdo, $runId, 'error', 'Failed to start process');
    return 127;
  }

  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);

  $stdoutBuf = '';
  $stderrBuf = '';
  $lastFlush = microtime(true);

  while (true) {
    $status = proc_get_status($process);
    $running = $status['running'];

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);

    if ($out !== false && $out !== '') $stdoutBuf .= $out;
    if ($err !== false && $err !== '') $stderrBuf .= $err;

    // Flush every ~0.5s to DB so UI feels “live”
    $now = microtime(true);
    if ($now - $lastFlush > 0.5) {
      if ($stdoutBuf !== '') {
        log_run($pdo, $runId, 'info', rtrim($stdoutBuf), ['stream' => 'stdout']);
        $stdoutBuf = '';
      }
      if ($stderrBuf !== '') {
        log_run($pdo, $runId, 'warn', rtrim($stderrBuf), ['stream' => 'stderr']);
        $stderrBuf = '';
      }
      $lastFlush = $now;
    }

    if (!$running) break;
    usleep(100000); // 100ms
  }

  // final flush
  $out = stream_get_contents($pipes[1]);
  $err = stream_get_contents($pipes[2]);
  if ($out) log_run($pdo, $runId, 'info', rtrim($out), ['stream' => 'stdout']);
  if ($err) log_run($pdo, $runId, 'warn', rtrim($err), ['stream' => 'stderr']);

  fclose($pipes[1]);
  fclose($pipes[2]);

  $exitCode = proc_close($process);
  log_run($pdo, $runId, 'info', 'Process exit', ['exit_code' => $exitCode]);

  return (int)$exitCode;
}

/**
 * Build the actual ansible command from meta.script
 */
function build_ansible_cmd(array $meta, string $inventoryPath, array $config): array {
  $script = $meta['script'] ?? [];
  $source = (string)($script['script_source'] ?? '');
  $command = (string)($script['command'] ?? '');

  if ($command === '') throw new RuntimeException('Script command empty');

  // If command already contains "ansible-playbook", we respect it.
  // Otherwise, treat it as a playbook path.
  if (str_contains($command, 'ansible-playbook')) {
    // Append inventory if missing
    if (!preg_match('/\s-i\s+\S+/', $command)) {
      $command .= ' -i ' . escapeshellarg($inventoryPath);
    }
    // shell execution: Windows needs cmd /c, Linux can run directly
    return php_uname('s') === 'Windows NT'
      ? ['cmd', '/c', $command]
      : ['bash', '-lc', $command];
  }

  // Treat as playbook path
  $bin = (string)$config['ansible_playbook_bin'];
  return [$bin, $command, '-i', $inventoryPath];
}

// ---- Main loop ----
echo "[" . now() . "] Worker started: $workerId\n";

while (true) {
  $run = null;
  try {
    $run = claim_run($pdo, $workerId, $lockTimeout);
    if (!$run) {
      sleep($poll);
      continue;
    }

    $runId = (int)$run['id'];
    $meta = json_decode((string)($run['meta'] ?? '{}'), true) ?: [];

    log_run($pdo, $runId, 'info', 'Run claimed', ['worker_id' => $workerId]);

    $t0 = microtime(true);
    mark_run_running($pdo, $runId, $workerId);

    // Resolve targets -> build inventory
    $nodes = resolve_targets($pdo, $meta, $maxTargets);
    log_run($pdo, $runId, 'info', 'Targets resolved', ['count' => count($nodes)]);

    $inventoryPath = write_inventory((string)$config['inventory_dir'], $runId, $nodes);
    log_run($pdo, $runId, 'info', 'Inventory written', ['path' => $inventoryPath]);

    // Build command
    $cmd = build_ansible_cmd($meta, $inventoryPath, $config);

    // Execute
    $exit = run_process($pdo, $runId, $cmd, (array)$config['env']);

    $durationMs = (int)round((microtime(true) - $t0) * 1000);

    if ($exit === 0) {
      mark_run_done($pdo, $runId, 'success', null, null, $durationMs);
      log_run($pdo, $runId, 'info', 'Run completed successfully', ['duration_ms' => $durationMs]);
    } else {
      mark_run_done($pdo, $runId, 'failed', 'ansible_exit', "Process exited with code $exit", $durationMs);
      log_run($pdo, $runId, 'error', 'Run failed', ['exit_code' => $exit, 'duration_ms' => $durationMs]);
    }

    unlock_run($pdo, $runId);
  } catch (Throwable $e) {
    if ($run) {
      $runId = (int)$run['id'];
      $msg = $e->getMessage();
      log_run($pdo, $runId, 'error', 'Worker exception', ['error' => $msg]);

      // Mark failed + unlock so it doesn't wedge
      $durationMs = 0;
      mark_run_done($pdo, $runId, 'failed', 'worker_exception', $msg, $durationMs);
      unlock_run($pdo, $runId);
    }

    // Do not crash loop; sleep briefly
    sleep(1);
  }
}