<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u = current_user();

// ---- Filters ----
$q          = trim((string)($_GET['q'] ?? ''));
$status     = trim((string)($_GET['status'] ?? 'all'));     // all|queued|running|success|failed|canceled
$window     = trim((string)($_GET['window'] ?? '24h'));     // 1h|24h|7d|30d
$automation = trim((string)($_GET['automation'] ?? 'all')); // all|<id>
$limit      = 200;

$windowSql = match ($window) {
  '1h'  => "INTERVAL 1 HOUR",
  '7d'  => "INTERVAL 7 DAY",
  '30d' => "INTERVAL 30 DAY",
  default => "INTERVAL 24 HOUR",
};

$where = [];
$params = [];

// time window is always applied for runs view (keeps it fast)
$where[] = "r.created_at >= (NOW() - $windowSql)";

if ($status !== 'all') {
  $where[] = "r.status = ?";
  $params[] = $status;
}

if ($automation !== 'all') {
  $where[] = "r.automation_id = ?";
  $params[] = (int)$automation;
}

if ($q !== '') {
  $where[] = "(a.name LIKE ? OR r.error_code LIKE ? OR r.error_message LIKE ? OR u.email LIKE ?)";
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like, $like);
}

$whereSql = "WHERE " . implode(" AND ", $where);

// dropdown options
$automationOptions = $pdo->query("SELECT id, name FROM automations ORDER BY name ASC")->fetchAll();

// ---- KPIs (within window + automation filter only — status is shown per-card) ----
$kpiWhere = ["created_at >= (NOW() - $windowSql)"];
$kpiParams = [];
if ($automation !== 'all') { $kpiWhere[] = "automation_id = ?"; $kpiParams[] = (int)$automation; }
$kpiWhereSql = "WHERE " . implode(" AND ", $kpiWhere);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_runs $kpiWhereSql");
$stmt->execute($kpiParams);
$totalRuns = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_runs $kpiWhereSql AND status = 'success'");
$stmt->execute($kpiParams);
$successRuns = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_runs $kpiWhereSql AND status = 'failed'");
$stmt->execute($kpiParams);
$failedRuns = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_runs $kpiWhereSql AND status = 'running'");
$stmt->execute($kpiParams);
$runningNow = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_runs $kpiWhereSql AND status = 'queued'");
$stmt->execute($kpiParams);
$queuedNow = (int)$stmt->fetchColumn();

$failRate = $totalRuns > 0 ? round(($failedRuns / $totalRuns) * 100, 1) : 0.0;

// ---- Main runs list ----
$sql = "
  SELECT r.id, r.status, r.created_at, r.started_at, r.finished_at, r.duration_ms,
         r.initiated_via, r.error_code, r.error_message,
         a.name AS automation_name,
         u.email AS initiated_by
  FROM automation_runs r
  JOIN automations a ON a.id = r.automation_id
  LEFT JOIN users u ON u.id = r.initiated_by_user_id
  $whereSql
  ORDER BY r.created_at DESC
  LIMIT $limit
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$runs = $stmt->fetchAll();

function badge_class(string $status): string {
  return match ($status) {
    'success' => 'badge badge--success',
    'failed', 'error' => 'badge badge--danger',
    'running' => 'badge badge--info',
    'queued' => 'badge badge--muted',
    'canceled' => 'badge badge--warn',
    default => 'badge',
  };
}

