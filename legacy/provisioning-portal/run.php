<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing run id');
}

$runStmt = $pdo->prepare(
  "SELECT r.id, r.status, r.created_at, r.started_at, r.finished_at, r.duration_ms,
          r.initiated_via, r.error_code, r.error_message, r.meta,
          a.name AS automation_name,
          u.email AS initiated_by
   FROM automation_runs r
   JOIN automations a ON a.id = r.automation_id
   LEFT JOIN users u ON u.id = r.initiated_by_user_id
   WHERE r.id = ?
   LIMIT 1"
);
$runStmt->execute([$id]);
$run = $runStmt->fetch();

if (!$run) {
  http_response_code(404);
  exit('Run not found');
}

$logsStmt = $pdo->prepare(
  "SELECT created_at, level, message, context
   FROM automation_run_logs
   WHERE run_id = ?
   ORDER BY created_at ASC
   LIMIT 500"
);
$logsStmt->execute([$id]);
$logs = $logsStmt->fetchAll();

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
  <title>Run #<?= (int)$run['id'] ?> • Provisioning Portal</title>
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
              <h1>Run #<?= (int)$run['id'] ?></h1>
              <p class="muted"><?= htmlspecialchars($run['automation_name']) ?></p>
            </div>
            <div class="page-header__actions">
              <a class="btn" href="/runs.php">Back to Runs</a>
            </div>
          </header>

          <section class="kpi-grid" aria-label="Run summary">
            <article class="kpi-card">
              <h2>Status</h2>
              <p><span class="<?= badge_class((string)$run['status']) ?>"><?= htmlspecialchars((string)$run['status']) ?></span></p>
              <p class="muted small"><?= htmlspecialchars((string)$run['created_at']) ?></p>
            </article>

            <article class="kpi-card">
              <h2>Duration</h2>
              <p><?= htmlspecialchars(fmt_duration($run['duration_ms'] !== null ? (int)$run['duration_ms'] : null)) ?></p>
              <p class="muted small">Started <?= htmlspecialchars((string)($run['started_at'] ?? '—')) ?></p>
            </article>

            <article class="kpi-card">
              <h2>Initiated</h2>
              <p><?= htmlspecialchars((string)($run['initiated_via'] ?? '—')) ?></p>
              <p class="muted small">By <?= htmlspecialchars((string)($run['initiated_by'] ?? 'system')) ?></p>
            </article>

            <article class="kpi-card">
              <h2>Error</h2>
              <p><?= htmlspecialchars((string)($run['error_code'] ?? '—')) ?></p>
              <p class="muted small"><?= htmlspecialchars((string)($run['error_message'] ?? '—')) ?></p>
            </article>
          </section>

          <section class="panel">
            <header class="panel__header">
              <h2>Logs</h2>
              <span class="muted small">Showing up to 500 events</span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Level</th>
                    <th>Message</th>
                    <th>Context</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$logs): ?>
                    <tr><td colspan="4" class="muted">No logs yet for this run.</td></tr>
                  <?php else: foreach ($logs as $l): ?>
                    <tr>
                      <td class="muted small"><?= htmlspecialchars((string)$l['created_at']) ?></td>
                      <td class="muted small"><?= htmlspecialchars((string)$l['level']) ?></td>
                      <td><?= htmlspecialchars((string)$l['message']) ?></td>
                      <td class="muted small">
                        <?php
                          $ctx = $l['context'];
                          if ($ctx === null || $ctx === '') echo '—';
                          else echo htmlspecialchars(is_string($ctx) ? $ctx : json_encode($ctx));
                        ?>
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