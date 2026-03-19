<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u = current_user();

// ---- Fleet KPIs ----
$nodesTotal    = (int)$pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn();
$nodesHealthy  = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='healthy'")->fetchColumn();
$nodesDegraded = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status IN ('degraded','warning')")->fetchColumn();
$nodesDown     = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='down'")->fetchColumn();

// ---- Automation KPIs ----
$runsToday  = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE created_at >= CURDATE()")->fetchColumn();
$failsToday = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE created_at >= CURDATE() AND status='failed'")->fetchColumn();
$failRate   = $runsToday > 0 ? round(($failsToday / $runsToday) * 100, 1) : 0.0;
$runningNow = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE status='running'")->fetchColumn();
$queuedNow  = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE status='queued'")->fetchColumn();

// ---- Hardware KPIs ----
$dcCount          = (int)$pdo->query("SELECT COUNT(*) FROM datacenters")->fetchColumn();
$rackCount        = (int)$pdo->query("SELECT COUNT(*) FROM racks")->fetchColumn();
$customerCount    = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE account_status='active'")->fetchColumn();
$unassignedAssets = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE customer_id IS NULL")->fetchColumn();

// ---- Recent runs ----
$recentRunsStmt = $pdo->query(
  "SELECT r.id, r.status, r.created_at, r.duration_ms, a.name AS automation_name
   FROM automation_runs r
   JOIN automations a ON a.id = r.automation_id
   ORDER BY r.created_at DESC
   LIMIT 8"
);
$recentRuns = $recentRunsStmt->fetchAll();

// ---- Sites/locations ----
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

