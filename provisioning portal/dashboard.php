<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u = current_user();

// ---- KPI queries ----
$nodesTotal = (int)$pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn();

$nodesHealthy = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='healthy'")->fetchColumn();
$nodesDegraded = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status IN ('degraded','warning')")->fetchColumn();
$nodesDown = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='down'")->fetchColumn();

$runsToday = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE created_at >= CURDATE()")->fetchColumn();
$failsToday = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE created_at >= CURDATE() AND status='failed'")->fetchColumn();
$failRate = $runsToday > 0 ? round(($failsToday / $runsToday) * 100, 1) : 0.0;

$runningNow = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE status='running'")->fetchColumn();
$queuedNow = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE status='queued'")->fetchColumn();

// ---- Recent runs ----
$recentRunsStmt = $pdo->query(
  "SELECT r.id, r.status, r.created_at, r.duration_ms, a.name AS automation_name
   FROM automation_runs r
   JOIN automations a ON a.id = r.automation_id
   ORDER BY r.created_at DESC
   LIMIT 10"
);
$recentRuns = $recentRunsStmt->fetchAll();

// ---- Nodes by location/site ----
$sitesStmt = $pdo->query(
  "SELECT site,
          COUNT(*) AS total,
          SUM(status='healthy') AS healthy,
          SUM(status IN ('degraded','warning')) AS degraded,
          SUM(status='down') AS down_count,
          MAX(last_seen_at) AS last_seen
   FROM nodes
   GROUP BY site
   ORDER BY site ASC"
);
$sites = $sitesStmt->fetchAll();

// ---- Recent node list ----
$nodesStmt = $pdo->query(
  "SELECT name, site, provider, status, mgmt_ip, last_seen_at
   FROM nodes
   ORDER BY site ASC, name ASC
   LIMIT 12"
);
$nodes = $nodesStmt->fetchAll();

// ---- Audit tail (admin/security only) ----
$canSeeAudit = in_array(($u['role'] ?? ''), ['owner','admin','security'], true);
$audit = [];
if ($canSeeAudit) {
  $auditStmt = $pdo->query(
    "SELECT l.created_at, l.action, l.target_type, l.target_id, u.email AS actor_email
     FROM audit_logs l
     LEFT JOIN users u ON u.id = l.actor_user_id
     ORDER BY l.created_at DESC
     LIMIT 10"
  );
  $audit = $auditStmt->fetchAll();
}

