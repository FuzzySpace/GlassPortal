<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/auth/rbac.php';
require_once __DIR__ . '/database/connection.php';

require_role(['owner','admin','security']);

// ---- Filters ----
$q       = trim((string)($_GET['q'] ?? ''));
$status  = trim((string)($_GET['status'] ?? 'all')); // all|success|fail
$window  = trim((string)($_GET['window'] ?? '24h')); // 1h|24h|7d|30d
$ip      = trim((string)($_GET['ip'] ?? ''));
$limit   = 100;

$windowSql = match ($window) {
  '1h'  => "INTERVAL 1 HOUR",
  '7d'  => "INTERVAL 7 DAY",
  '30d' => "INTERVAL 30 DAY",
  default => "INTERVAL 24 HOUR",
};

$where = [];
$params = [];

// time window
$where[] = "l.created_at >= (NOW() - $windowSql)";

// status filter
if ($status === 'success') {
  $where[] = "l.success = 1";
} elseif ($status === 'fail') {
  $where[] = "l.success = 0";
}

// IP filter
if ($ip !== '') {
  $where[] = "l.ip = ?";
  $params[] = $ip;
}

// search
if ($q !== '') {
  $where[] = "(l.email_attempted LIKE ? OR l.fail_reason LIKE ? OR u.email LIKE ? OR l.user_agent LIKE ?)";
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like, $like);
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ---- KPI Cards ----
$kpiBaseSql = "FROM auth_logins l $whereSql";

$stmt = $pdo->prepare("SELECT COUNT(*) $kpiBaseSql");
$stmt->execute($params);
$totalAttempts = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) $kpiBaseSql AND l.success=1");
$stmt->execute($params);
$successAttempts = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) $kpiBaseSql AND l.success=0");
$stmt->execute($params);
$failAttempts = (int)$stmt->fetchColumn();

$failRate = $totalAttempts > 0 ? round(($failAttempts / $totalAttempts) * 100, 1) : 0.0;

// Top failing IPs (within window)
$topIpParams = [];
$topIpWhere = ["l.created_at >= (NOW() - $windowSql)", "l.success = 0"];
if ($q !== '') {
  $topIpWhere[] = "(l.email_attempted LIKE ? OR l.fail_reason LIKE ? OR u.email LIKE ? OR l.user_agent LIKE ?)";
  $like = '%' . $q . '%';
  array_push($topIpParams, $like, $like, $like, $like);
}
$topIpWhereSql = "WHERE " . implode(" AND ", $topIpWhere);

$topIps = $pdo->prepare(
  "SELECT l.ip, COUNT(*) AS fails
   FROM auth_logins l
   LEFT JOIN users u ON u.id = l.user_id
   $topIpWhereSql
   GROUP BY l.ip
   ORDER BY fails DESC
   LIMIT 8"
);
$topIps->execute($topIpParams);
$topIpsRows = $topIps->fetchAll();

// Recent login attempts table
$sql = "
  SELECT l.created_at, l.email_attempted, l.success, l.fail_reason, l.ip, l.user_agent,
         u.email AS user_email
  FROM auth_logins l
  LEFT JOIN users u ON u.id = l.user_id
  $whereSql
  ORDER BY l.created_at DESC
  LIMIT $limit
";
$attemptsStmt = $pdo->prepare($sql);
$attemptsStmt->execute($params);
$attempts = $attemptsStmt->fetchAll();

// Remember token summary
$tokensSummary = $pdo->query(
  "SELECT
     COUNT(*) AS total,
     SUM(revoked_at IS NULL AND expires_at > NOW()) AS active,
     SUM(revoked_at IS NOT NULL) AS revoked,
     SUM(expires_at <= NOW()) AS expired
   FROM auth_remember_tokens"
)->fetch();

