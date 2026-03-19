<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

// ---- Single DC detail view ----
$dcId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : null;

// ---- Load all DCs with stats ----
$dcListStmt = $pdo->query("
    SELECT d.*,
           COUNT(DISTINCT r.id)   AS rack_count,
           COUNT(DISTINCT n.id)   AS node_count,
           SUM(n.status='healthy') AS healthy,
           SUM(n.status IN ('degraded','warning')) AS degraded,
           SUM(n.status='down')   AS down_count
    FROM datacenters d
    LEFT JOIN racks r ON r.datacenter_id = d.id
    LEFT JOIN nodes n ON n.datacenter_id = d.id
    GROUP BY d.id
    ORDER BY d.name ASC
");
$dcList = $dcListStmt->fetchAll();

// ---- Single DC detail ----
$dc      = null;
$racks   = [];
$dcNodes = [];
if ($dcId !== null) {
    $dcStmt = $pdo->prepare("SELECT * FROM datacenters WHERE id = ?");
    $dcStmt->execute([$dcId]);
    $dc = $dcStmt->fetch() ?: null;

    if ($dc) {
        $rackStmt = $pdo->prepare("
            SELECT r.*,
                   COUNT(n.id)            AS node_count,
                   SUM(n.status='healthy') AS healthy,
                   SUM(n.status='down')   AS down_count,
                   SUM(n.rack_unit_size)  AS used_units
            FROM racks r
            LEFT JOIN nodes n ON n.rack_id = r.id
            WHERE r.datacenter_id = ?
            GROUP BY r.id
            ORDER BY r.row_label, r.name
        ");
        $rackStmt->execute([$dcId]);
        $racks = $rackStmt->fetchAll();

        $nodeStmt = $pdo->prepare("
            SELECT n.id, n.name, n.status, n.role, n.make, n.model,
                   n.cpu_cores, n.ram_gb, n.mgmt_ip, n.rack_unit_start,
                   r.name AS rack_name, r.id AS rack_id,
                   c.name AS customer_name
            FROM nodes n
            LEFT JOIN racks r     ON r.id = n.rack_id
            LEFT JOIN customers c ON c.id = n.customer_id
            WHERE n.datacenter_id = ?
            ORDER BY r.name, n.rack_unit_start, n.name
        ");
        $nodeStmt->execute([$dcId]);
        $dcNodes = $nodeStmt->fetchAll();
    }
}

$totalDcs    = count($dcList);
$totalRacks  = (int)$pdo->query("SELECT COUNT(*) FROM racks")->fetchColumn();
$totalNodes  = (int)$pdo->query("SELECT COUNT(*) FROM nodes WHERE datacenter_id IS NOT NULL")->fetchColumn();

$canSeeMgmt = in_array($role, ['owner','admin','security'], true);

function status_badge_class(string $s): string {
    return match ($s) {
        'healthy', 'active' => 'badge badge--success',
        'degraded', 'warning' => 'badge badge--warn',
        'down', 'error'     => 'badge badge--danger',
        default             => 'badge badge--muted',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= $dc ? htmlspecialchars($dc['name']).' • ' : '' ?>Data Centers • NOC Portal</title>
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

          <?php if ($dc): ?>
            <!-- ========================= SINGLE DC VIEW ========================= -->
            <nav class="breadcrumb" aria-label="Breadcrumb">
              <a class="link" href="/datacenters.php">Data Centers</a>
              <span class="muted"> / </span>
              <span><?= htmlspecialchars($dc['name']) ?></span>
            </nav>

            <header class="page-header">
              <div class="page-header__titles">
                <h1>
                  <?= htmlspecialchars($dc['name']) ?>
                  <?= $dc['code'] ? '<span class="badge badge--muted">'.htmlspecialchars($dc['code']).'</span>' : '' ?>
                </h1>
                <p class="muted">
                  <?= htmlspecialchars(array_filter([$dc['city']??'', $dc['state']??'', $dc['country']??'']) ? implode(', ', array_filter([$dc['city']??'', $dc['state']??'', $dc['country']??''])) : 'Location not set') ?>
                </p>
              </div>
              <div class="page-header__actions">
                <a class="btn" href="/rack.php?dc=<?= $dcId ?>">Rack View</a>
                <a class="btn" href="/hardware.php?dc=<?= $dcId ?>">Assets</a>
              </div>
            </header>

            <!-- KPI row -->
            <section class="kpi-grid" aria-label="DC KPIs">
              <article class="kpi-card">
                <h2>Racks</h2>
                <p><?= count($racks) ?></p>
                <p class="muted small"><?= $dc['total_sqft'] ? number_format((int)$dc['total_sqft']).' sqft' : 'Size not set' ?></p>
              </article>
              <article class="kpi-card">
                <h2>Servers</h2>
                <p><?= count($dcNodes) ?></p>
                <p class="muted small"><?= count(array_filter($dcNodes, fn($n) => $n['status']==='healthy')) ?> healthy</p>
              </article>
              <?php if ($dc['power_capacity_kw']): ?>
              <article class="kpi-card">
                <h2>Power</h2>
                <p><?= (int)$dc['power_capacity_kw'] ?> kW</p>
                <p class="muted small">Facility capacity</p>
              </article>
              <?php endif; ?>
              <article class="kpi-card">
                <h2>Status</h2>
                <p><span class="<?= status_badge_class((string)($dc['status']??'active')) ?>"><?= htmlspecialchars($dc['status']??'active') ?></span></p>
                <p class="muted small">&nbsp;</p>
              </article>
            </section>

            <!-- DC info + contact -->
            <div class="server-detail-grid" style="--cols:2;">
              <section class="panel">
                <header class="panel__header"><h2>Facility Info</h2></header>
                <div class="panel__body">
                  <dl class="detail-dl">
                    <dt>Code</dt>
                    <dd><?= $dc['code'] ? htmlspecialchars($dc['code']) : '<span class="muted">—</span>' ?></dd>
                    <dt>Location</dt>
                    <dd><?= $dc['location'] ? htmlspecialchars($dc['location']) : '<span class="muted">—</span>' ?></dd>
                    <dt>Address</dt>
                    <dd class="muted small"><?= $dc['address'] ? nl2br(htmlspecialchars($dc['address'])) : '—' ?></dd>
                    <dt>City / State</dt>
                    <dd><?= htmlspecialchars(implode(', ', array_filter([$dc['city']??'', $dc['state']??'']))) ?: '<span class="muted">—</span>' ?></dd>
                    <dt>Country</dt>
                    <dd><?= $dc['country'] ? htmlspecialchars($dc['country']) : '<span class="muted">—</span>' ?></dd>
                    <?php if ($dc['power_capacity_kw']): ?>
                      <dt>Power</dt>
                      <dd><?= (int)$dc['power_capacity_kw'] ?> kW capacity</dd>
                    <?php endif; ?>
                    <?php if ($dc['total_sqft']): ?>
                      <dt>Floor space</dt>
                      <dd><?= number_format((int)$dc['total_sqft']) ?> sqft</dd>
                    <?php endif; ?>
                    <?php if ($dc['notes']): ?>
                      <dt>Notes</dt>
                      <dd class="muted small"><?= htmlspecialchars($dc['notes']) ?></dd>
                    <?php endif; ?>
                  </dl>
                </div>
              </section>

              <section class="panel">
                <header class="panel__header"><h2>Contact</h2></header>
                <div class="panel__body">
                  <dl class="detail-dl">
                    <dt>Contact Name</dt>
                    <dd><?= $dc['contact_name'] ? htmlspecialchars($dc['contact_name']) : '<span class="muted">—</span>' ?></dd>
                    <dt>Phone</dt>
                    <dd><?= $dc['contact_phone'] ? htmlspecialchars($dc['contact_phone']) : '<span class="muted">—</span>' ?></dd>
                    <dt>Email</dt>
                    <dd>
                      <?php if ($dc['contact_email']): ?>
                        <a class="link" href="mailto:<?= htmlspecialchars($dc['contact_email']) ?>"><?= htmlspecialchars($dc['contact_email']) ?></a>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </dd>
                  </dl>
                </div>
              </section>
            </div>

            <!-- Racks in this DC -->
            <section class="panel">
              <header class="panel__header">
                <h2>Racks</h2>
                <a class="link" href="/rack.php?dc=<?= $dcId ?>">Rack View →</a>
              </header>
              <div class="panel__body">
                <?php if (!$racks): ?>
                  <p class="muted">No racks configured in this data center.</p>
                <?php else: ?>
                  <div class="dc-rack-grid">
                    <?php foreach ($racks as $r): ?>
                      <a class="rack-summary-card" href="/rack.php?id=<?= (int)$r['id'] ?>">
                        <div class="rack-summary-card__name"><?= htmlspecialchars($r['name']) ?></div>
                        <?php if ($r['row_label']): ?>
                          <div class="muted small">Row <?= htmlspecialchars($r['row_label']) ?></div>
                        <?php endif; ?>
                        <div class="rack-summary-card__stats">
                          <span><?= (int)$r['node_count'] ?> servers</span>
                          <span><?= (int)($r['used_units'] ?? 0) ?>/<? = (int)$r['total_units'] ?>U</span>
                          <?php if ((int)$r['down_count'] > 0): ?>
                            <span class="badge badge--danger"><?= (int)$r['down_count'] ?> down</span>
                          <?php elseif ((int)$r['healthy'] > 0): ?>
                            <span class="badge badge--success"><?= (int)$r['healthy'] ?> ok</span>
                          <?php endif; ?>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </section>

            <!-- Nodes in this DC -->
            <section class="panel">
              <header class="panel__header">
                <h2>All Servers</h2>
                <a class="link" href="/hardware.php?dc=<?= $dcId ?>">Filter in Hardware →</a>
              </header>
              <div class="panel__body">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Server</th>
                      <th>Rack / Unit</th>
                      <th>Customer</th>
                      <th>Make / Model</th>
                      <th>Specs</th>
                      <th>Status</th>
                      <?php if ($canSeeMgmt): ?><th>Mgmt IP</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$dcNodes): ?>
                      <tr><td colspan="<?= $canSeeMgmt ? '7' : '6' ?>" class="muted">No servers in this data center.</td></tr>
                    <?php else: foreach ($dcNodes as $n): ?>
                      <tr>
                        <td>
                          <a class="link" href="/server.php?id=<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['name']) ?></a>
                          <?php if ($n['role']): ?>
                            <br><span class="badge badge--muted"><?= htmlspecialchars($n['role']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="muted small">
                          <?php if ($n['rack_name']): ?>
                            <a class="link" href="/rack.php?id=<?= (int)$n['rack_id'] ?>"><?= htmlspecialchars($n['rack_name']) ?></a>
                            <?= $n['rack_unit_start'] ? ' U'.(int)$n['rack_unit_start'] : '' ?>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td><?= $n['customer_name'] ? htmlspecialchars($n['customer_name']) : '<span class="muted">—</span>' ?></td>
                        <td class="muted small"><?= htmlspecialchars(trim(($n['make']??'').' '.($n['model']??''))) ?: '—' ?></td>
                        <td class="muted small">
                          <?= $n['cpu_cores'] ? (int)$n['cpu_cores'].'c' : '' ?>
                          <?= ($n['cpu_cores'] && $n['ram_gb']) ? ' • ' : '' ?>
                          <?= $n['ram_gb'] ? (int)$n['ram_gb'].' GB' : '' ?>
                        </td>
                        <td><span class="<?= status_badge_class((string)($n['status']??'unknown')) ?>"><?= htmlspecialchars((string)($n['status']??'unknown')) ?></span></td>
                        <?php if ($canSeeMgmt): ?>
                          <td class="muted small"><?= htmlspecialchars((string)($n['mgmt_ip']??'—')) ?></td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </section>

          <?php else: ?>
            <!-- ========================= DC LIST VIEW ========================= -->
            <header class="page-header">
              <div class="page-header__titles">
                <h1>Data Centers</h1>
                <p class="muted">Physical facilities hosting Glasshouse infrastructure.</p>
              </div>
            </header>

            <!-- KPIs -->
            <section class="kpi-grid" aria-label="DC KPIs">
              <article class="kpi-card">
                <h2>Data Centers</h2>
                <p><?= $totalDcs ?></p>
                <p class="muted small">Physical facilities</p>
              </article>
              <article class="kpi-card">
                <h2>Total Racks</h2>
                <p><?= $totalRacks ?></p>
                <p class="muted small">Across all DCs</p>
              </article>
              <article class="kpi-card">
                <h2>Racked Servers</h2>
                <p><?= $totalNodes ?></p>
                <p class="muted small">With DC assignment</p>
              </article>
            </section>

            <!-- DC cards -->
            <section class="panel">
              <header class="panel__header"><h2>All Data Centers</h2></header>
              <div class="panel__body">
                <?php if (!$dcList): ?>
                  <p class="muted">No data centers configured yet.</p>
                <?php else: ?>
                  <div class="dc-grid">
                    <?php foreach ($dcList as $d): ?>
                      <a class="dc-card" href="/datacenters.php?id=<?= (int)$d['id'] ?>">
                        <div class="dc-card__header">
                          <span class="dc-card__code"><?= htmlspecialchars($d['code'] ?? $d['name']) ?></span>
                          <span class="<?= status_badge_class((string)($d['status']??'active')) ?>"><?= htmlspecialchars($d['status']??'active') ?></span>
                        </div>
                        <div class="dc-card__name"><?= htmlspecialchars($d['name']) ?></div>
                        <div class="dc-card__location muted small">
                          <?= htmlspecialchars(implode(', ', array_filter([$d['city']??'', $d['country']??'']))) ?: '—' ?>
                        </div>
                        <div class="dc-card__stats">
                          <span><?= (int)$d['rack_count'] ?> racks</span>
                          <span><?= (int)$d['node_count'] ?> servers</span>
                          <?php if ((int)$d['down_count'] > 0): ?>
                            <span class="badge badge--danger"><?= (int)$d['down_count'] ?> down</span>
                          <?php elseif ((int)$d['healthy'] > 0): ?>
                            <span class="badge badge--success"><?= (int)$d['healthy'] ?> healthy</span>
                          <?php endif; ?>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>

                  <!-- DC table -->
                  <table class="table" style="margin-top:18px;">
                    <thead>
                      <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Location</th>
                        <th>Racks</th>
                        <th>Servers</th>
                        <th>Healthy</th>
                        <th>Down</th>
                        <th>Status</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($dcList as $d): ?>
                        <tr>
                          <td><a class="link" href="/datacenters.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></a></td>
                          <td class="muted small"><?= $d['code'] ? htmlspecialchars($d['code']) : '—' ?></td>
                          <td class="muted small"><?= htmlspecialchars(implode(', ', array_filter([$d['city']??'', $d['country']??'']))) ?: '—' ?></td>
                          <td><?= (int)$d['rack_count'] ?></td>
                          <td><?= (int)$d['node_count'] ?></td>
                          <td><?= (int)$d['healthy'] ?></td>
                          <td><?= (int)$d['down_count'] > 0 ? '<span class="badge badge--danger">'.(int)$d['down_count'].'</span>' : '0' ?></td>
                          <td><span class="<?= status_badge_class((string)($d['status']??'active')) ?>"><?= htmlspecialchars($d['status']??'active') ?></span></td>
                          <td>
                            <a class="btn" style="padding:4px 10px;font-size:12px;" href="/datacenters.php?id=<?= (int)$d['id'] ?>">View</a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
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