function fmt_duration(?int $ms): string {
  if ($ms === null) return '—';
  if ($ms < 1000) return $ms . ' ms';
  $s = $ms / 1000.0;
  if ($s < 60) return number_format($s, 2) . ' s';
  $m = floor($s / 60);
  $rem = $s - ($m * 60);
  return $m . 'm ' . number_format($rem, 1) . 's';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Runs • Provisioning Portal</title>
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
              <h1>Runs</h1>
              <p class="muted">Execution history across automations. Filter fast. Drill down when needed.</p>
            </div>

            <div class="page-header__actions">
              <form method="get" action="/runs.php" class="inline" style="display:flex; gap:10px; flex-wrap:wrap;">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search automation, error, user…" />

                <select name="automation">
                  <option value="all" <?= $automation==='all'?'selected':'' ?>>All automations</option>
                  <?php foreach ($automationOptions as $a): ?>
                    <?php $id = (string)$a['id']; ?>
                    <option value="<?= htmlspecialchars($id) ?>" <?= $automation===$id?'selected':'' ?>>
                      <?= htmlspecialchars($a['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="status">
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All status</option>
                  <option value="queued" <?= $status==='queued'?'selected':'' ?>>Queued</option>
                  <option value="running" <?= $status==='running'?'selected':'' ?>>Running</option>
                  <option value="success" <?= $status==='success'?'selected':'' ?>>Success</option>
                  <option value="failed" <?= $status==='failed'?'selected':'' ?>>Failed</option>
                  <option value="canceled" <?= $status==='canceled'?'selected':'' ?>>Canceled</option>
                </select>

                <select name="window">
                  <option value="1h" <?= $window==='1h'?'selected':'' ?>>Last 1h</option>
                  <option value="24h" <?= $window==='24h'?'selected':'' ?>>Last 24h</option>
                  <option value="7d" <?= $window==='7d'?'selected':'' ?>>Last 7d</option>
                  <option value="30d" <?= $window==='30d'?'selected':'' ?>>Last 30d</option>
                </select>

                <button class="btn" type="submit">Apply</button>
                <a class="btn" href="/runs.php">Reset</a>
              </form>
            </div>
          </header>

          <!-- KPI Widgets -->
          <section class="kpi-grid" aria-label="Run KPIs">
            <article class="kpi-card">
              <h2>Runs</h2>
              <p><?= $totalRuns ?></p>
              <p class="muted small">Window: <?= htmlspecialchars($window) ?></p>
            </article>

            <article class="kpi-card">
              <h2>Success</h2>
              <p><?= $successRuns ?></p>
              <p class="muted small">Success rate <?= $totalRuns>0 ? (100 - $failRate) : 0 ?>%</p>
            </article>

            <article class="kpi-card">
              <h2>Failures</h2>
              <p><?= $failedRuns ?></p>
              <p class="muted small">Fail rate <?= $failRate ?>%</p>
            </article>

            <article class="kpi-card">
              <h2>In-flight</h2>
              <p><?= $runningNow ?></p>
              <p class="muted small">Queued <?= $queuedNow ?></p>
            </article>
          </section>

          <!-- Runs Table -->
          <section class="panel">
            <header class="panel__header">
              <h2>Execution history</h2>
              <span class="muted small">Showing up to <?= $limit ?> rows</span>
            </header>

            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Automation</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Initiated</th>
                    <th>By</th>
                    <th>Error</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$runs): ?>
                    <tr><td colspan="7" class="muted">No runs match this filter set.</td></tr>
                  <?php else: foreach ($runs as $r): ?>
                    <?php
                      $id = (int)$r['id'];
                      $st = (string)$r['status'];
                      $err = trim((string)($r['error_code'] ?? ''));
                      $errMsg = trim((string)($r['error_message'] ?? ''));
                      $errText = $err !== '' ? $err : ($errMsg !== '' ? 'error' : '—');
                      $errTitle = $errMsg !== '' ? $errMsg : $err;
                    ?>
                    <tr>
                      <td class="muted small">
                        <a class="link" href="/run.php?id=<?= $id ?>"><?= htmlspecialchars((string)$r['created_at']) ?></a>
                      </td>
                      <td><?= htmlspecialchars($r['automation_name']) ?></td>
                      <td><span class="<?= badge_class($st) ?>"><?= htmlspecialchars($st) ?></span></td>
                      <td class="muted small"><?= htmlspecialchars(fmt_duration($r['duration_ms'] !== null ? (int)$r['duration_ms'] : null)) ?></td>
                      <td class="muted small"><?= htmlspecialchars((string)($r['initiated_via'] ?? '—')) ?></td>
                      <td class="muted small"><?= htmlspecialchars((string)($r['initiated_by'] ?? 'system')) ?></td>
                      <td class="muted small" title="<?= htmlspecialchars($errTitle) ?>"><?= htmlspecialchars($errText) ?></td>
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