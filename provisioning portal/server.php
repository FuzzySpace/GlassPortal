<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: /hardware.php');
    exit;
}

// ---- Load server with all relations ----
$stmt = $pdo->prepare("
    SELECT
        n.*,
        d.id   AS dc_id,   d.name AS dc_name,   d.code AS dc_code,
        d.city AS dc_city, d.country AS dc_country, d.address AS dc_address,
        d.contact_name AS dc_contact, d.contact_email AS dc_contact_email,
        r.id   AS r_id,    r.name AS rack_name,  r.row_label AS rack_row,
        r.total_units AS rack_total_units,
        c.id   AS cust_id, c.name AS customer_name,
        c.service_level, c.company_type AS cust_type,
        c.contact_name AS cust_contact, c.contact_email AS cust_email,
        c.contact_phone AS cust_phone,
        c.account_status AS cust_status, c.mrr_cents
    FROM nodes n
    LEFT JOIN datacenters d ON d.id = n.datacenter_id
    LEFT JOIN racks        r ON r.id = n.rack_id
    LEFT JOIN customers    c ON c.id = n.customer_id
    WHERE n.id = ?
");
$stmt->execute([$id]);
$server = $stmt->fetch();

if (!$server) {
    header('Location: /hardware.php');
    exit;
}

// ---- Recent automation runs on this node ----
$recentRuns = [];
try {
    $runsStmt = $pdo->prepare("
        SELECT ar.id, ar.status, ar.created_at, ar.duration_ms,
               a.name AS automation_name
        FROM automation_runs ar
        JOIN automations a ON a.id = ar.automation_id
        WHERE JSON_SEARCH(ar.meta, 'one', ?, NULL, '$.targets') IS NOT NULL
           OR ar.meta->>'$.node_id' = ?
        ORDER BY ar.created_at DESC
        LIMIT 10
    ");
    $runsStmt->execute([$server['name'], (string)$id]);
    $recentRuns = $runsStmt->fetchAll();
} catch (\PDOException $e) { /* meta column not yet added via migration */ }

$canSeeMgmt = in_array($role, ['owner','admin','security'], true);

function status_badge_class(string $s): string {
    return match ($s) {
        'healthy', 'success', 'active' => 'badge badge--success',
        'degraded', 'warning'           => 'badge badge--warn',
        'down', 'failed', 'error'       => 'badge badge--danger',
        'running'                       => 'badge badge--info',
        default                         => 'badge badge--muted',
    };
}

$statusCls = status_badge_class((string)($server['status'] ?? 'unknown'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($server['name']) ?> • NOC Portal</title>
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
            <?php if ($server['dc_name']): ?>
              <span class="muted"> / </span>
              <a class="link" href="/datacenters.php?id=<?= (int)$server['dc_id'] ?>"><?= htmlspecialchars($server['dc_code'] ?? $server['dc_name']) ?></a>
            <?php endif; ?>
            <?php if ($server['rack_name']): ?>
              <span class="muted"> / </span>
              <a class="link" href="/rack.php?id=<?= (int)$server['r_id'] ?>"><?= htmlspecialchars($server['rack_name']) ?></a>
            <?php endif; ?>
            <span class="muted"> / </span>
            <span><?= htmlspecialchars($server['name']) ?></span>
          </nav>

          <!-- Page header -->
          <header class="page-header">
            <div class="page-header__titles">
              <h1>
                <?= htmlspecialchars($server['name']) ?>
                <span class="<?= $statusCls ?>"><?= htmlspecialchars((string)($server['status'] ?? 'unknown')) ?></span>
              </h1>
              <p class="muted">
                <?= htmlspecialchars(trim(($server['make'] ?? '') . ' ' . ($server['model'] ?? ''))) ?: 'No hardware model recorded' ?>
                <?php if ($server['role']): ?>
                  &nbsp;•&nbsp;<span class="badge badge--muted"><?= htmlspecialchars($server['role']) ?></span>
                <?php endif; ?>
              </p>
            </div>
            <div class="page-header__actions">
              <?php if (in_array($role, ['owner','admin','operator'], true)): ?>
                <a class="btn btn--primary" href="/automations.php?node=<?= (int)$id ?>">Run Automation</a>
                <a class="btn" href="/provision.php?node=<?= (int)$id ?>">Provisioning Checklist</a>
                <a class="btn" href="/node_edit.php?id=<?= (int)$id ?>">Edit Server</a>
              <?php endif; ?>
              <?php if ($server['rack_name']): ?>
                <a class="btn" href="/rack.php?id=<?= (int)$server['r_id'] ?>">View in Rack</a>
              <?php endif; ?>
            </div>
          </header>

          <!-- Detail grid: 3 cols -->
          <div class="server-detail-grid">

            <!-- Col 1: Location -->
            <section class="panel">
              <header class="panel__header"><h2>Location</h2></header>
              <div class="panel__body">
                <dl class="detail-dl">
                  <dt>Data Center</dt>
                  <dd>
                    <?php if ($server['dc_name']): ?>
                      <a class="link" href="/datacenters.php?id=<?= (int)$server['dc_id'] ?>">
                        <?= htmlspecialchars($server['dc_name']) ?>
                        <?= $server['dc_code'] ? ' (' . htmlspecialchars($server['dc_code']) . ')' : '' ?>
                      </a>
                      <?php if ($server['dc_city']): ?>
                        <br><span class="muted small">
                          <?= htmlspecialchars($server['dc_city']) ?>
                          <?= $server['dc_country'] ? ', ' . htmlspecialchars($server['dc_country']) : '' ?>
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="muted">Not assigned</span>
                    <?php endif; ?>
                  </dd>

                  <dt>Rack</dt>
                  <dd>
                    <?php if ($server['rack_name']): ?>
                      <a class="link" href="/rack.php?id=<?= (int)$server['r_id'] ?>"><?= htmlspecialchars($server['rack_name']) ?></a>
                      <?php if ($server['rack_row']): ?>
                        <span class="muted small"> — Row <?= htmlspecialchars($server['rack_row']) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="muted">Not racked</span>
                    <?php endif; ?>
                  </dd>

                  <dt>Rack Unit</dt>
                  <dd>
                    <?php if ($server['rack_unit_start']): ?>
                      U<?= (int)$server['rack_unit_start'] ?>
                      <?php if ((int)$server['rack_unit_size'] > 1): ?>
                        –U<?= (int)$server['rack_unit_start'] + (int)$server['rack_unit_size'] - 1 ?>
                      <?php endif; ?>
                      (<?= (int)$server['rack_unit_size'] ?>U)
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </dd>

                  <dt>Site</dt>
                  <dd><?= $server['site'] ? htmlspecialchars($server['site']) : '<span class="muted">—</span>' ?></dd>

                  <dt>Provider</dt>
                  <dd><?= $server['provider'] ? htmlspecialchars($server['provider']) : '<span class="muted">—</span>' ?></dd>
                </dl>
              </div>
            </section>

            <!-- Col 2: Hardware -->
            <section class="panel">
              <header class="panel__header"><h2>Hardware</h2></header>
              <div class="panel__body">
                <dl class="detail-dl">
                  <dt>Make / Model</dt>
                  <dd><?= htmlspecialchars(trim(($server['make'] ?? '') . ' ' . ($server['model'] ?? ''))) ?: '<span class="muted">—</span>' ?></dd>

                  <dt>Asset Tag</dt>
                  <dd><?= $server['asset_tag'] ? htmlspecialchars($server['asset_tag']) : '<span class="muted">—</span>' ?></dd>

                  <dt>Serial Number</dt>
                  <dd><?= $server['serial_number'] ? htmlspecialchars($server['serial_number']) : '<span class="muted">—</span>' ?></dd>

                  <dt>CPU</dt>
                  <dd>
                    <?= htmlspecialchars((string)($server['cpu_model'] ?? '—')) ?>
                    <?= $server['cpu_cores'] ? ' &nbsp;•&nbsp; ' . (int)$server['cpu_cores'] . ' cores' : '' ?>
                  </dd>

                  <dt>Memory</dt>
                  <dd><?= $server['ram_gb'] ? (int)$server['ram_gb'] . ' GB RAM' : '<span class="muted">—</span>' ?></dd>

                  <dt>Storage</dt>
                  <dd><?= $server['storage_gb'] ? (int)$server['storage_gb'] . ' GB' : '<span class="muted">—</span>' ?></dd>

                  <dt>OS</dt>
                  <dd>
                    <?php if ($server['os_type'] || $server['os_version']): ?>
                      <?= htmlspecialchars(trim(($server['os_type'] ?? '') . ' ' . ($server['os_version'] ?? ''))) ?>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </dd>

                  <?php if ($canSeeMgmt && $server['mgmt_ip']): ?>
                    <dt>Mgmt IP</dt>
                    <dd class="font-mono"><?= htmlspecialchars($server['mgmt_ip']) ?></dd>
                  <?php endif; ?>
                </dl>
              </div>
            </section>

            <!-- Col 3: Customer -->
            <section class="panel">
              <header class="panel__header">
                <h2>Customer</h2>
                <?php if ($server['cust_id']): ?>
                  <a class="link" href="/customer.php?id=<?= (int)$server['cust_id'] ?>">View →</a>
                <?php endif; ?>
              </header>
              <div class="panel__body">
                <?php if ($server['customer_name']): ?>
                  <dl class="detail-dl">
                    <dt>Name</dt>
                    <dd>
                      <a class="link" href="/customer.php?id=<?= (int)$server['cust_id'] ?>">
                        <?= htmlspecialchars($server['customer_name']) ?>
                      </a>
                    </dd>

                    <dt>Account Status</dt>
                    <dd>
                      <span class="<?= status_badge_class((string)($server['cust_status'] ?? 'unknown')) ?>">
                        <?= htmlspecialchars((string)($server['cust_status'] ?? '—')) ?>
                      </span>
                    </dd>

                    <dt>Service Level</dt>
                    <dd><?= $server['service_level'] ? '<span class="badge badge--muted">'.htmlspecialchars($server['service_level']).'</span>' : '<span class="muted">—</span>' ?></dd>

                    <dt>Type</dt>
                    <dd><?= $server['cust_type'] ? htmlspecialchars($server['cust_type']) : '<span class="muted">—</span>' ?></dd>

                    <?php if ($server['cust_contact']): ?>
                      <dt>Contact</dt>
                      <dd><?= htmlspecialchars($server['cust_contact']) ?></dd>
                    <?php endif; ?>

                    <?php if (in_array($role, ['owner','admin'], true) && $server['cust_email']): ?>
                      <dt>Email</dt>
                      <dd><a class="link" href="mailto:<?= htmlspecialchars($server['cust_email']) ?>"><?= htmlspecialchars($server['cust_email']) ?></a></dd>
                    <?php endif; ?>

                    <?php if (in_array($role, ['owner','admin'], true) && $server['mrr_cents']): ?>
                      <dt>MRR</dt>
                      <dd>$<?= number_format((int)$server['mrr_cents'] / 100, 2) ?>/mo</dd>
                    <?php endif; ?>
                  </dl>
                <?php else: ?>
                  <p class="muted">No customer assigned to this server.</p>
                  <?php if (in_array($role, ['owner','admin'], true)): ?>
                    <a class="btn" href="/hardware.php">Assign via Hardware →</a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </section>

          </div>

          <!-- Status & monitoring row -->
          <div class="server-status-row">
            <section class="panel">
              <header class="panel__header"><h2>System Status</h2></header>
              <div class="panel__body">
                <dl class="detail-dl detail-dl--row">
                  <dt>Status</dt>
                  <dd><span class="<?= $statusCls ?>"><?= htmlspecialchars((string)($server['status'] ?? 'unknown')) ?></span></dd>

                  <dt>Last Seen</dt>
                  <dd class="muted small"><?= htmlspecialchars((string)($server['last_seen_at'] ?? '—')) ?></dd>

                  <dt>Added</dt>
                  <dd class="muted small"><?= htmlspecialchars((string)($server['created_at'] ?? '—')) ?></dd>

                  <dt>Updated</dt>
                  <dd class="muted small"><?= htmlspecialchars((string)($server['updated_at'] ?? '—')) ?></dd>
                </dl>
              </div>
            </section>

            <?php if ($server['notes']): ?>
            <section class="panel">
              <header class="panel__header"><h2>Notes</h2></header>
              <div class="panel__body">
                <p style="white-space: pre-wrap; color: rgba(255,255,255,0.78);"><?= htmlspecialchars($server['notes']) ?></p>
              </div>
            </section>
            <?php endif; ?>
          </div>

          <!-- Recent automation runs -->
          <section class="panel">
            <header class="panel__header">
              <h2>Recent Automation Runs</h2>
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
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$recentRuns): ?>
                    <tr><td colspan="5" class="muted">No automation runs recorded for this node.</td></tr>
                  <?php else: foreach ($recentRuns as $r): ?>
                    <tr>
                      <td class="muted small"><?= htmlspecialchars($r['created_at']) ?></td>
                      <td><?= htmlspecialchars($r['automation_name']) ?></td>
                      <td><span class="<?= status_badge_class((string)$r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                      <td class="muted small"><?= $r['duration_ms'] !== null ? (int)$r['duration_ms'].' ms' : '—' ?></td>
                      <td><a class="link" href="/run.php?id=<?= (int)$r['id'] ?>">Logs →</a></td>
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
