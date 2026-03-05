<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u = current_user();
$role = $u['role'] ?? 'operator';

// ---- Filters ----
$q        = trim((string)($_GET['q'] ?? ''));
$site     = trim((string)($_GET['site'] ?? 'all'));
$status   = trim((string)($_GET['status'] ?? 'all'));    // all|healthy|degraded|warning|down|unknown
$provider = trim((string)($_GET['provider'] ?? 'all'));
$window   = trim((string)($_GET['stale'] ?? '30m'));     // 10m|30m|2h|24h
$limit    = 200;

// stale threshold
$staleSql = match ($window) {
  '10m' => "INTERVAL 10 MINUTE",
  '2h'  => "INTERVAL 2 HOUR",
  '24h' => "INTERVAL 24 HOUR",
  default => "INTERVAL 30 MINUTE",
};

$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(n.name LIKE ? OR n.site LIKE ? OR n.provider LIKE ? OR n.cpu_model LIKE ? OR n.mgmt_ip LIKE ?)";
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like, $like, $like);
}

if ($site !== 'all') {
  $where[] = "n.site = ?";
  $params[] = $site;
}

if ($status !== 'all') {
  $where[] = "n.status = ?";
  $params[] = $status;
}

if ($provider !== 'all') {
  $where[] = "n.provider = ?";
  $params[] = $provider;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ---- Filter dropdown options ----
$sites = $pdo->query("SELECT DISTINCT site FROM nodes ORDER BY site ASC")->fetchAll();
$providers = $pdo->query("SELECT DISTINCT provider FROM nodes WHERE provider IS NOT NULL AND provider <> '' ORDER BY provider ASC")->fetchAll();

// ---- KPI cards (overall, not filter-limited) ----
$nodesTotal    = (int)$pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn();
$nodesHealthy  = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='healthy'")->fetchColumn();
$nodesDegraded = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status IN ('degraded','warning')")->fetchColumn();
$nodesDown     = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='down'")->fetchColumn();
$nodesUnknown  = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE status='unknown'")->fetchColumn();

// stale nodes KPI
$staleStmt = $pdo->prepare(
  "SELECT COUNT(*)
   FROM nodes
   WHERE last_seen_at IS NULL OR last_seen_at < (NOW() - $staleSql)"
);
$staleStmt->execute();
$nodesStale = (int)$staleStmt->fetchColumn();

// ---- Nodes list (filtered) ----
$sql = "
  SELECT n.id, n.name, n.site, n.provider, n.status, n.mgmt_ip,
         n.cpu_model, n.cpu_cores, n.ram_gb, n.storage_gb,
         n.last_seen_at, n.created_at
  FROM nodes n
  $whereSql
  ORDER BY
    CASE n.status
      WHEN 'down' THEN 1
      WHEN 'degraded' THEN 2
      WHEN 'warning' THEN 2
      WHEN 'unknown' THEN 3
      WHEN 'healthy' THEN 4
      ELSE 5
    END,
    n.site ASC,
    n.name ASC
  LIMIT $limit
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$nodes = $stmt->fetchAll();

// ---- Site summary panel (overall) ----
$siteSummary = $pdo->query(
  "SELECT site,
          COUNT(*) AS total,
          SUM(status='healthy') AS healthy,
          SUM(status IN ('degraded','warning')) AS degraded,
          SUM(status='down') AS down_count,
          SUM(status='unknown') AS unknown_count,
          MAX(last_seen_at) AS last_seen
   FROM nodes
   GROUP BY site
   ORDER BY site ASC"
)->fetchAll();

$canSeeMgmt = in_array($role, ['owner','admin','security'], true);

function status_badge_class(string $status): string {
  return match ($status) {
    'healthy' => 'badge badge--success',
    'degraded', 'warning' => 'badge badge--warn',
    'down' => 'badge badge--danger',
    'unknown' => 'badge badge--muted',
    default => 'badge',
  };
}

function is_stale(?string $lastSeen, string $staleSql, PDO $pdo): bool {
  // Avoid per-row SQL calls; just do a quick PHP compare:
  if (!$lastSeen) return true;
  $last = strtotime($lastSeen);
  if ($last === false) return true;

  // Interpret staleSql in php (approx): match your options
  $threshold = match (true) {
    str_contains($staleSql, '10 MINUTE') => time() - 10*60,
    str_contains($staleSql, '2 HOUR') => time() - 2*3600,
    str_contains($staleSql, '24 HOUR') => time() - 24*3600,
    default => time() - 30*60,
  };
  return $last < $threshold;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Nodes • Provisioning Portal</title>
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
              <h1>Nodes</h1>
              <p class="muted">Fleet posture across sites/providers. Use filters to slice quickly.</p>
            </div>

            <div class="page-header__actions">
              <form method="get" action="/nodes.php" class="inline" style="display:flex; gap:10px; flex-wrap:wrap;">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search node, site, CPU, IP…" />

                <select name="site">
                  <option value="all" <?= $site==='all'?'selected':'' ?>>All sites</option>
                  <?php foreach ($sites as $s): ?>
                    <?php $sv = (string)$s['site']; ?>
                    <option value="<?= htmlspecialchars($sv) ?>" <?= $site===$sv?'selected':'' ?>>
                      <?= htmlspecialchars($sv) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="provider">
                  <option value="all" <?= $provider==='all'?'selected':'' ?>>All providers</option>
                  <?php foreach ($providers as $p): ?>
                    <?php $pv = (string)$p['provider']; ?>
                    <option value="<?= htmlspecialchars($pv) ?>" <?= $provider===$pv?'selected':'' ?>>
                      <?= htmlspecialchars($pv) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="status">
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All status</option>
                  <option value="healthy" <?= $status==='healthy'?'selected':'' ?>>Healthy</option>
                  <option value="degraded" <?= $status==='degraded'?'selected':'' ?>>Degraded</option>
                  <option value="warning" <?= $status==='warning'?'selected':'' ?>>Warning</option>
                  <option value="down" <?= $status==='down'?'selected':'' ?>>Down</option>
                  <option value="unknown" <?= $status==='unknown'?'selected':'' ?>>Unknown</option>
                </select>

                <select name="stale">
                  <option value="10m" <?= $window==='10m'?'selected':'' ?>>Stale: 10m</option>
                  <option value="30m" <?= $window==='30m'?'selected':'' ?>>Stale: 30m</option>
                  <option value="2h" <?= $window==='2h'?'selected':'' ?>>Stale: 2h</option>
                  <option value="24h" <?= $window==='24h'?'selected':'' ?>>Stale: 24h</option>
                </select>

                <button class="btn" type="submit">Apply</button>
                <a class="btn" href="/nodes.php">Reset</a>
              </form>
            </div>
          </header>

          <!-- KPI Widgets -->
          <section class="kpi-grid" aria-label="Node KPIs">
            <article class="kpi-card">
              <h2>Total nodes</h2>
              <p><?= $nodesTotal ?></p>
              <p class="muted small">Fleet count</p>
            </article>

            <article class="kpi-card">
              <h2>Healthy</h2>
              <p><?= $nodesHealthy ?></p>
              <p class="muted small">Stable posture</p>
            </article>

            <article class="kpi-card">
              <h2>Degraded/Warning</h2>
              <p><?= $nodesDegraded ?></p>
              <p class="muted small">Needs attention</p>
            </article>

            <article class="kpi-card">
              <h2>Down / Stale</h2>
              <p><?= $nodesDown ?> / <?= $nodesStale ?></p>
              <p class="muted small">Down vs missed heartbeat (<?= htmlspecialchars($window) ?>)</p>
            </article>
          </section>

          <section class="grid-2">
            <!-- Site Summary -->
            <section class="panel">
              <header class="panel__header">
                <h2>Sites</h2>
                <span class="muted small">Health rollup by location</span>
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
                      <th>Unknown</th>
                      <th>Last seen</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$siteSummary): ?>
                      <tr><td colspan="7" class="muted">No nodes yet.</td></tr>
                    <?php else: foreach ($siteSummary as $s): ?>
                      <tr>
                        <td><?= htmlspecialchars($s['site']) ?></td>
                        <td><?= (int)$s['total'] ?></td>
                        <td><?= (int)$s['healthy'] ?></td>
                        <td><?= (int)$s['degraded'] ?></td>
                        <td><?= (int)$s['down_count'] ?></td>
                        <td><?= (int)$s['unknown_count'] ?></td>
                        <td class="muted small"><?= htmlspecialchars((string)($s['last_seen'] ?? '—')) ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </section>

            <!-- Quick Actions / Notes -->
            <section class="panel">
              <header class="panel__header">
                <h2>Operator notes</h2>
                <span class="muted small">Triage posture</span>
              </header>
              <div class="panel__body">
                <ul style="margin:0; padding-left:18px; color: rgba(255,255,255,0.78);">
                  <li><strong>Down</strong> nodes are priority 1—verify upstream reachability, hypervisor state, then storage.</li>
                  <li><strong>Stale</strong> nodes missed heartbeat—often agent/service or network path. Validate exporter/agent.</li>
                  <li><strong>Degraded</strong> nodes—plan remediation before it becomes downtime.</li>
                  <li>Mgmt IP is role-gated by default.</li>
                </ul>
              </div>
            </section>
          </section>

          <!-- Nodes Table -->
          <section class="panel">
            <header class="panel__header">
              <h2>Fleet</h2>
              <span class="muted small">Showing up to <?= $limit ?> nodes • filtered view</span>
            </header>

            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Site</th>
                    <th>Provider</th>
                    <th>Status</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Storage</th>
                    <?php if ($canSeeMgmt): ?>
                      <th>Mgmt IP</th>
                    <?php endif; ?>
                    <th>Last seen</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$nodes): ?>
                    <tr><td colspan="<?= $canSeeMgmt ? '9' : '8' ?>" class="muted">No nodes match your filter.</td></tr>
                  <?php else: foreach ($nodes as $n): ?>
                    <?php
                      $stale = is_stale($n['last_seen_at'] ?? null, $staleSql, $pdo);
                      $statusVal = (string)($n['status'] ?? 'unknown');
                      $badgeCls = status_badge_class($statusVal);
                      $statusLabel = $stale && $statusVal !== 'down' ? ($statusVal . ' • stale') : $statusVal;
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($n['name']) ?></td>
                      <td><?= htmlspecialchars($n['site']) ?></td>
                      <td class="muted"><?= htmlspecialchars((string)($n['provider'] ?? '—')) ?></td>
                      <td>
                        <span class="<?= $badgeCls ?>"><?= htmlspecialchars($statusLabel) ?></span>
                      </td>
                      <td class="muted small">
                        <?= htmlspecialchars((string)($n['cpu_model'] ?? '—')) ?>
                        <?= $n['cpu_cores'] !== null ? (' • ' . (int)$n['cpu_cores'] . 'c') : '' ?>
                      </td>
                      <td class="muted small"><?= $n['ram_gb'] !== null ? ((int)$n['ram_gb'] . ' GB') : '—' ?></td>
                      <td class="muted small"><?= $n['storage_gb'] !== null ? ((int)$n['storage_gb'] . ' GB') : '—' ?></td>

                      <?php if ($canSeeMgmt): ?>
                        <td class="muted small"><?= htmlspecialchars((string)($n['mgmt_ip'] ?? '—')) ?></td>
                      <?php endif; ?>

                      <td class="muted small"><?= htmlspecialchars((string)($n['last_seen_at'] ?? '—')) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </section>

        </section>

        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
</body>
</html>