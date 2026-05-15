<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

$q = trim((string)($_GET['q'] ?? ''));

$servers   = [];
$customers = [];
$racks     = [];
$dcs       = [];
$scripts   = [];

if ($q !== '' && strlen($q) >= 2) {
    $like = '%' . $q . '%';

    // Servers / nodes
    $stmt = $pdo->prepare("
        SELECT n.id, n.name, n.status, n.make, n.model, n.asset_tag,
               d.code AS dc_code, d.name AS dc_name,
               r.name AS rack_name,
               c.name AS customer_name
        FROM nodes n
        LEFT JOIN datacenters d ON d.id = n.datacenter_id
        LEFT JOIN racks r       ON r.id = n.rack_id
        LEFT JOIN customers c   ON c.id = n.customer_id
        WHERE n.name LIKE ? OR n.asset_tag LIKE ? OR n.serial_number LIKE ?
           OR n.make LIKE ? OR n.model LIKE ? OR n.mgmt_ip LIKE ?
        ORDER BY n.name
        LIMIT 25
    ");
    $stmt->execute([$like, $like, $like, $like, $like, $like]);
    $servers = $stmt->fetchAll();

    // Customers
    $stmt = $pdo->prepare("
        SELECT id, name, company_type, account_status, service_level, contact_email
        FROM customers
        WHERE name LIKE ? OR contact_email LIKE ? OR contact_name LIKE ? OR account_number LIKE ?
        ORDER BY name
        LIMIT 15
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $customers = $stmt->fetchAll();

    // Racks
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.row_label, r.total_units, r.status,
               d.name AS dc_name, d.code AS dc_code
        FROM racks r
        LEFT JOIN datacenters d ON d.id = r.datacenter_id
        WHERE r.name LIKE ? OR r.row_label LIKE ?
        ORDER BY r.name
        LIMIT 10
    ");
    $stmt->execute([$like, $like]);
    $racks = $stmt->fetchAll();

    // Data centers
    $stmt = $pdo->prepare("
        SELECT id, name, code, city, country, status
        FROM datacenters
        WHERE name LIKE ? OR code LIKE ? OR city LIKE ? OR country LIKE ?
        ORDER BY name
        LIMIT 10
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $dcs = $stmt->fetchAll();

    // Scripts
    $stmt = $pdo->prepare("
        SELECT id, name, description, script_type, category, command
        FROM ansible_scripts
        WHERE name LIKE ? OR description LIKE ? OR command LIKE ? OR tags LIKE ?
        ORDER BY name
        LIMIT 10
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $scripts = $stmt->fetchAll();
}

$totalResults = count($servers) + count($customers) + count($racks) + count($dcs) + count($scripts);

function status_cls(string $s): string {
    return match ($s) {
        'healthy', 'active', 'success' => 'badge badge--success',
        'degraded', 'warning'          => 'badge badge--warn',
        'down', 'failed', 'inactive'   => 'badge badge--danger',
        default                        => 'badge badge--muted',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= $q ? 'Search: ' . htmlspecialchars($q) : 'Search' ?> • NOC Portal</title>
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
              <h1>Search</h1>
              <?php if ($q !== ''): ?>
                <p class="muted"><?= $totalResults ?> results for "<?= htmlspecialchars($q) ?>"</p>
              <?php else: ?>
                <p class="muted">Search across servers, customers, racks, data centers, and scripts.</p>
              <?php endif; ?>
            </div>
          </header>

          <!-- Search box -->
          <section class="panel">
            <div class="panel__body">
              <form method="get" action="/search.php" class="filter-bar" style="padding-bottom:0;">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
                       placeholder="Server name, asset tag, customer, rack…"
                       autofocus style="flex:1; min-width:240px;" />
                <button class="btn btn--primary" type="submit">Search</button>
              </form>
            </div>
          </section>

          <?php if ($q !== '' && strlen($q) < 2): ?>
            <p class="muted" style="padding:12px 0;">Search query must be at least 2 characters.</p>
          <?php elseif ($q !== '' && $totalResults === 0): ?>
            <p class="muted" style="padding:12px 0;">No results found for "<?= htmlspecialchars($q) ?>".</p>
          <?php endif; ?>

          <!-- Servers -->
          <?php if ($servers): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Servers</h2>
              <span class="muted small"><?= count($servers) ?> result<?= count($servers)!==1?'s':'' ?></span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr><th>Name</th><th>Status</th><th>Make / Model</th><th>Location</th><th>Customer</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($servers as $s): ?>
                  <tr>
                    <td><a class="link" href="/server.php?id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a></td>
                    <td><span class="<?= status_cls((string)$s['status']) ?>"><?= htmlspecialchars((string)$s['status']) ?></span></td>
                    <td class="muted small"><?= htmlspecialchars(trim(($s['make'] ?? '') . ' ' . ($s['model'] ?? ''))) ?: '—' ?></td>
                    <td class="muted small">
                      <?= $s['dc_code'] ? htmlspecialchars($s['dc_code']) : ($s['dc_name'] ? htmlspecialchars($s['dc_name']) : '—') ?>
                      <?= $s['rack_name'] ? ' / ' . htmlspecialchars($s['rack_name']) : '' ?>
                    </td>
                    <td class="muted small"><?= $s['customer_name'] ? htmlspecialchars($s['customer_name']) : '—' ?></td>
                    <td><a class="link" href="/server.php?id=<?= (int)$s['id'] ?>">View →</a></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
          <?php endif; ?>

          <!-- Customers -->
          <?php if ($customers): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Customers</h2>
              <span class="muted small"><?= count($customers) ?> result<?= count($customers)!==1?'s':'' ?></span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr><th>Name</th><th>Type</th><th>Status</th><th>Service Level</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($customers as $c): ?>
                  <tr>
                    <td><a class="link" href="/customer.php?id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></td>
                    <td class="muted small"><?= $c['company_type'] ? htmlspecialchars($c['company_type']) : '—' ?></td>
                    <td><span class="<?= status_cls((string)$c['account_status']) ?>"><?= htmlspecialchars((string)$c['account_status']) ?></span></td>
                    <td class="muted small"><?= $c['service_level'] ? htmlspecialchars($c['service_level']) : '—' ?></td>
                    <td><a class="link" href="/customer.php?id=<?= (int)$c['id'] ?>">View →</a></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
          <?php endif; ?>

          <!-- Racks -->
          <?php if ($racks): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Racks</h2>
              <span class="muted small"><?= count($racks) ?> result<?= count($racks)!==1?'s':'' ?></span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr><th>Name</th><th>Data Center</th><th>Row</th><th>Units</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($racks as $r): ?>
                  <tr>
                    <td><a class="link" href="/rack.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></td>
                    <td class="muted small"><?= $r['dc_code'] ? htmlspecialchars($r['dc_code']) : htmlspecialchars((string)$r['dc_name']) ?></td>
                    <td class="muted small"><?= $r['row_label'] ? htmlspecialchars($r['row_label']) : '—' ?></td>
                    <td class="muted small"><?= (int)$r['total_units'] ?>U</td>
                    <td><span class="<?= status_cls((string)$r['status']) ?>"><?= htmlspecialchars((string)$r['status']) ?></span></td>
                    <td><a class="link" href="/rack.php?id=<?= (int)$r['id'] ?>">View →</a></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
          <?php endif; ?>

          <!-- Data Centers -->
          <?php if ($dcs): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Data Centers</h2>
              <span class="muted small"><?= count($dcs) ?> result<?= count($dcs)!==1?'s':'' ?></span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr><th>Name</th><th>Code</th><th>Location</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($dcs as $d): ?>
                  <tr>
                    <td><a class="link" href="/datacenters.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></a></td>
                    <td class="muted small"><?= $d['code'] ? htmlspecialchars($d['code']) : '—' ?></td>
                    <td class="muted small">
                      <?= $d['city'] ? htmlspecialchars($d['city']) : '' ?>
                      <?= ($d['city'] && $d['country']) ? ', ' : '' ?>
                      <?= $d['country'] ? htmlspecialchars($d['country']) : '' ?>
                    </td>
                    <td><span class="<?= status_cls((string)$d['status']) ?>"><?= htmlspecialchars((string)$d['status']) ?></span></td>
                    <td><a class="link" href="/datacenters.php?id=<?= (int)$d['id'] ?>">View →</a></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
          <?php endif; ?>

          <!-- Scripts -->
          <?php if ($scripts): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Ansible Scripts</h2>
              <span class="muted small"><?= count($scripts) ?> result<?= count($scripts)!==1?'s':'' ?></span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr><th>Name</th><th>Type</th><th>Category</th><th>Command</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($scripts as $sc): ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars($sc['name']) ?></strong>
                      <?php if ($sc['description']): ?>
                        <br><span class="muted small"><?= htmlspecialchars($sc['description']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><span class="badge badge--muted"><?= htmlspecialchars($sc['script_type']) ?></span></td>
                    <td class="muted small"><?= $sc['category'] ? htmlspecialchars($sc['category']) : '—' ?></td>
                    <td class="muted small" style="font-family:monospace;"><?= htmlspecialchars($sc['command']) ?></td>
                    <td><a class="link" href="/automations.php?script=<?= (int)$sc['id'] ?>">Run →</a></td>
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
</body>
</html>