// Helper for status badge classes
function badge_class_login(int $success): string {
  return $success === 1 ? 'badge badge--success' : 'badge badge--danger';
}
function short_ua(?string $ua): string {
  $ua = (string)$ua;
  if ($ua === '') return '—';
  return mb_strlen($ua) > 72 ? (mb_substr($ua, 0, 72) . '…') : $ua;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Access • Provisioning Portal</title>
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
              <h1>Access</h1>
              <p class="muted">Authentication telemetry: attempts, failures, and token posture.</p>
            </div>

            <div class="page-header__actions">
              <form method="get" action="/access.php" class="inline" style="display:flex; gap:10px; flex-wrap:wrap;">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search email, reason, UA…" />
                <input type="text" name="ip" value="<?= htmlspecialchars($ip) ?>" placeholder="IP filter (optional)" style="max-width:180px;" />

                <select name="status">
                  <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
                  <option value="success" <?= $status==='success'?'selected':'' ?>>Success</option>
                  <option value="fail" <?= $status==='fail'?'selected':'' ?>>Fail</option>
                </select>

                <select name="window">
                  <option value="1h" <?= $window==='1h'?'selected':'' ?>>Last 1h</option>
                  <option value="24h" <?= $window==='24h'?'selected':'' ?>>Last 24h</option>
                  <option value="7d" <?= $window==='7d'?'selected':'' ?>>Last 7d</option>
                  <option value="30d" <?= $window==='30d'?'selected':'' ?>>Last 30d</option>
                </select>

                <button class="btn" type="submit">Apply</button>
                <a class="btn" href="/access.php">Reset</a>
              </form>
            </div>
          </header>

          <!-- KPI Widgets -->
          <section class="kpi-grid" aria-label="Access KPIs">
            <article class="kpi-card">
              <h2>Attempts</h2>
              <p><?= $totalAttempts ?></p>
              <p class="muted small">Window: <?= htmlspecialchars($window) ?></p>
            </article>

            <article class="kpi-card">
              <h2>Success</h2>
              <p><?= $successAttempts ?></p>
              <p class="muted small">Success rate <?= $totalAttempts>0 ? (100 - $failRate) : 0 ?>%</p>
            </article>

            <article class="kpi-card">
              <h2>Failures</h2>
              <p><?= $failAttempts ?></p>
              <p class="muted small">Fail rate <?= $failRate ?>%</p>
            </article>

            <article class="kpi-card">
              <h2>Remember Tokens</h2>
              <p><?= (int)$tokensSummary['active'] ?></p>
              <p class="muted small">
                Active <?= (int)$tokensSummary['active'] ?> • Revoked <?= (int)$tokensSummary['revoked'] ?> • Expired <?= (int)$tokensSummary['expired'] ?>
              </p>
            </article>
          </section>

          <section class="grid-2">
            <!-- Top failing IPs -->
            <section class="panel">
              <header class="panel__header">
                <h2>Top failing IPs</h2>
                <span class="muted small">Within <?= htmlspecialchars($window) ?> window</span>
              </header>
              <div class="panel__body">
                <table class="table">
                  <thead>
                    <tr>
                      <th>IP</th>
                      <th>Failures</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$topIpsRows): ?>
                      <tr><td colspan="3" class="muted">No failures in this window.</td></tr>
                    <?php else: foreach ($topIpsRows as $r): ?>
                      <tr>
                        <td class="muted small"><?= htmlspecialchars((string)($r['ip'] ?? 'unknown')) ?></td>
                        <td><?= (int)$r['fails'] ?></td>
                        <td>
                          <?php if (!empty($r['ip'])): ?>
                            <a class="link" href="/access.php?window=<?= urlencode($window) ?>&status=fail&ip=<?= urlencode((string)$r['ip']) ?>">Investigate</a>
                          <?php else: ?>
                            <span class="muted small">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </section>

            <!-- Guidance -->
            <section class="panel">
              <header class="panel__header">
                <h2>Operator notes</h2>
                <span class="muted small">Triage playbook</span>
              </header>
              <div class="panel__body">
                <ul style="margin:0; padding-left:18px; color: rgba(255,255,255,0.78);">
                  <li>Spikes in <strong>failures</strong> + repeated IPs → consider firewall/rate-limit escalation.</li>
                  <li>Repeated <strong>bad_password</strong> on a known user → validate account owner and rotate password.</li>
                  <li><strong>no_such_user</strong> bursts → credential stuffing; keep lockout strict.</li>
                  <li>Remember tokens should remain low; revoke if device is lost.</li>
                </ul>
              </div>
            </section>
          </section>

          <!-- Recent attempts -->
          <section class="panel">
            <header class="panel__header">
              <h2>Recent login attempts</h2>
              <span class="muted small">Showing up to <?= $limit ?> rows</span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Email Attempted</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>IP</th>
                    <th>User Agent</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$attempts): ?>
                    <tr><td colspan="7" class="muted">No records found for this filter set.</td></tr>
                  <?php else: foreach ($attempts as $a): ?>
                    <tr>
                      <td class="muted small"><?= htmlspecialchars($a['created_at']) ?></td>
                      <td><?= htmlspecialchars($a['email_attempted']) ?></td>
                      <td class="muted small"><?= htmlspecialchars((string)($a['user_email'] ?? '—')) ?></td>
                      <td>
                        <span class="<?= badge_class_login((int)$a['success']) ?>">
                          <?= ((int)$a['success'] === 1) ? 'success' : 'fail' ?>
                        </span>
                      </td>
                      <td class="muted small"><?= htmlspecialchars((string)($a['fail_reason'] ?? '—')) ?></td>
                      <td class="muted small"><?= htmlspecialchars((string)($a['ip'] ?? '—')) ?></td>
                      <td class="muted small" title="<?= htmlspecialchars((string)($a['user_agent'] ?? '')) ?>">
                        <?= htmlspecialchars(short_ua($a['user_agent'] ?? null)) ?>
                      </td>
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