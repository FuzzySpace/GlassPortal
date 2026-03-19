<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/auth/bootstrap.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';
$userId = (int)($u['id'] ?? 0);

if (!in_array($role, ['owner', 'admin', 'operator'], true)) {
    http_response_code(403); exit('Access denied');
}

// ---- Load node ----
$nodeId = isset($_GET['node']) && ctype_digit($_GET['node']) ? (int)$_GET['node'] : 0;
if (!$nodeId) { header('Location: /hardware.php'); exit; }

$nodeStmt = $pdo->prepare("
    SELECT n.*, d.name AS dc_name, d.code AS dc_code,
           r.name AS rack_name, c.name AS customer_name
    FROM nodes n
    LEFT JOIN datacenters d ON d.id=n.datacenter_id
    LEFT JOIN racks r        ON r.id=n.rack_id
    LEFT JOIN customers c    ON c.id=n.customer_id
    WHERE n.id=?
");
$nodeStmt->execute([$nodeId]);
$node = $nodeStmt->fetch();
if (!$node) { header('Location: /hardware.php'); exit; }

// ---- Load or create provisioning session ----
$provId = isset($_GET['prov']) && ctype_digit($_GET['prov']) ? (int)$_GET['prov'] : null;

// Handle actions
$msg = null;
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) { $msg = 'Invalid CSRF token.'; $msgType = 'err'; }
    else {
        $action = (string)($_POST['action'] ?? '');

        // Start a new provisioning session
        if ($action === 'start_prov') {
            $stmt = $pdo->prepare("INSERT INTO server_provisioning (node_id, started_by, status, created_at) VALUES (?,?,'in_progress',NOW())");
            $stmt->execute([$nodeId, $userId ?: null]);
            $provId = (int)$pdo->lastInsertId();

            // Seed all default tasks
            $tasks = $pdo->query("SELECT * FROM provisioning_tasks ORDER BY step_order ASC")->fetchAll();
            $ins = $pdo->prepare("INSERT INTO provisioning_step_log (provisioning_id, task_id, task_name, category, status, created_at) VALUES (?,?,?,?,'pending',NOW())");
            foreach ($tasks as $t) {
                $ins->execute([$provId, (int)$t['id'], $t['name'], $t['category']]);
            }
            $msg = 'Provisioning session started.';
            header("Location: /provision.php?node=$nodeId&prov=$provId");
            exit;
        }

        // Update a single step
        if ($action === 'update_step' && $provId && ctype_digit($_POST['step_id'] ?? '')) {
            $stepId    = (int)$_POST['step_id'];
            $newStatus = (string)($_POST['step_status'] ?? 'pending');
            $notes     = trim((string)($_POST['step_notes'] ?? ''));
            $allowed   = ['pending','pass','fail','skip','na'];
            if (!in_array($newStatus, $allowed, true)) $newStatus = 'pending';

            $completedAt = in_array($newStatus, ['pass','fail','skip','na'], true) ? 'NOW()' : 'NULL';
            $stmt = $pdo->prepare("
                UPDATE provisioning_step_log
                SET status=?,
                    notes=?,
                    completed_by_user_id=?,
                    completed_at=".($newStatus!=='pending'?'NOW()':'NULL')."
                WHERE id=? AND provisioning_id=?
            ");
            $stmt->execute([$newStatus, $notes ?: null, $userId ?: null, $stepId, $provId]);

            // Check if all required steps are done → auto-complete the session
            $pending = (int)$pdo->prepare("
                SELECT COUNT(*) FROM provisioning_step_log
                WHERE provisioning_id=? AND status='pending'
            ")->execute([$provId]) ? $pdo->query("SELECT COUNT(*) FROM provisioning_step_log WHERE provisioning_id=$provId AND status='pending'")->fetchColumn() : 1;

            header("Location: /provision.php?node=$nodeId&prov=$provId&updated=1");
            exit;
        }

        // Mark entire session complete
        if ($action === 'complete_prov' && $provId) {
            $pdo->prepare("UPDATE server_provisioning SET status='complete', completed_at=NOW() WHERE id=? AND node_id=?")->execute([$provId, $nodeId]);
            // Mark node as healthy
            $pdo->prepare("UPDATE nodes SET status='healthy', updated_at=NOW() WHERE id=?")->execute([$nodeId]);
            header("Location: /provision.php?node=$nodeId&prov=$provId&completed=1");
            exit;
        }
    }
}

// ---- Load provisioning history ----
$provHistory = $pdo->prepare("
    SELECT p.*, u.email AS started_by_email
    FROM server_provisioning p
    LEFT JOIN users u ON u.id=p.started_by
    WHERE p.node_id=?
    ORDER BY p.created_at DESC
")->execute([$nodeId]) ? $pdo->query("SELECT p.*, u.email AS started_by_email FROM server_provisioning p LEFT JOIN users u ON u.id=p.started_by WHERE p.node_id=$nodeId ORDER BY p.created_at DESC")->fetchAll() : [];

// ---- Load current session steps ----
$steps     = [];
$prov      = null;
$progress  = ['total'=>0,'pass'=>0,'fail'=>0,'skip'=>0,'pending'=>0,'na'=>0];

if ($provId) {
    $provQ = $pdo->prepare("SELECT * FROM server_provisioning WHERE id=? AND node_id=?");
    $provQ->execute([$provId, $nodeId]);
    $prov = $provQ->fetch() ?: null;

    if ($prov) {
        $stepQ = $pdo->prepare("
            SELECT s.*, t.description AS task_desc, t.is_required, t.script_id,
                   sc.name AS script_name, sc.command AS script_command,
                   u.email AS completed_by_email,
                   ar.id AS run_id, ar.status AS run_status
            FROM provisioning_step_log s
            LEFT JOIN provisioning_tasks t  ON t.id=s.task_id
            LEFT JOIN ansible_scripts sc    ON sc.id=t.script_id
            LEFT JOIN users u               ON u.id=s.completed_by_user_id
            LEFT JOIN automation_runs ar    ON ar.id=s.automation_run_id
            WHERE s.provisioning_id=?
            ORDER BY t.step_order ASC, s.id ASC
        ");
        $stepQ->execute([$provId]);
        $steps = $stepQ->fetchAll();

        foreach ($steps as $s) {
            $progress['total']++;
            $st = (string)($s['status'] ?? 'pending');
            $progress[$st] = ($progress[$st] ?? 0) + 1;
        }
    }
}

// Group steps by category
$stepsByCategory = [];
foreach ($steps as $s) {
    $stepsByCategory[$s['category']][] = $s;
}

$pctDone = $progress['total'] > 0
    ? (int)round((($progress['pass'] + $progress['skip'] + $progress['na']) / $progress['total']) * 100)
    : 0;

function step_badge(string $status): string {
    return match($status) {
        'pass'    => 'badge badge--success',
        'fail'    => 'badge badge--danger',
        'skip'    => 'badge badge--warn',
        'na'      => 'badge badge--muted',
        'pending' => 'badge badge--muted',
        default   => 'badge',
    };
}

$catLabels = [
    'hardware'  => 'Hardware Verification',
    'os'        => 'OS Provisioning',
    'network'   => 'Network & Connectivity',
    'cis'       => 'CIS Hardening',
    'security'  => 'Security Checks',
    'monitoring'=> 'Monitoring & Handover',
];

$updated   = isset($_GET['updated']);
$completed = isset($_GET['completed']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Provisioning — <?= htmlspecialchars($node['name']) ?> • NOC Portal</title>
  <link rel="stylesheet" href="assets/css/styles.css" />
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <div class="app-shell" data-layout="dashboard">
    <?php require __DIR__ . '/components/header.php'; ?>
    <div class="app-shell__body">
      <?php require __DIR__ . '/components/sidebar.php'; ?>

      <main id="main" class="main" tabindex="-1">
        <section class="page">

          <!-- Breadcrumb -->
          <nav class="breadcrumb" aria-label="Breadcrumb">
            <a class="link" href="/hardware.php">Hardware</a>
            <span class="muted"> / </span>
            <a class="link" href="/server.php?id=<?= $nodeId ?>"><?= htmlspecialchars($node['name']) ?></a>
            <span class="muted"> / </span>
            <span>Provisioning</span>
          </nav>

          <header class="page-header">
            <div class="page-header__titles">
              <h1>Provisioning — <?= htmlspecialchars($node['name']) ?></h1>
              <p class="muted">
                <?= htmlspecialchars($node['dc_code'] ?? $node['dc_name'] ?? '—') ?>
                <?= $node['rack_name'] ? ' / '.htmlspecialchars($node['rack_name']) : '' ?>
                <?= $node['customer_name'] ? ' • '.htmlspecialchars($node['customer_name']) : '' ?>
              </p>
            </div>
            <div class="page-header__actions">
              <a class="btn" href="/node_edit.php?id=<?= $nodeId ?>">Edit Server Info</a>
              <a class="btn" href="/automations.php?node=<?= $nodeId ?>">Run Ansible →</a>
            </div>
          </header>

          <?php if ($msg): ?>
            <div class="form-alert <?= $msgType==='ok' ? 'form-alert--ok' : '' ?>">
              <?= htmlspecialchars($msg) ?>
            </div>
          <?php endif; ?>

          <?php if ($completed): ?>
            <div class="form-alert" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10);">
              Provisioning marked complete. Server status set to <strong>Healthy</strong>.
            </div>
          <?php endif; ?>

          <?php if ($updated): ?>
            <div class="form-alert" style="border-color:rgba(111,182,255,.30);background:rgba(111,182,255,.08);">
              Step updated.
            </div>
          <?php endif; ?>

          <!-- ──────────────────────────────────────────────────────── -->
          <!-- No active session: show history + start button           -->
          <!-- ──────────────────────────────────────────────────────── -->
          <?php if (!$prov): ?>

            <div class="grid-2">
              <section class="panel">
                <header class="panel__header"><h2>Start Provisioning</h2></header>
                <div class="panel__body" style="padding:18px;">
                  <p class="muted" style="margin-top:0;">
                    This will create a new provisioning checklist for <strong><?= htmlspecialchars($node['name']) ?></strong>
                    covering hardware verification, OS setup, CIS hardening (Level 1 &amp; 2),
                    security checks, and monitoring handover.
                  </p>
                  <form method="post" action="/provision.php?node=<?= $nodeId ?>">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
                    <input type="hidden" name="action" value="start_prov" />
                    <button class="btn btn--primary" type="submit">Start provisioning checklist</button>
                  </form>
                </div>
              </section>

              <section class="panel">
                <header class="panel__header"><h2>Server Details</h2></header>
                <div class="panel__body">
                  <dl class="detail-dl">
                    <dt>Status</dt>
                    <dd><span class="<?= $node['status']==='healthy'?'badge badge--success':($node['status']==='down'?'badge badge--danger':'badge badge--warn') ?>"><?= htmlspecialchars($node['status']??'unknown') ?></span></dd>
                    <dt>Make / Model</dt>
                    <dd><?= htmlspecialchars(trim(($node['make']??'').(' '.($node['model']??'')))) ?: '<span class="muted">—</span>' ?></dd>
                    <dt>OS</dt>
                    <dd><?= htmlspecialchars(trim(($node['os_type']??'').' '.($node['os_version']??''))) ?: '<span class="muted">—</span>' ?></dd>
                    <dt>Mgmt IP</dt>
                    <dd><?= $node['mgmt_ip'] ? htmlspecialchars($node['mgmt_ip']) : '<span class="muted">—</span>' ?></dd>
                  </dl>
                </div>
              </section>
            </div>

            <?php if ($provHistory): ?>
            <section class="panel">
              <header class="panel__header"><h2>Previous Provisioning Sessions</h2></header>
              <div class="panel__body">
                <table class="table">
                  <thead>
                    <tr><th>Started</th><th>By</th><th>Status</th><th>Completed</th><th></th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($provHistory as $ph): ?>
                      <tr>
                        <td class="muted small"><?= htmlspecialchars($ph['created_at']) ?></td>
                        <td><?= htmlspecialchars((string)($ph['started_by_email'] ?? 'system')) ?></td>
                        <td><span class="<?= $ph['status']==='complete'?'badge badge--success':'badge badge--warn' ?>"><?= htmlspecialchars($ph['status']) ?></span></td>
                        <td class="muted small"><?= $ph['completed_at'] ? htmlspecialchars($ph['completed_at']) : '—' ?></td>
                        <td><a class="link" href="/provision.php?node=<?= $nodeId ?>&prov=<?= (int)$ph['id'] ?>">View →</a></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
            <?php endif; ?>

          <?php else: ?>
          <!-- ──────────────────────────────────────────────────────── -->
          <!-- Active / view session                                    -->
          <!-- ──────────────────────────────────────────────────────── -->

            <!-- Progress bar -->
            <section class="panel">
              <header class="panel__header">
                <h2>Progress</h2>
                <div style="display:flex; gap:12px; align-items:center;">
                  <span class="muted small">Session #<?= $provId ?></span>
                  <span class="<?= $prov['status']==='complete'?'badge badge--success':'badge badge--warn' ?>"><?= htmlspecialchars($prov['status']) ?></span>
                </div>
              </header>
              <div class="panel__body" style="padding:16px 18px;">
                <div class="prov-progress-bar">
                  <div class="prov-progress-bar__fill" style="width:<?= $pctDone ?>%;"></div>
                </div>
                <div class="prov-progress-stats">
                  <span><?= $pctDone ?>% complete</span>
                  <span class="badge badge--success"><?= $progress['pass'] ?> pass</span>
                  <span class="badge badge--danger"><?= $progress['fail'] ?> fail</span>
                  <span class="badge badge--warn"><?= $progress['skip'] ?> skip</span>
                  <span class="badge badge--muted"><?= $progress['na'] ?> N/A</span>
                  <span class="muted small"><?= $progress['pending'] ?> pending</span>
                  <span class="muted small" style="margin-left:auto;"><?= $progress['total'] ?> total steps</span>
                </div>

                <?php if ($prov['status'] !== 'complete' && $progress['pending'] === 0 && in_array($role, ['owner','admin','operator'], true)): ?>
                  <form method="post" action="/provision.php?node=<?= $nodeId ?>&prov=<?= $provId ?>" style="margin-top:14px;">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
                    <input type="hidden" name="action" value="complete_prov" />
                    <button class="btn btn--primary" type="submit">Mark provisioning complete &amp; set server healthy</button>
                  </form>
                <?php elseif ($prov['status'] !== 'complete'): ?>
                  <p class="muted small" style="margin-top:8px;"><?= $progress['pending'] ?> steps still pending.</p>
                <?php endif; ?>
              </div>
            </section>

            <!-- Session info + history switcher -->
            <div class="grid-2">
              <section class="panel">
                <header class="panel__header"><h2>Session Info</h2></header>
                <div class="panel__body">
                  <dl class="detail-dl">
                    <dt>Started</dt>
                    <dd class="muted small"><?= htmlspecialchars($prov['created_at']) ?></dd>
                    <?php if ($prov['completed_at']): ?>
                      <dt>Completed</dt>
                      <dd class="muted small"><?= htmlspecialchars($prov['completed_at']) ?></dd>
                    <?php endif; ?>
                    <?php if ($prov['notes']): ?>
                      <dt>Notes</dt>
                      <dd><?= htmlspecialchars($prov['notes']) ?></dd>
                    <?php endif; ?>
                  </dl>
                </div>
              </section>

              <?php if (count($provHistory) > 1): ?>
              <section class="panel">
                <header class="panel__header"><h2>Other Sessions</h2></header>
                <div class="panel__body" style="padding:10px 18px;">
                  <?php foreach ($provHistory as $ph): if ((int)$ph['id'] === $provId) continue; ?>
                    <a class="link" href="/provision.php?node=<?= $nodeId ?>&prov=<?= (int)$ph['id'] ?>">
                      Session #<?= (int)$ph['id'] ?> — <?= htmlspecialchars($ph['status']) ?>
                      (<?= htmlspecialchars(substr($ph['created_at'],0,10)) ?>)
                    </a><br>
                  <?php endforeach; ?>
                </div>
              </section>
              <?php endif; ?>
            </div>

            <!-- ── Step checklist by category ── -->
            <?php foreach ($stepsByCategory as $cat => $catSteps): ?>
              <section class="panel">
                <header class="panel__header">
                  <h2><?= htmlspecialchars($catLabels[$cat] ?? ucfirst($cat)) ?></h2>
                  <div style="display:flex;gap:8px;align-items:center;">
                    <?php
                      $catPass = count(array_filter($catSteps, fn($s)=>$s['status']==='pass'));
                      $catTotal = count($catSteps);
                    ?>
                    <span class="muted small"><?= $catPass ?>/<?= $catTotal ?> complete</span>
                  </div>
                </header>
                <div class="panel__body">
                  <?php foreach ($catSteps as $step): ?>
                    <div class="prov-step <?= $step['status']==='pass'?'prov-step--pass':($step['status']==='fail'?'prov-step--fail':($step['status']==='skip'||$step['status']==='na'?'prov-step--skip':'')) ?>">

                      <div class="prov-step__header" onclick="toggleStep(<?= (int)$step['id'] ?>)">
                        <div class="prov-step__left">
                          <span class="<?= step_badge($step['status']) ?>"><?= htmlspecialchars($step['status']) ?></span>
                          <span class="prov-step__name">
                            <?= htmlspecialchars($step['task_name']) ?>
                            <?php if ($step['is_required']): ?>
                              <span class="badge badge--danger" style="font-size:9px; padding:1px 5px;">required</span>
                            <?php endif; ?>
                          </span>
                        </div>
                        <div class="prov-step__right">
                          <?php if ($step['script_name']): ?>
                            <span class="badge badge--info" title="Linked Ansible script">⚙ <?= htmlspecialchars($step['script_name']) ?></span>
                          <?php endif; ?>
                          <?php if ($step['run_id']): ?>
                            <a class="link" href="/run.php?id=<?= (int)$step['run_id'] ?>">
                              Run #<?= (int)$step['run_id'] ?> (<?= htmlspecialchars($step['run_status']) ?>)
                            </a>
                          <?php endif; ?>
                          <?php if ($step['completed_by_email']): ?>
                            <span class="muted small"><?= htmlspecialchars($step['completed_by_email']) ?></span>
                          <?php endif; ?>
                          <span class="prov-step__toggle muted small">▸</span>
                        </div>
                      </div>

                      <?php if ($step['task_desc']): ?>
                        <div class="prov-step__desc muted small" id="desc-<?= (int)$step['id'] ?>" style="display:none;">
                          <?= htmlspecialchars($step['task_desc']) ?>
                        </div>
                      <?php endif; ?>

                      <!-- Update form -->
                      <?php if ($prov['status'] !== 'complete'): ?>
                      <div class="prov-step__form" id="stepform-<?= (int)$step['id'] ?>" style="display:none;">
                        <form method="post" action="/provision.php?node=<?= $nodeId ?>&prov=<?= $provId ?>">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
                          <input type="hidden" name="action" value="update_step" />
                          <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>" />

                          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:8px;">
                            <select name="step_status" style="padding:6px 10px; border-radius:8px; border:1px solid rgba(255,255,255,.10); background:rgba(0,0,0,.28); color:var(--text); font:inherit; font-size:12px;">
                              <?php foreach (['pending','pass','fail','skip','na'] as $sv): ?>
                                <option value="<?= $sv ?>" <?= $step['status']===$sv?'selected':'' ?>><?= ucfirst($sv==='na'?'N/A':$sv) ?></option>
                              <?php endforeach; ?>
                            </select>

                            <input type="text" name="step_notes"
                              value="<?= htmlspecialchars((string)($step['notes'] ?? '')) ?>"
                              placeholder="Optional notes…"
                              style="flex:1; min-width:180px; padding:6px 10px; border-radius:8px; border:1px solid rgba(255,255,255,.10); background:rgba(0,0,0,.28); color:var(--text); font:inherit; font-size:12px;" />

                            <button class="btn btn--primary" type="submit" style="padding:6px 14px; font-size:12px;">Save</button>

                            <?php if ($step['script_name']): ?>
                              <a class="btn" style="padding:6px 14px;font-size:12px;"
                                 href="/automations.php?script=<?= (int)$step['script_id'] ?>&node=<?= $nodeId ?>">
                                Run: <?= htmlspecialchars($step['script_name']) ?> →
                              </a>
                            <?php endif; ?>
                          </div>
                        </form>
                      </div>
                      <?php endif; ?>

                    </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>

            <?php if ($prov['status'] !== 'complete'): ?>
            <div style="display:flex; gap:10px;">
              <a class="btn" href="/automations.php?node=<?= $nodeId ?>">Run Ansible on this server →</a>
              <a class="btn" href="/scripts.php">Script Library</a>
            </div>
            <?php endif; ?>

          <?php endif; ?>

        </section>
        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>

<script>
function toggleStep(stepId) {
  const form = document.getElementById('stepform-' + stepId);
  const desc = document.getElementById('desc-' + stepId);
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
  if (desc) desc.style.display = desc.style.display === 'none' ? 'block' : 'none';
}
</script>

<style>
/* Progress bar */
.prov-progress-bar {
  height: 8px;
  border-radius: 999px;
  background: rgba(255,255,255,0.08);
  overflow: hidden;
  margin-bottom: 10px;
}
.prov-progress-bar__fill {
  height: 100%;
  background: linear-gradient(90deg, var(--ok), var(--accent));
  transition: width .4s ease;
  border-radius: 999px;
}
.prov-progress-stats {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  font-size: 13px;
}

/* Steps */
.prov-step {
  border-bottom: 1px solid rgba(255,255,255,0.04);
  padding: 10px 18px;
  transition: background .1s;
}
.prov-step:last-child { border-bottom: none; }
.prov-step--pass { border-left: 3px solid var(--ok); background: rgba(52,211,153,0.03); }
.prov-step--fail { border-left: 3px solid var(--danger); background: rgba(255,90,122,0.04); }
.prov-step--skip { border-left: 3px solid rgba(255,255,255,0.18); }

.prov-step__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  gap: 10px;
  flex-wrap: wrap;
}
.prov-step__left  { display:flex; align-items:center; gap:10px; flex:1; }
.prov-step__right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.prov-step__name  { font-size:13px; font-weight:500; }
.prov-step__toggle{ font-size:10px; }
.prov-step__desc  { padding:6px 0 4px; font-size:12px; }
.prov-step__form  { padding-top:6px; }
</style>
</body>
</html>
