<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

// Only security-cleared roles can view audit logs
if (!in_array($role, ['owner', 'admin', 'security'], true)) {
    header('Location: /dashboard.php');
    exit;
}

// ---- Filters ----
$q       = trim((string)($_GET['q']       ?? ''));
$action  = trim((string)($_GET['action']  ?? 'all'));
$window  = trim((string)($_GET['window']  ?? '7d'));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset  = ($page - 1) * $perPage;

$windowSql = match ($window) {
    '1h'  => "INTERVAL 1 HOUR",
    '24h' => "INTERVAL 24 HOUR",
    '30d' => "INTERVAL 30 DAY",
    default => "INTERVAL 7 DAY",
};

$where  = ["a.created_at >= (NOW() - $windowSql)"];
$params = [];

if ($action !== 'all' && $action !== '') {
    $where[]  = "a.action = ?";
    $params[] = $action;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[]  = "(u.email LIKE ? OR a.action LIKE ? OR a.target_type LIKE ? OR a.ip LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}

$whereSql = "WHERE " . implode(" AND ", $where);

// ---- Distinct action types for filter ----
$actionTypes = $pdo->query(
    "SELECT DISTINCT action FROM audit_logs ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN);

// ---- KPIs ----
$totalEventsStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE a.created_at >= (NOW() - $windowSql)");
$totalEventsStmt->execute();
$totalEvents = (int)$totalEventsStmt->fetchColumn();

$uniqueActorsStmt = $pdo->prepare("SELECT COUNT(DISTINCT actor_user_id) FROM audit_logs a WHERE a.created_at >= (NOW() - $windowSql) AND actor_user_id IS NOT NULL");
$uniqueActorsStmt->execute();
$uniqueActors = (int)$uniqueActorsStmt->fetchColumn();

// ---- Count for pagination ----
$countParams = $params;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id $whereSql");
$countStmt->execute($countParams);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));

// ---- Main log query ----
$sql = "
    SELECT a.id, a.action, a.target_type, a.target_id, a.meta,
           a.ip, a.user_agent, a.created_at,
           u.email AS actor_email, u.role AS actor_role
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.actor_user_id
    $whereSql
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// ---- Recent login audit (auth_logins) ----
$loginsSql = "
    SELECT al.*, u.email AS matched_email
    FROM auth_logins al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.created_at >= (NOW() - INTERVAL 24 HOUR)
    ORDER BY al.created_at DESC
    LIMIT 50
