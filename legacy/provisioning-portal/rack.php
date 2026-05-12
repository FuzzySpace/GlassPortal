<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

// ---- Selected rack (optional) ----
$rackId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : null;
$dcId   = isset($_GET['dc']) && ctype_digit($_GET['dc'])   ? (int)$_GET['dc']  : null;

// ---- All DCs and racks for nav ----
$allDcs = $pdo->query("SELECT id, name, code FROM datacenters ORDER BY name ASC")->fetchAll();

// Build rack list, filtered by DC if specified
if ($dcId !== null) {
    $rackListStmt = $pdo->prepare("
        SELECT r.id, r.name, r.row_label, r.total_units, r.status,
               d.name AS dc_name, d.code AS dc_code,
               COUNT(n.id) AS node_count
        FROM racks r
        JOIN datacenters d ON d.id = r.datacenter_id
        LEFT JOIN nodes n  ON n.rack_id = r.id
        WHERE r.datacenter_id = ?
        GROUP BY r.id
        ORDER BY r.row_label, r.name
    ");
    $rackListStmt->execute([$dcId]);
} else {
    $rackListStmt = $pdo->query("
        SELECT r.id, r.name, r.row_label, r.total_units, r.status,
               d.name AS dc_name, d.code AS dc_code,
               COUNT(n.id) AS node_count
        FROM racks r
        JOIN datacenters d ON d.id = r.datacenter_id
        LEFT JOIN nodes n  ON n.rack_id = r.id
        GROUP BY r.id
        ORDER BY d.name, r.row_label, r.name
    ");
}
$allRacks = $rackListStmt->fetchAll();

// If no rack selected, default to first one
if ($rackId === null && !empty($allRacks)) {
    $rackId = (int)$allRacks[0]['id'];
}

// ---- Selected rack detail ----
$rack = null;
$servers = [];
if ($rackId !== null) {
    $rackStmt = $pdo->prepare("
        SELECT r.*, d.name AS dc_name, d.code AS dc_code, d.city AS dc_city, d.country AS dc_country
        FROM racks r
        JOIN datacenters d ON d.id = r.datacenter_id
        WHERE r.id = ?
    ");
    $rackStmt->execute([$rackId]);
    $rack = $rackStmt->fetch() ?: null;

    if ($rack) {
        $srvStmt = $pdo->prepare("
            SELECT n.id, n.name, n.status, n.role,
                   n.rack_unit_start, n.rack_unit_size,
                   n.make, n.model, n.cpu_model, n.cpu_cores,
                   n.ram_gb, n.storage_gb, n.mgmt_ip,
                   n.asset_tag, n.serial_number,
                   n.os_type, n.os_version,
                   c.name AS customer_name, c.service_level
            FROM nodes n
            LEFT JOIN customers c ON c.id = n.customer_id
            WHERE n.rack_id = ?
            ORDER BY n.rack_unit_start ASC, n.name ASC
        ");
        $srvStmt->execute([$rackId]);
        $servers = $srvStmt->fetchAll();
    }
}

// Build a slot occupancy map: unit => server (for visualization)
$slotMap = [];
if ($rack) {
    $totalU = (int)($rack['total_units'] ?? 42);
    // Initialize all slots empty
    for ($u2 = 1; $u2 <= $totalU; $u2++) {
        $slotMap[$u2] = null;
    }
    foreach ($servers as $srv) {
        if ($srv['rack_unit_start'] !== null) {
            $start = (int)$srv['rack_unit_start'];
            $size  = max(1, (int)($srv['rack_unit_size'] ?? 1));
            for ($uu = $start; $uu < $start + $size; $uu++) {
                if (isset($slotMap[$uu])) {
                    $slotMap[$uu] = ['server' => $srv, 'start' => $start, 'size' => $size, 'is_top' => ($uu === $start)];
                }
            }
        }
    }
}

$canSeeMgmt = in_array($role, ['owner','admin','security'], true);

function srv_bg_class(string $status): string {
    return match ($status) {
        'healthy' => 'rack-slot--healthy',
        'degraded', 'warning' => 'rack-slot--warn',
        'down'    => 'rack-slot--danger',
        default   => 'rack-slot--unknown',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Rack View • NOC Portal</title>
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
              <h1>Rack View</h1>
              <p class="muted">Visual rack diagram with server positions and status.</p>
            </div>
            <div class="page-header__actions">
              <!-- DC filter -->
              <form method="get" action="/rack.php" style="display:flex; gap:8px; flex-wrap:wrap;">
                <select name="dc" onchange="this.form.submit()">
                  <option value="">All DCs</option>
                  <?php foreach ($allDcs as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $dcId===(int)$d['id']?'selected':'' ?>>
                      <?= htmlspecialchars($d['code'] ? $d['code'].' – '.$d['name'] : $d['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
              <a class="btn" href="/hardware.php">Asset Register</a>
            </div>
          </header>

          <div class="rack-layout">

            <!-- Left: Rack list -->
            <aside class="rack-list-panel">
              <h3>Racks <?= $dcId ? ('<span class="muted small">filtered</span>') : '' ?></h3>
              <?php if (!$allRacks): ?>
                <p class="muted small">No racks found.</p>
              <?php else:
                $lastDc = null;
                foreach ($allRacks as $r):
                  if ($r['dc_name'] !== $lastDc):
                    if ($lastDc !== null) echo '</div>';
                    $lastDc = $r['dc_name'];
                    echo '<p class="rack-list__dc">' . htmlspecialchars($r['dc_code'] ?? $r['dc_name']) . '</p>';
                    echo '<div class="rack-list__group">';
                  endif;
              ?>
                  <a class="rack-list__item <?= $rackId===(int)$r['id'] ? 'is-active' : '' ?>"
                     href="/rack.php?id=<?= (int)$r['id'] ?><?= $dcId ? '&dc='.$dcId : '' ?>">
                    <span class="rack-list__name"><?= htmlspecialchars($r['name']) ?></span>
                    <?php if ($r['row_label']): ?>
                      <span class="muted small"><?= htmlspecialchars($r['row_label']) ?></span>
                    <?php endif; ?>
                    <span class="rack-list__count"><?= (int)$r['node_count'] ?>U</span>
                  </a>
              <?php endforeach;
                if ($lastDc !== null) echo '</div>';
              endif; ?>
            </aside>

            <!-- Right: Rack diagram + detail -->
            <div class="rack-main">
              <?php if (!$rack): ?>
                <div class="panel">
                  <div class="panel__body">
                    <p class="muted">No rack selected or no racks configured. Add racks via <a class="link" href="/datacenters.php">Data Centers</a>.</p>
                  </div>
                </div>
              <?php else: ?>

                <!-- Rack info header -->
                <div class="rack-info-bar">
                  <div>
                    <strong><?= htmlspecialchars($rack['name']) ?></strong>
                    <span class="muted small">
                      <?= htmlspecialchars($rack['dc_name']) ?>
                      <?= $rack['dc_city'] ? ('• ' . htmlspecialchars($rack['dc_city'])) : '' ?>
                    </span>
                  </div>
                  <div class="rack-info-bar__meta">
                    <span class="muted small"><?= (int)($rack['total_units'] ?? 42) ?>U total</span>
                    <span class="muted small"><?= count($servers) ?> servers</span>
                    <?php if ($rack['power_amps']): ?>
                      <span class="muted small"><?= (int)$rack['power_amps'] ?>A</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="rack-view-body">
                  <!-- Rack diagram -->
                  <div class="rack-chassis" aria-label="Rack diagram">
                    <div class="rack-chassis__label">U</div>
                    <?php
                      $totalU = (int)($rack['total_units'] ?? 42);
                      $rendered = [];
                      for ($uNum = 1; $uNum <= $totalU; $uNum++):
                        $slot = $slotMap[$uNum] ?? null;
                        if (isset($rendered[$uNum])) continue; // already drawn by multi-U block
                    ?>
                      <?php if ($slot && $slot['is_top']): ?>
                        <?php
                          $srv   = $slot['server'];
                          $size  = $slot['size'];
                          $bgCls = srv_bg_class((string)($srv['status'] ?? 'unknown'));
                          for ($x = $uNum; $x < $uNum + $size; $x++) $rendered[$x] = true;
                        ?>
                        <div class="rack-slot <?= $bgCls ?> rack-slot--filled"
                             style="--slot-size: <?= $size ?>;"
                             onclick="showServerDetail(<?= (int)$srv['id'] ?>)"
                             role="button" tabindex="0" aria-label="<?= htmlspecialchars($srv['name']) ?>">
                          <div class="rack-slot__unit">U<?= $uNum ?></div>
                          <div class="rack-slot__content">
                            <span class="rack-slot__name"><?= htmlspecialchars($srv['name']) ?></span>
                            <?php if ($srv['customer_name']): ?>
                              <span class="rack-slot__customer"><?= htmlspecialchars($srv['customer_name']) ?></span>
                            <?php endif; ?>
                            <span class="rack-slot__model muted small">
                              <?= htmlspecialchars(trim(($srv['make'] ?? '') . ' ' . ($srv['model'] ?? ''))) ?: '' ?>
                            </span>
                          </div>
                          <div class="rack-slot__size"><?= $size ?>U</div>
                        </div>
                      <?php elseif ($slot): ?>
                        <?php /* continuation of multi-U block — skip */ ?>
                      <?php else: ?>
                        <div class="rack-slot rack-slot--empty">
                          <div class="rack-slot__unit muted">U<?= $uNum ?></div>
                          <div class="rack-slot__content muted small">— empty —</div>
                        </div>
                      <?php endif; ?>
                    <?php endfor; ?>
                  </div>

                  <!-- Server detail sidebar (JS-driven) -->
                  <div class="rack-detail" id="rackDetail">
                    <div class="rack-detail__placeholder muted">
                      <p>Click a server in the rack to view details.</p>
                    </div>

                    <!-- Preload all server data as JSON for JS -->
                    <script>
                      const RACK_SERVERS = <?= json_encode(
                        array_map(function($s) use ($canSeeMgmt) {
                          return [
                            'id'           => (int)$s['id'],
                            'name'         => $s['name'],
                            'status'       => $s['status'],
                            'role'         => $s['role'],
                            'rack_unit_start' => $s['rack_unit_start'],
                            'rack_unit_size'  => $s['rack_unit_size'],
                            'make'         => $s['make'],
                            'model'        => $s['model'],
                            'cpu_model'    => $s['cpu_model'],
                            'cpu_cores'    => $s['cpu_cores'],
                            'ram_gb'       => $s['ram_gb'],
                            'storage_gb'   => $s['storage_gb'],
                            'os_type'      => $s['os_type'],
                            'os_version'   => $s['os_version'],
                            'asset_tag'    => $s['asset_tag'],
                            'serial_number'=> $s['serial_number'],
                            'mgmt_ip'      => $canSeeMgmt ? $s['mgmt_ip'] : null,
                            'customer_name'=> $s['customer_name'],
                            'service_level'=> $s['service_level'],
                          ];
                        }, $servers),
                        JSON_HEX_TAG | JSON_HEX_AMP
                      ) ?>;
                    </script>
                  </div>
                </div>

              <?php endif; ?>
            </div>
          </div>

          <!-- Servers in this rack (table) -->
          <?php if ($rack && $servers): ?>
          <section class="panel" style="margin-top:18px;">
            <header class="panel__header">
              <h2>Servers in <?= htmlspecialchars($rack['name']) ?></h2>
              <span class="muted small"><?= count($servers) ?> total</span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Unit</th>
                    <th>Server</th>
                    <th>Customer</th>
                    <th>Make / Model</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Storage</th>
                    <th>OS</th>
                    <th>Status</th>
                    <?php if ($canSeeMgmt): ?><th>Mgmt IP</th><?php endif; ?>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($servers as $s): ?>
                    <tr>
                      <td class="muted small">
                        <?= $s['rack_unit_start'] ? 'U'.(int)$s['rack_unit_start'] : '—' ?>
                        <?= ($s['rack_unit_size'] > 1) ? ('–U'. (((int)$s['rack_unit_start'] + (int)$s['rack_unit_size']) - 1)) : '' ?>
                      </td>
                      <td>
                        <a class="link" href="/server.php?id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a>
                        <?php if ($s['asset_tag']): ?>
                          <br><span class="muted small"><?= htmlspecialchars($s['asset_tag']) ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= $s['customer_name'] ? htmlspecialchars($s['customer_name']) : '<span class="muted">—</span>' ?></td>
                      <td class="muted small">
                        <?= htmlspecialchars(trim(($s['make'] ?? '') . ' ' . ($s['model'] ?? ''))) ?: '—' ?>
                      </td>
                      <td class="muted small">
                        <?= htmlspecialchars((string)($s['cpu_model'] ?? '—')) ?>
                        <?= $s['cpu_cores'] ? ' • '.(int)$s['cpu_cores'].'c' : '' ?>
                      </td>
                      <td class="muted small"><?= $s['ram_gb'] ? (int)$s['ram_gb'].' GB' : '—' ?></td>
                      <td class="muted small"><?= $s['storage_gb'] ? (int)$s['storage_gb'].' GB' : '—' ?></td>
                      <td class="muted small">
                        <?= htmlspecialchars((string)($s['os_type'] ?? '')) ?>
                        <?= $s['os_version'] ? ' '.htmlspecialchars($s['os_version']) : '' ?>
                      </td>
                      <td>
                        <span class="<?= ($s['status']==='healthy') ? 'badge badge--success' : (($s['status']==='down') ? 'badge badge--danger' : 'badge badge--warn') ?>">
                          <?= htmlspecialchars((string)($s['status'] ?? 'unknown')) ?>
                        </span>
                      </td>
                      <?php if ($canSeeMgmt): ?>
                        <td class="muted small"><?= htmlspecialchars((string)($s['mgmt_ip'] ?? '—')) ?></td>
                      <?php endif; ?>
                      <td><a class="btn" style="padding:4px 10px;font-size:12px;" href="/server.php?id=<?= (int)$s['id'] ?>">View</a></td>
                    </tr>
                  <?php endforeach; ?>
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

<script>
function showServerDetail(serverId) {
  const srv = RACK_SERVERS.find(s => s.id === serverId);
  if (!srv) return;

  const statusClass = {
    healthy: 'badge--success',
    degraded: 'badge--warn',
    warning: 'badge--warn',
    down: 'badge--danger',
  }[srv.status] || 'badge--muted';

  const html = `
    <div class="rack-detail__card">
      <div class="rack-detail__header">
        <h3>${escHtml(srv.name)}</h3>
        <span class="badge ${statusClass}">${escHtml(srv.status || 'unknown')}</span>
      </div>

      <dl class="detail-dl">
        ${srv.role       ? `<dt>Role</dt><dd>${escHtml(srv.role)}</dd>` : ''}
        ${srv.asset_tag  ? `<dt>Asset Tag</dt><dd>${escHtml(srv.asset_tag)}</dd>` : ''}
        ${srv.serial_number ? `<dt>Serial</dt><dd>${escHtml(srv.serial_number)}</dd>` : ''}
        ${(srv.make||srv.model) ? `<dt>Hardware</dt><dd>${escHtml((srv.make||'') + ' ' + (srv.model||'')).trim()}</dd>` : ''}
        ${srv.rack_unit_start ? `<dt>Rack Position</dt><dd>U${srv.rack_unit_start}${srv.rack_unit_size > 1 ? '–U'+(parseInt(srv.rack_unit_start)+parseInt(srv.rack_unit_size)-1) : ''} (${srv.rack_unit_size}U)</dd>` : ''}

        <dt>CPU</dt>
        <dd>${escHtml(srv.cpu_model || '—')}${srv.cpu_cores ? ' • ' + srv.cpu_cores + ' cores' : ''}</dd>

        <dt>Memory</dt>
        <dd>${srv.ram_gb ? srv.ram_gb + ' GB RAM' : '—'}</dd>

        <dt>Storage</dt>
        <dd>${srv.storage_gb ? srv.storage_gb + ' GB' : '—'}</dd>

        ${(srv.os_type||srv.os_version) ? `<dt>OS</dt><dd>${escHtml((srv.os_type||'') + ' ' + (srv.os_version||'')).trim()}</dd>` : ''}
        ${srv.customer_name ? `<dt>Customer</dt><dd>${escHtml(srv.customer_name)}${srv.service_level ? ' <span class="badge badge--muted">'+escHtml(srv.service_level)+'</span>' : ''}</dd>` : '<dt>Customer</dt><dd class="muted">Unassigned</dd>'}
        ${srv.mgmt_ip ? `<dt>Mgmt IP</dt><dd>${escHtml(srv.mgmt_ip)}</dd>` : ''}
      </dl>

      <div style="margin-top:14px; display:flex; gap:8px;">
        <a class="btn btn--primary" href="/server.php?id=${srv.id}">Full Detail →</a>
      </div>
    </div>
  `;

  document.getElementById('rackDetail').innerHTML = html;

  // highlight selected slot
  document.querySelectorAll('.rack-slot--selected').forEach(el => el.classList.remove('rack-slot--selected'));
  document.querySelectorAll('.rack-slot--filled').forEach(el => {
    if (el.getAttribute('aria-label') === srv.name) el.classList.add('rack-slot--selected');
  });
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// keyboard support for rack slots
document.addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && document.activeElement.classList.contains('rack-slot--filled')) {
    document.activeElement.click();
  }
});
</script>
</body>
</html>