// helper for status badge class
function status_badge_class(string $status): string {
  return match ($status) {
    'healthy', 'success' => 'badge badge--success',
    'degraded', 'warning' => 'badge badge--warn',
    'down', 'failed', 'error' => 'badge badge--danger',
    'running' => 'badge badge--info',
    'queued' => 'badge badge--muted',
    default => 'badge',
  };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Overview • Provisioning Portal</title>

  <!-- Use your existing formatting -->
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
          <header class="page-header">
            <div class="page-header__titles">
              <h1>Control Plane Overview</h1>
              <p class="muted">Fleet health, execution posture, and security visibility.</p>
            </div>
            <div class="page-header__actions">
              <a class="btn btn--primary" href="/automations.php">Run automation</a>
              <a class="btn" href="/nodes.php">Fleet view</a>
            </div>
          </header>

          <!-- KPI Widgets -->
          <section class="kpi-grid" aria-label="Key metrics">
            <article class="kpi-card">
              <h2>Nodes</h2>
              <p><?= $nodesTotal ?></p>
              <p class="muted small">Healthy <?= $nodesHealthy ?> • Degraded <?= $nodesDegraded ?> • Down <?= $nodesDown ?></p>
            </article>

            <article class="kpi-card">
              <h2>Runs today</h2>
              <p><?= $runsToday ?></p>
              <p class="muted small">Fail rate <?= $failRate ?>%</p>
            </article>

            <article class="kpi-card">
              <h2>In-flight</h2>
              <p><?= $runningNow ?></p>
              <p class="muted small">Queued <?= $queuedNow ?></p>
            </article>

            <article class="kpi-card">
              <h2>Security</h2>
              <p><?= $canSeeAudit ? 'AUDIT ON' : 'LIMITED' ?></p>
              <p class="muted small"><?= $canSeeAudit ? 'Admin visibility enabled' : 'Role restricted' ?></p>
            </article>
          </section>

          <section class="grid-2">
            <!-- Sites / Locations -->
            <section class="panel">
              <header class="panel__header">
                <h2>Locations</h2>
                <a class="link" href="/nodes.php">View all</a>
              </header>
              <div class="panel__body">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Site</th>
                      <th>Total</th>
                      <th>Healthy</th>
                      <th>Degraded</th>
                      <th>Down</th>
                      <th>Last seen</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$sites): ?>
                      <tr><td colspan="6" class="muted">No sites yet.</td></tr>
                    <?php else: foreach ($sites as $s): ?>
                      <tr>
                        <td><?= htmlspecialchars($s['site']) ?></td>
                        <td><?= (int)$s['total'] ?></td>
                        <td><?= (int)$s['healthy'] ?></td>
                        <td><?= (int)$s['degraded'] ?></td>
                        <td><?= (int)$s['down_count'] ?></td>
                        <td class="muted small"><?= htmlspecialchars((string)($s['last_seen'] ?? '—')) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </section>

            <!-- Recent Runs -->
            <section class="panel">
              <header class="panel__header">
                <h2>Recent Runs</h2>
                <a class="link" href="/runs.php">View all</a>
              </header>
              <div class="panel__body">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Time</th>
                      <th>Automation</th>
                      <th>Status</th>
                      <th>Duration</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$recentRuns): ?>
                      <tr><td colspan="4" class="muted">No runs yet.</td></tr>
                    <?php else: foreach ($recentRuns as $r): ?>
                      <tr>
                        <td class="muted small"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= htmlspecialchars($r['automation_name']) ?></td>
                        <td><span class="<?= status_badge_class((string)$r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                        <td class="muted small">
                          <?= $r['duration_ms'] !== null ? ((int)$r['duration_ms'] . ' ms') : '—' ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <!-- Node snapshot -->
          <section class="panel">
            <header class="panel__header">
              <h2>Node Snapshot</h2>
              <span class="muted small">Top 12 nodes by site/name</span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Site</th>
                    <th>Provider</th>
                    <th>Status</th>
                    <th>Mgmt IP</th>
                    <th>Last seen</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$nodes): ?>
                    <tr><td colspan="6" class="muted">No nodes yet.</td></tr>
                  <?php else: foreach ($nodes as $n): ?>
                    <tr>
                      <td><?= htmlspecialchars($n['name']) ?></td>
                      <td><?= htmlspecialchars($n['site']) ?></td>
                      <td class="muted"><?= htmlspecialchars((string)($n['provider'] ?? '—')) ?></td>
                      <td><span class="<?= status_badge_class((string)$n['status']) ?>"><?= htmlspecialchars($n['status']) ?></span></td>
                      <td class="muted small"><?= htmlspecialchars((string)($n['mgmt_ip'] ?? '—')) ?></td>
                      <td class="muted small"><?= htmlspecialchars((string)($n['last_seen_at'] ?? '—')) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Audit tail (role-gated) -->
          <section class="panel">
            <header class="panel__header">
              <h2>Audit Trail</h2>
              <?php if ($canSeeAudit): ?>
                <a class="link" href="/audit.php">View full</a>
              <?php else: ?>
                <span class="muted small">Restricted to admin/security</span>
              <?php endif; ?>
            </header>

            <div class="panel__body">
              <?php if (!$canSeeAudit): ?>
                <p class="muted">You do not have permission to view audit events.</p>
              <?php else: ?>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Time</th>
                      <th>Actor</th>
                      <th>Action</th>
                      <th>Target</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$audit): ?>
                      <tr><td colspan="4" class="muted">No audit events yet.</td></tr>
                    <?php else: foreach ($audit as $a): ?>
                      <tr>
                        <td class="muted small"><?= htmlspecialchars($a['created_at']) ?></td>
                        <td><?= htmlspecialchars((string)($a['actor_email'] ?? 'system')) ?></td>
                        <td><?= htmlspecialchars($a['action']) ?></td>
                        <td class="muted small">
                          <?= htmlspecialchars((string)($a['target_type'] ?? '—')) ?> <?= htmlspecialchars((string)($a['target_id'] ?? '—')) ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </section>
        </section>

        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
</body>
</html>