";
$recentLogins = $pdo->query($loginsSql)->fetchAll();
$failedLogins  = array_filter($recentLogins, fn($r) => !$r['success']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Audit Log • NOC Portal</title>
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
              <h1>Audit Log</h1>
              <p class="muted">Portal activity trail — actions, logins, automation events.</p>
            </div>
          </header>

          <!-- KPIs -->
          <section class="kpi-grid" aria-label="Audit KPIs">
            <article class="kpi-card">
              <h2>Events (window)</h2>
              <p><?= number_format($totalEvents) ?></p>
              <p class="muted small">Selected time window</p>
            </article>
            <article class="kpi-card">
              <h2>Unique Actors</h2>
              <p><?= $uniqueActors ?></p>
              <p class="muted small">Authenticated users</p>
            </article>
            <article class="kpi-card">
              <h2>Failed Logins (24 h)</h2>
              <p><?= count($failedLogins) ?></p>
              <p class="muted small"><?= count($recentLogins) ?> total login attempts</p>
            </article>
          </section>

          <!-- Filters -->
          <section class="panel">
            <header class="panel__header">
              <h2>Portal Activity</h2>
              <span class="muted small"><?= number_format($totalFiltered) ?> events match filter</span>
            </header>
            <div class="panel__body">
              <form method="get" action="/audit.php" class="filter-bar">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search email, action, IP…" />
                <select name="action">
                  <option value="all" <?= $action==='all'?'selected':'' ?>>All actions</option>
                  <?php foreach ($actionTypes as $at): ?>
                    <option value="<?= htmlspecialchars($at) ?>" <?= $action===$at?'selected':'' ?>><?= htmlspecialchars($at) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="window">
                  <option value="1h"  <?= $window==='1h' ?'selected':'' ?>>Last 1 hour</option>
                  <option value="24h" <?= $window==='24h'?'selected':'' ?>>Last 24 hours</option>
                  <option value="7d"  <?= $window==='7d' ?'selected':'' ?>>Last 7 days</option>
                  <option value="30d" <?= $window==='30d'?'selected':'' ?>>Last 30 days</option>
                </select>
                <button class="btn" type="submit">Filter</button>
                <a class="btn" href="/audit.php">Reset</a>
              </form>

              <!-- Log table -->
              <table class="table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>IP</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$logs): ?>
                    <tr><td colspan="6" class="muted">No audit events in this window.</td></tr>
                  <?php else: foreach ($logs as $log): ?>
                    <tr>
                      <td class="muted small"><?= htmlspecialchars($log['created_at']) ?></td>
                      <td>
                        <?php if ($log['actor_email']): ?>
                          <span class="badge badge--muted"><?= htmlspecialchars($log['actor_role'] ?? '') ?></span>
                          <?= htmlspecialchars($log['actor_email']) ?>
                        <?php else: ?>
                          <span class="muted">system</span>
                        <?php endif; ?>
                      </td>
                      <td><code style="font-size:12px;"><?= htmlspecialchars($log['action']) ?></code></td>
                      <td class="muted small">
                        <?php if ($log['target_type']): ?>
                          <?= htmlspecialchars($log['target_type']) ?>
                          <?= $log['target_id'] ? ' #' . (int)$log['target_id'] : '' ?>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td class="muted small font-mono"><?= $log['ip'] ? htmlspecialchars($log['ip']) : '—' ?></td>
                      <td class="muted small">
                        <?php if ($log['meta']): ?>
                          <details>
                            <summary style="cursor:pointer;">meta</summary>
                            <pre style="font-size:11px; margin:4px 0 0; max-width:300px; overflow:auto;"><?= htmlspecialchars((string)$log['meta']) ?></pre>
                          </details>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>

              <!-- Pagination -->
              <?php if ($totalPages > 1): ?>
              <div style="display:flex; gap:8px; align-items:center; padding:12px 18px;" aria-label="Pagination">
                <?php if ($page > 1): ?>
                  <a class="btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Prev</a>
                <?php endif; ?>
                <span class="muted small">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                  <a class="btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </section>

          <!-- Recent login attempts -->
          <section class="panel">
            <header class="panel__header">
              <h2>Login Attempts (last 24 h)</h2>
              <span class="muted small"><?= count($failedLogins) ?> failed of <?= count($recentLogins) ?></span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr><th>Time</th><th>Email attempted</th><th>IP</th><th>Result</th><th>Reason</th></tr>
                </thead>
                <tbody>
                  <?php if (!$recentLogins): ?>
                    <tr><td colspan="5" class="muted">No login attempts in the last 24 hours.</td></tr>
                  <?php else: foreach ($recentLogins as $l): ?>
                    <tr>
                      <td class="muted small"><?= htmlspecialchars($l['created_at']) ?></td>
                      <td><?= htmlspecialchars((string)$l['email_attempted']) ?></td>
                      <td class="muted small font-mono"><?= htmlspecialchars((string)$l['ip']) ?></td>
                      <td>
                        <span class="<?= $l['success'] ? 'badge badge--success' : 'badge badge--danger' ?>">
                          <?= $l['success'] ? 'success' : 'failed' ?>
                        </span>
                      </td>
                      <td class="muted small"><?= $l['fail_reason'] ? htmlspecialchars($l['fail_reason']) : '—' ?></td>
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
