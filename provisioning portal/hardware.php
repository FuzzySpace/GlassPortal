<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

// ---- Filters ----
$q          = trim((string)($_GET['q']          ?? ''));
$dcFilter   = trim((string)($_GET['dc']         ?? 'all'));
$rackFilter = trim((string)($_GET['rack']       ?? 'all'));
$custFilter = trim((string)($_GET['customer']   ?? 'all'));
$roleFilter = trim((string)($_GET['role']       ?? 'all'));
$statusFilter = trim((string)($_GET['status']   ?? 'all'));
$limit      = 300;

// ---- Filter option lists ----
$dcOptions   = $pdo->query("SELECT id, name, code FROM datacenters ORDER BY name ASC")->fetchAll();
$rackOptions = $pdo->query("SELECT r.id, r.name, d.name AS dc_name FROM racks r JOIN datacenters d ON d.id=r.datacenter_id ORDER BY d.name, r.name")->fetchAll();
$custOptions = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll();
$roleOptions = $pdo->query("SELECT DISTINCT role FROM nodes WHERE role IS NOT NULL AND role <> '' ORDER BY role ASC")->fetchAll();

// ---- Build WHERE clause ----
$where  = [];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[]  = "(n.name LIKE ? OR n.asset_tag LIKE ? OR n.serial_number LIKE ? OR n.make LIKE ? OR n.model LIKE ? OR n.mgmt_ip LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($dcFilter !== 'all') {
    $where[]  = "n.datacenter_id = ?";
    $params[] = (int)$dcFilter;
}
if ($rackFilter !== 'all') {
    $where[]  = "n.rack_id = ?";
    $params[] = (int)$rackFilter;
}
if ($custFilter !== 'all') {
    $where[]  = "n.customer_id = ?";
    $params[] = (int)$custFilter;
}
if ($roleFilter !== 'all') {
    $where[]  = "n.role = ?";
    $params[] = $roleFilter;
}
if ($statusFilter !== 'all') {
    $where[]  = "n.status = ?";
    $params[] = $statusFilter;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// ---- KPIs (unfiltered) ----
$totalAssets   = (int)$pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn();
$assignedCount = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE customer_id IS NOT NULL")->fetchColumn();
$unassigned    = $totalAssets - $assignedCount;
$rackCount     = (int)$pdo->query("SELECT COUNT(*) FROM racks")->fetchColumn();
$dcCount       = (int)$pdo->query("SELECT COUNT(*) FROM datacenters")->fetchColumn();

// ---- Main asset list ----
$sql = "
  SELECT
    n.id, n.name, n.status, n.role, n.site,
    n.asset_tag, n.serial_number, n.make, n.model,
    n.cpu_model, n.cpu_cores, n.ram_gb, n.storage_gb,
    n.os_type, n.os_version, n.mgmt_ip,
    n.rack_unit_start, n.rack_unit_size,
    n.last_seen_at, n.created_at,
    d.name  AS dc_name,
    d.code  AS dc_code,
    d.city  AS dc_city,
    r.name  AS rack_name,
    r.row_label AS rack_row,
    c.name  AS customer_name,
    c.service_level AS customer_service_level,
    c.account_status AS customer_account_status
  FROM nodes n
  LEFT JOIN datacenters d ON d.id = n.datacenter_id
  LEFT JOIN racks        r ON r.id = n.rack_id
  LEFT JOIN customers    c ON c.id = n.customer_id
  $whereSql
  ORDER BY d.name ASC, r.name ASC, n.rack_unit_start ASC, n.name ASC
  LIMIT $limit
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll();

// ---- DC summary ----
$dcSummary = $pdo->query("
  SELECT d.id, d.name, d.code, d.city, d.country, d.status,
         COUNT(n.id) AS node_count,
         COUNT(r.id) AS rack_count_val,
         SUM(n.status='healthy') AS healthy,
         SUM(n.status='down')    AS down_count
  FROM datacenters d
  LEFT JOIN racks r       ON r.datacenter_id = d.id
  LEFT JOIN nodes n       ON n.datacenter_id = d.id
  GROUP BY d.id
  ORDER BY d.name ASC
")->fetchAll();

$canSeeMgmt = in_array($role, ['owner','admin','security'], true);

function status_badge_class(string $s): string {
    return match ($s) {
        'healthy', 'success', 'active' => 'badge badge--success',
        'degraded', 'warning'           => 'badge badge--warn',
        'down', 'failed', 'error'       => 'badge badge--danger',
        'running'                       => 'badge badge--info',
        'queued', 'unknown'             => 'badge badge--muted',
        default                         => 'badge',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Hardware Inventory • NOC Portal</title>
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
              <h1>Hardware Inventory</h1>
              <p class="muted">Full asset register — data centers, racks, servers, and customer assignments.</p>
            </div>
            <div class="page-header__actions">
              <a class="btn btn--primary" href="/node_edit.php">+ Add Server</a>
              <?php if (in_array($role, ['owner','admin'], true)): ?>
                <a class="btn" href="/datacenters.php">Manage DCs</a>
              <?php endif; ?>
              <a class="btn" href="/rack.php">Rack View</a>
            </div>
          </header>

          <!-- KPI row -->
          <section class="kpi-grid" aria-label="Hardware KPIs">
            <article class="kpi-card">
              <h2>Total Assets</h2>
              <p><?= $totalAssets ?></p>
              <p class="muted small">Servers &amp; nodes registered</p>
            </article>
            <article class="kpi-card">
              <h2>Data Centers</h2>
              <p><?= $dcCount ?></p>
              <p class="muted small"><?= $rackCount ?> racks across all DCs</p>
            </article>
            <article class="kpi-card">
              <h2>Assigned</h2>
              <p><?= $assignedCount ?></p>
              <p class="muted small"><?= $unassigned ?> unassigned / spare</p>
            </article>
            <article class="kpi-card">
              <h2>Customers</h2>
              <p><?= count($custOptions) ?></p>
              <p class="muted small">Active accounts</p>
            </article>
          </section>

          <!-- DC Summary grid -->
          <section class="panel">
            <header class="panel__header">
              <h2>Data Center Overview</h2>
              <a class="link" href="/datacenters.php">Manage</a>
            </header>
            <div class="panel__body">
              <div class="dc-grid">
                <?php if (!$dcSummary): ?>
                  <p class="muted">No data centers configured yet. <a class="link" href="/datacenters.php">Add one →</a></p>
                <?php else: foreach ($dcSummary as $dc): ?>
                  <a class="dc-card" href="/datacenters.php?id=<?= (int)$dc['id'] ?>">
                    <div class="dc-card__header">
                      <span class="dc-card__code"><?= htmlspecialchars((string)($dc['code'] ?? $dc['name'])) ?></span>
                      <span class="<?= status_badge_class((string)($dc['status'] ?? 'active')) ?>">
                        <?= htmlspecialchars((string)($dc['status'] ?? 'active')) ?>
                      </span>
                    </div>
                    <div class="dc-card__name"><?= htmlspecialchars($dc['name']) ?></div>
                    <div class="dc-card__location muted small">
                      <?= htmlspecialchars((string)($dc['city'] ?? '')) ?>
                      <?= $dc['country'] ? (', ' . htmlspecialchars($dc['country'])) : '' ?>
                    </div>
                    <div class="dc-card__stats">
                      <span><?= (int)$dc['rack_count_val'] ?> racks</span>
                      <span><?= (int)$dc['node_count'] ?> servers</span>
                      <?php if ((int)$dc['down_count'] > 0): ?>
                        <span class="badge badge--danger"><?= (int)$dc['down_count'] ?> down</span>
                      <?php elseif ((int)$dc['healthy'] > 0): ?>
                        <span class="badge badge--success"><?= (int)$dc['healthy'] ?> healthy</span>
                      <?php endif; ?>
                    </div>
                  </a>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </section>

          <!-- Filter bar -->
          <section class="panel">
            <header class="panel__header">
              <h2>Asset Register</h2>
              <span class="muted small">Showing up to <?= $limit ?> assets</span>
            </header>
            <div class="panel__body">
              <form method="get" action="/hardware.php" class="filter-bar">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
                       placeholder="Search name, asset tag, serial, make/model, IP…" />

                <select name="dc">
                  <option value="all" <?= $dcFilter==='all'?'selected':'' ?>>All DCs</option>
                  <?php foreach ($dcOptions as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $dcFilter==(string)$d['id']?'selected':'' ?>>
                      <?= htmlspecialchars($d['code'] ? $d['code'].' – '.$d['name'] : $d['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="rack">
                  <option value="all" <?= $rackFilter==='all'?'selected':'' ?>>All racks</option>
                  <?php foreach ($rackOptions as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= $rackFilter==(string)$r['id']?'selected':'' ?>>
                      <?= htmlspecialchars($r['dc_name'] . ' / ' . $r['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="customer">
                  <option value="all" <?= $custFilter==='all'?'selected':'' ?>>All customers</option>
                  <?php foreach ($custOptions as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $custFilter==(string)$c['id']?'selected':'' ?>>
                      <?= htmlspecialchars($c['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="role">
                  <option value="all" <?= $roleFilter==='all'?'selected':'' ?>>All roles</option>
                  <?php foreach ($roleOptions as $rv): ?>
                    <option value="<?= htmlspecialchars($rv['role']) ?>" <?= $roleFilter===$rv['role']?'selected':'' ?>>
                      <?= htmlspecialchars($rv['role']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="status">
                  <option value="all"     <?= $statusFilter==='all'?'selected':''     ?>>All status</option>
                  <option value="healthy" <?= $statusFilter==='healthy'?'selected':'' ?>>Healthy</option>
                  <option value="degraded"<?= $statusFilter==='degraded'?'selected':''?>>Degraded</option>
                  <option value="warning" <?= $statusFilter==='warning'?'selected':'' ?>>Warning</option>
                  <option value="down"    <?= $statusFilter==='down'?'selected':''    ?>>Down</option>
                  <option value="unknown" <?= $statusFilter==='unknown'?'selected':'' ?>>Unknown</option>
                </select>

                <button class="btn" type="submit">Filter</button>
                <a class="btn" href="/hardware.php">Reset</a>
              </form>

              <!-- Assets table -->
              <div class="table-scroll">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Server</th>
                      <th>Data Center</th>
                      <th>Rack / Position</th>
                      <th>Customer</th>
                      <th>Make / Model</th>
                      <th>CPU</th>
                      <th>RAM</th>
                      <th>Storage</th>
                      <th>OS</th>
                      <th>Status</th>
                      <?php if ($canSeeMgmt): ?><th>Mgmt IP</th><?php endif; ?>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$assets): ?>
                      <tr><td colspan="<?= $canSeeMgmt ? '12' : '11' ?>" class="muted">No assets match your filter.</td></tr>
                    <?php else: foreach ($assets as $a): ?>
                      <tr>
                        <td>
                          <a class="link" href="/server.php?id=<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?></a>
                          <?php if ($a['asset_tag']): ?>
                            <br><span class="muted small"><?= htmlspecialchars($a['asset_tag']) ?></span>
                          <?php endif; ?>
                          <?php if ($a['role']): ?>
                            <br><span class="badge badge--muted"><?= htmlspecialchars($a['role']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($a['dc_name']): ?>
                            <span><?= htmlspecialchars($a['dc_code'] ?? $a['dc_name']) ?></span>
                            <?php if ($a['dc_city']): ?>
                              <br><span class="muted small"><?= htmlspecialchars($a['dc_city']) ?></span>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($a['rack_name']): ?>
                            <a class="link" href="/rack.php?id=<?= (int)$a['rack_id'] ?>">
                              <?= htmlspecialchars($a['rack_name']) ?>
                            </a>
                            <?php if ($a['rack_unit_start']): ?>
                              <br><span class="muted small">U<?= (int)$a['rack_unit_start'] ?>
                                <?= ($a['rack_unit_size'] > 1) ? '–U'.(((int)$a['rack_unit_start'] + (int)$a['rack_unit_size']) - 1) : '' ?>
                              </span>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($a['customer_name']): ?>
                            <a class="link" href="/customer.php?id=<?= (int)$a['cust_id'] ?>">
                              <?= htmlspecialchars($a['customer_name']) ?>
                            </a>
                            <?php if ($a['customer_service_level']): ?>
                              <br><span class="badge badge--muted"><?= htmlspecialchars($a['customer_service_level']) ?></span>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="muted">Unassigned</span>
                          <?php endif; ?>
                        </td>
                        <td class="muted small">
                          <?= htmlspecialchars(trim(($a['make'] ?? '') . ' ' . ($a['model'] ?? ''))) ?: '—' ?>
                          <?php if ($a['serial_number']): ?>
                            <br><span class="muted" style="font-size:11px;">S/N: <?= htmlspecialchars($a['serial_number']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="muted small">
                          <?= htmlspecialchars((string)($a['cpu_model'] ?? '—')) ?>
                          <?= $a['cpu_cores'] ? (' • ' . (int)$a['cpu_cores'] . 'c') : '' ?>
                        </td>
                        <td class="muted small"><?= $a['ram_gb'] ? (int)$a['ram_gb'] . ' GB' : '—' ?></td>
                        <td class="muted small"><?= $a['storage_gb'] ? (int)$a['storage_gb'] . ' GB' : '—' ?></td>
                        <td class="muted small">
                          <?= htmlspecialchars((string)($a['os_type'] ?? '')) ?>
                          <?= $a['os_version'] ? (' ' . htmlspecialchars($a['os_version'])) : '' ?>
                        </td>
                        <td>
                          <span class="<?= status_badge_class((string)($a['status'] ?? 'unknown')) ?>">
                            <?= htmlspecialchars((string)($a['status'] ?? 'unknown')) ?>
                          </span>
                        </td>
                        <?php if ($canSeeMgmt): ?>
                          <td class="muted small"><?= htmlspecialchars((string)($a['mgmt_ip'] ?? '—')) ?></td>
                        <?php endif; ?>
                        <td>
                          <a class="btn" style="padding:4px 10px; font-size:12px;" href="/server.php?id=<?= (int)$a['id'] ?>">View</a>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </section>

        </section>
        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
</body>
</html>