// ---- DC summary ----
$dcSummaryStmt = $pdo->query("
  SELECT d.id, d.name, d.code, d.city, d.country, d.status,
         COUNT(DISTINCT r.id)  AS rack_count,
         COUNT(n.id)           AS node_count,
         SUM(n.status='healthy') AS healthy,
         SUM(n.status='down')  AS down_count
  FROM datacenters d
  LEFT JOIN racks r ON r.datacenter_id = d.id
  LEFT JOIN nodes n ON n.datacenter_id = d.id
  GROUP BY d.id
  ORDER BY d.name ASC
  LIMIT 6
");
$dcSummary = $dcSummaryStmt->fetchAll();

// ---- Customer health overview ----
$custHealthStmt = $pdo->query("
  SELECT c.id, c.name, c.service_level, c.account_status,
         COUNT(n.id)              AS server_count,
         SUM(n.status='healthy')  AS healthy,
         SUM(n.status='down')     AS down_count
  FROM customers c
  LEFT JOIN nodes n ON n.customer_id = c.id
  WHERE c.account_status = 'active'
  GROUP BY c.id
  ORDER BY down_count DESC, c.name ASC
  LIMIT 8
");
$custHealth = $custHealthStmt->fetchAll();

// ---- Audit tail (admin/security only) ----
$canSeeAudit = in_array(($u['role'] ?? ''), ['owner','admin','security'], true);
$audit = [];
if ($canSeeAudit) {
  $auditStmt = $pdo->query(
    "SELECT l.created_at, l.action, l.target_type, l.target_id, u.email AS actor_email
     FROM audit_logs l
     LEFT JOIN users u ON u.id = l.actor_user_id
     ORDER BY l.created_at DESC
     LIMIT 8"
  );
  $audit = $auditStmt->fetchAll();
}

function status_badge_class(string $status): string {
  return match ($status) {
    'healthy', 'success', 'active' => 'badge badge--success',
    'degraded', 'warning'          => 'badge badge--warn',
    'down', 'failed', 'error'      => 'badge badge--danger',
    'running'                      => 'badge badge--info',
    'queued', 'unknown'            => 'badge badge--muted',
    default                        => 'badge',
  };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Overview • NOC Portal</title>
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
              <h1>NOC Control Plane</h1>
              <p class="muted">Fleet health, hardware posture, customer visibility, and execution overview.</p>
            </div>
            <div class="page-header__actions">
              <a class="btn btn--primary" href="/automations.php">Run automation</a>
              <a class="btn" href="/hardware.php">Hardware</a>
              <a class="btn" href="/nodes.php">Fleet</a>
            </div>
          </header>

          <!-- Row 1: Fleet KPIs -->
          <p class="kpi-section-label muted small">Fleet &amp; Automation</p>
          <section class="kpi-grid" aria-label="Fleet metrics">
            <article class="kpi-card">
              <h2>Nodes</h2>
              <p><?= $nodesTotal ?></p>
              <p class="muted small">
                <span class="badge badge--success"><?= $nodesHealthy ?> ok</span>
                <span class="badge badge--warn"><?= $nodesDegraded ?> deg</span>
                <span class="badge badge--danger"><?= $nodesDown ?> down</span>
              </p>
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
              <p class="muted small"><?= $canSeeAudit ? 'Admin visibility' : 'Role restricted' ?></p>
            </article>
          </section>

          <!-- Row 2: Hardware KPIs -->
          <p class="kpi-section-label muted small" style="margin-top:14px;">Infrastructure &amp; Customers</p>
          <section class="kpi-grid" aria-label="Infrastructure metrics">
            <article class="kpi-card">
              <h2>Data Centers</h2>
              <p><?= $dcCount ?></p>
              <p class="muted small"><?= $rackCount ?> racks total</p>
            </article>

            <article class="kpi-card">
              <h2>Assets</h2>
              <p><?= $nodesTotal ?></p>
              <p class="muted small"><?= $unassignedAssets ?> unassigned</p>
            </article>

            <article class="kpi-card">
              <h2>Customers</h2>
              <p><?= $customerCount ?></p>
              <p class="muted small">Active accounts</p>
            </article>

            <article class="kpi-card">
              <h2>Quick links</h2>
              <p class="small" style="font-size:13px;">
                <a class="link" href="/rack.php">Racks</a> •
                <a class="link" href="/datacenters.php">DCs</a> •
                <a class="link" href="/customers.php">Customers</a>
              </p>
              <p class="muted small">One-click navigation</p>
            </article>
          </section>

          <!-- Row 3: DC overview + Recent Runs -->
          <section class="grid-2">

            <!-- Data Center summary -->
            <section class="panel">
              <header class="panel__header">
                <h2>Data Centers</h2>
                <a class="link" href="/datacenters.php">View all</a>
              </header>
              <div class="panel__body">
                <?php if (!$dcSummary): ?>
                  <p class="muted">No data centers configured. <a class="link" href="/datacenters.php">Add one →</a></p>
                <?php else: ?>
                  <table class="table">
                    <thead>
                      <tr>
                        <th>DC</th>
                        <th>Racks</th>
                        <th>Servers</th>
                        <th>Healthy</th>
                        <th>Down</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($dcSummary as $d): ?>
                        <tr>
                          <td>
                            <a class="link" href="/datacenters.php?id=<?= (int)$d['id'] ?>">
                              <?= htmlspecialchars($d['code'] ?? $d['name']) ?>
                            </a>
                            <?php if ($d['city']): ?>
                              <br><span class="muted small"><?= htmlspecialchars($d['city']) ?></span>
                            <?php endif; ?>
                          </td>
                          <td><?= (int)$d['rack_count'] ?></td>
                          <td><?= (int)$d['node_count'] ?></td>
                          <td><?= (int)$d['healthy'] ?></td>
                          <td>
                            <?= (int)$d['down_count'] > 0
                              ? '<span class="badge badge--danger">'.(int)$d['down_count'].'</span>'
                              : '<span class="muted">0</span>' ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
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
                      <th>ms</th>
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
                        <td class="muted small"><?= $r['duration_ms'] !== null ? (int)$r['duration_ms'] : '—' ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <!-- Row 4: Customer health + Locations -->
          <section class="grid-2">

            <!-- Customer health -->
            <section class="panel">
              <header class="panel__header">
                <h2>Customer Health</h2>
                <a class="link" href="/customers.php">View all</a>
              </header>
              <div class="panel__body">
                <?php if (!$custHealth): ?>
                  <p class="muted">No customers configured. <a class="link" href="/customers.php">Add one →</a></p>
                <?php else: ?>
                  <table class="table">
                    <thead>
                      <tr>
                        <th>Customer</th>
                        <th>SL</th>
                        <th>Servers</th>
                        <th>Healthy</th>
                        <th>Down</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($custHealth as $c): ?>
                        <tr>
                          <td>
                            <a class="link" href="/customer.php?id=<?= (int)$c['id'] ?>">
                              <?= htmlspecialchars($c['name']) ?>
                            </a>
                          </td>
                          <td>
                            <?= $c['service_level']
                              ? '<span class="badge badge--muted">'.htmlspecialchars($c['service_level']).'</span>'
                              : '<span class="muted">—</span>' ?>
                          </td>
                          <td><?= (int)$c['server_count'] ?></td>
                          <td><?= (int)$c['healthy'] ?></td>
                          <td>
                            <?= (int)$c['down_count'] > 0
                              ? '<span class="badge badge--danger">'.(int)$c['down_count'].'</span>'
                              : '<span class="muted">0</span>' ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </section>

            <!-- Locations/Sites -->
            <section class="panel">
              <header class="panel__header">
                <h2>Locations</h2>
                <a class="link" href="/nodes.php">Fleet view</a>
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
          </section>

          <!-- Audit tail (role-gated) -->
          <?php if ($canSeeAudit): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Audit Trail</h2>
              <a class="link" href="/audit.php">View full</a>
            </header>
            <div class="panel__body">
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
                        <?= htmlspecialchars((string)($a['target_type'] ?? '—')) ?>
                        <?= htmlspecialchars((string)($a['target_id'] ?? '')) ?>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </section>
          <?php endif; ?>

        </section>

        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
</body>
</html>
