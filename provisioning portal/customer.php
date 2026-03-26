<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: /customers.php');
    exit;
}

// ---- Load customer ----
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: /customers.php');
    exit;
}

// ---- Servers assigned to this customer ----
$srvStmt = $pdo->prepare("
    SELECT n.id, n.name, n.status, n.role, n.make, n.model,
           n.cpu_model, n.cpu_cores, n.ram_gb, n.storage_gb,
           n.os_type, n.os_version, n.asset_tag, n.mgmt_ip,
           n.rack_unit_start, n.rack_unit_size, n.last_seen_at,
           d.name AS dc_name, d.code AS dc_code,
           r.name AS rack_name, r.id AS rack_id
    FROM nodes n
    LEFT JOIN datacenters d ON d.id = n.datacenter_id
    LEFT JOIN racks        r ON r.id = n.rack_id
    WHERE n.customer_id = ?
    ORDER BY d.name ASC, r.name ASC, n.rack_unit_start ASC
");
$srvStmt->execute([$id]);
$servers = $srvStmt->fetchAll();

// ---- Server stats ----
$totalServers   = count($servers);
$healthyServers = count(array_filter($servers, fn($s) => $s['status'] === 'healthy'));
$downServers    = count(array_filter($servers, fn($s) => $s['status'] === 'down'));
$dcSet          = array_unique(array_filter(array_column($servers, 'dc_name')));

$canSeeMgmt      = in_array($role, ['owner','admin','security'], true);
$canSeeFinancial = in_array($role, ['owner','admin'], true);

function status_badge_class(string $s): string {
    return match ($s) {
        'healthy', 'success', 'active' => 'badge badge--success',
        'degraded', 'warning'           => 'badge badge--warn',
        'down', 'failed', 'error', 'suspended', 'churned' => 'badge badge--danger',
        default                         => 'badge badge--muted',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($customer['name']) ?> • NOC Portal</title>
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
            <a class="link" href="/customers.php">Customers</a>
            <span class="muted"> / </span>
            <span><?= htmlspecialchars($customer['name']) ?></span>
          </nav>

          <!-- Page header -->
          <header class="page-header">
            <div class="page-header__titles">
              <h1>
                <?= htmlspecialchars($customer['name']) ?>
                <span class="<?= status_badge_class((string)($customer['account_status'] ?? 'unknown')) ?>">
                  <?= htmlspecialchars((string)($customer['account_status'] ?? '—')) ?>
                </span>
              </h1>
              <p class="muted">
                <?= htmlspecialchars((string)($customer['company_type'] ?? '')) ?>
                <?php if ($customer['service_level']): ?>
                  &nbsp;•&nbsp;<span class="badge badge--muted"><?= htmlspecialchars($customer['service_level']) ?></span>
                <?php endif; ?>
                <?php if ($customer['account_number']): ?>
                  &nbsp;•&nbsp; Account #<?= htmlspecialchars($customer['account_number']) ?>
                <?php endif; ?>
              </p>
            </div>
            <div class="page-header__actions">
              <a class="btn" href="/hardware.php?customer=<?= $id ?>">View Hardware →</a>
            </div>
          </header>

          <!-- KPI row -->
          <section class="kpi-grid" aria-label="Customer KPIs">
            <article class="kpi-card">
              <h2>Servers</h2>
              <p><?= $totalServers ?></p>
              <p class="muted small"><?= $healthyServers ?> healthy • <?= $downServers ?> down</p>
            </article>
            <article class="kpi-card">
              <h2>Data Centers</h2>
              <p><?= count($dcSet) ?></p>
              <p class="muted small"><?= implode(', ', array_slice($dcSet, 0, 3)) ?: '—' ?></p>
            </article>
            <?php if ($canSeeFinancial && $customer['mrr_cents']): ?>
            <article class="kpi-card">
              <h2>MRR</h2>
              <p>$<?= number_format((int)$customer['mrr_cents'] / 100, 0) ?></p>
              <p class="muted small">per month</p>
            </article>
            <?php endif; ?>
            <article class="kpi-card">
              <h2>Contract</h2>
              <p class="small">
                <?= $customer['contract_start'] ? htmlspecialchars(substr($customer['contract_start'],0,10)) : '—' ?>
              </p>
              <p class="muted small">
                to <?= $customer['contract_end'] ? htmlspecialchars(substr($customer['contract_end'],0,10)) : '∞' ?>
              </p>
            </article>
          </section>

          <!-- Detail panels -->
          <div class="server-detail-grid">

            <!-- Account info -->
            <section class="panel">
              <header class="panel__header"><h2>Account</h2></header>
              <div class="panel__body">
                <dl class="detail-dl">
                  <dt>Company Name</dt>
                  <dd><?= htmlspecialchars($customer['name']) ?></dd>

                  <dt>Type</dt>
                  <dd><?= htmlspecialchars((string)($customer['company_type'] ?? '—')) ?></dd>

                  <dt>Service Level</dt>
                  <dd><?= $customer['service_level'] ? '<span class="badge badge--muted">'.htmlspecialchars($customer['service_level']).'</span>' : '<span class="muted">—</span>' ?></dd>

                  <dt>Account Status</dt>
                  <dd><span class="<?= status_badge_class((string)($customer['account_status'] ?? 'unknown')) ?>"><?= htmlspecialchars((string)($customer['account_status'] ?? '—')) ?></span></dd>

                  <?php if ($customer['account_number']): ?>
                    <dt>Account #</dt>
                    <dd><?= htmlspecialchars($customer['account_number']) ?></dd>
                  <?php endif; ?>

                  <?php if ($canSeeFinancial): ?>
                    <dt>MRR</dt>
                    <dd><?= $customer['mrr_cents'] ? '$'.number_format((int)$customer['mrr_cents']/100, 2).'/mo' : '<span class="muted">—</span>' ?></dd>
                  <?php endif; ?>

                  <dt>Contract Start</dt>
                  <dd class="muted small"><?= $customer['contract_start'] ? htmlspecialchars(substr($customer['contract_start'],0,10)) : '—' ?></dd>

                  <dt>Contract End</dt>
                  <dd class="muted small"><?= $customer['contract_end'] ? htmlspecialchars(substr($customer['contract_end'],0,10)) : '—' ?></dd>
                </dl>
              </div>
            </section>

            <!-- Contact -->
            <section class="panel">
              <header class="panel__header"><h2>Contact</h2></header>
              <div class="panel__body">
                <dl class="detail-dl">
                  <dt>Contact Name</dt>
                  <dd><?= $customer['contact_name'] ? htmlspecialchars($customer['contact_name']) : '<span class="muted">—</span>' ?></dd>

                  <dt>Email</dt>
                  <dd>
                    <?php if ($customer['contact_email']): ?>
                      <?php if ($canSeeFinancial): ?>
                        <a class="link" href="mailto:<?= htmlspecialchars($customer['contact_email']) ?>"><?= htmlspecialchars($customer['contact_email']) ?></a>
                      <?php else: ?>
                        <span class="muted">[restricted]</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </dd>

                  <dt>Phone</dt>
                  <dd><?= $customer['contact_phone'] ? htmlspecialchars($customer['contact_phone']) : '<span class="muted">—</span>' ?></dd>

                  <dt>Address</dt>
                  <dd class="muted small">
                    <?php
                      $addrParts = array_filter([
                        $customer['address'] ?? '',
                        $customer['city'] ?? '',
                        $customer['state'] ?? '',
                        $customer['country'] ?? '',
                      ]);
                      echo $addrParts ? nl2br(htmlspecialchars(implode(', ', $addrParts))) : '—';
                    ?>
                  </dd>
                </dl>
              </div>
            </section>

            <!-- Notes -->
            <section class="panel">
              <header class="panel__header"><h2>Notes</h2></header>
              <div class="panel__body">
                <?php if ($customer['notes']): ?>
                  <p style="white-space: pre-wrap; color: rgba(255,255,255,0.78);"><?= htmlspecialchars($customer['notes']) ?></p>
                <?php else: ?>
                  <p class="muted">No notes on file.</p>
                <?php endif; ?>
                <p class="muted small" style="margin-top:8px;">
                  Added <?= htmlspecialchars(substr($customer['created_at'],0,10)) ?>
                  • Updated <?= htmlspecialchars(substr($customer['updated_at'],0,10)) ?>
                </p>
              </div>
            </section>

          </div>

          <!-- Servers assigned -->
          <section class="panel">
            <header class="panel__header">
              <h2>Assigned Servers</h2>
              <a class="link" href="/hardware.php?customer=<?= $id ?>">Filter in Hardware →</a>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Server</th>
                    <th>Data Center</th>
                    <th>Rack / Unit</th>
                    <th>Make / Model</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>OS</th>
                    <th>Status</th>
                    <?php if ($canSeeMgmt): ?><th>Mgmt IP</th><?php endif; ?>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$servers): ?>
                    <tr><td colspan="<?= $canSeeMgmt ? '10' : '9' ?>" class="muted">No servers assigned to this customer yet.</td></tr>
                  <?php else: foreach ($servers as $s): ?>
                    <tr>
                      <td>
                        <a class="link" href="/server.php?id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a>
                        <?php if ($s['asset_tag']): ?>
                          <br><span class="muted small"><?= htmlspecialchars($s['asset_tag']) ?></span>
                        <?php endif; ?>
                        <?php if ($s['role']): ?>
                          <br><span class="badge badge--muted"><?= htmlspecialchars($s['role']) ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($s['dc_name']): ?>
                          <?= htmlspecialchars($s['dc_code'] ?? $s['dc_name']) ?>
                        <?php else: ?>
                          <span class="muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td class="muted small">
                        <?php if ($s['rack_name']): ?>
                          <a class="link" href="/rack.php?id=<?= (int)$s['rack_id'] ?>"><?= htmlspecialchars($s['rack_name']) ?></a>
                          <?= $s['rack_unit_start'] ? ' U'.(int)$s['rack_unit_start'] : '' ?>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td class="muted small"><?= htmlspecialchars(trim(($s['make'] ?? '') . ' ' . ($s['model'] ?? ''))) ?: '—' ?></td>
                      <td class="muted small">
                        <?= htmlspecialchars((string)($s['cpu_model'] ?? '—')) ?>
                        <?= $s['cpu_cores'] ? ' • '.(int)$s['cpu_cores'].'c' : '' ?>
                      </td>
                      <td class="muted small"><?= $s['ram_gb'] ? (int)$s['ram_gb'].' GB' : '—' ?></td>
                      <td class="muted small">
                        <?= htmlspecialchars((string)($s['os_type'] ?? '')) ?>
                        <?= $s['os_version'] ? ' '.htmlspecialchars($s['os_version']) : '' ?>
                      </td>
                      <td>
                        <span class="<?= status_badge_class((string)($s['status'] ?? 'unknown')) ?>">
                          <?= htmlspecialchars((string)($s['status'] ?? 'unknown')) ?>
                        </span>
                      </td>
                      <?php if ($canSeeMgmt): ?>
                        <td class="muted small"><?= htmlspecialchars((string)($s['mgmt_ip'] ?? '—')) ?></td>
                      <?php endif; ?>
                      <td><a class="btn" style="padding:4px 10px;font-size:12px;" href="/server.php?id=<?= (int)$s['id'] ?>">View</a></td>
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
