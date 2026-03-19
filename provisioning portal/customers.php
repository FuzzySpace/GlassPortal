<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

// ---- Filters ----
$q          = trim((string)($_GET['q']       ?? ''));
$typeFilter = trim((string)($_GET['type']    ?? 'all'));
$slFilter   = trim((string)($_GET['sl']      ?? 'all'));
$statusFilter = trim((string)($_GET['status']?? 'all'));

$typeOptions = $pdo->query("SELECT DISTINCT company_type FROM customers WHERE company_type IS NOT NULL AND company_type <> '' ORDER BY company_type")->fetchAll(PDO::FETCH_COLUMN);
$slOptions   = $pdo->query("SELECT DISTINCT service_level FROM customers WHERE service_level IS NOT NULL AND service_level <> '' ORDER BY service_level")->fetchAll(PDO::FETCH_COLUMN);

$where  = [];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[]  = "(c.name LIKE ? OR c.contact_name LIKE ? OR c.contact_email LIKE ? OR c.account_number LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}
if ($typeFilter !== 'all') {
    $where[]  = "c.company_type = ?";
    $params[] = $typeFilter;
}
if ($slFilter !== 'all') {
    $where[]  = "c.service_level = ?";
    $params[] = $slFilter;
}
if ($statusFilter !== 'all') {
    $where[]  = "c.account_status = ?";
    $params[] = $statusFilter;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// ---- KPIs ----
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$activeCount    = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE account_status='active'")->fetchColumn();
$mrrTotal       = (int)$pdo->query("SELECT COALESCE(SUM(mrr_cents),0) FROM customers WHERE account_status='active'")->fetchColumn();

// ---- Customer list ----
$sql = "
  SELECT c.*,
         COUNT(n.id) AS server_count
  FROM customers c
  LEFT JOIN nodes n ON n.customer_id = c.id
  $whereSql
  GROUP BY c.id
  ORDER BY c.account_status='active' DESC, c.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

function status_badge_class(string $s): string {
    return match ($s) {
        'active'    => 'badge badge--success',
        'suspended' => 'badge badge--warn',
        'churned'   => 'badge badge--danger',
        default     => 'badge badge--muted',
    };
}

$canSeeFinancial = in_array($role, ['owner','admin'], true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Customers • NOC Portal</title>
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
              <h1>Customers</h1>
              <p class="muted">Hosting &amp; MSP client registry — accounts, SLAs, and server assignments.</p>
            </div>
          </header>

          <!-- KPIs -->
          <section class="kpi-grid" aria-label="Customer KPIs">
            <article class="kpi-card">
              <h2>Total Customers</h2>
              <p><?= $totalCustomers ?></p>
              <p class="muted small"><?= $activeCount ?> active</p>
            </article>
            <?php if ($canSeeFinancial): ?>
            <article class="kpi-card">
              <h2>Monthly Revenue</h2>
              <p>$<?= number_format($mrrTotal / 100, 0) ?></p>
              <p class="muted small">Active MRR</p>
            </article>
            <?php endif; ?>
            <article class="kpi-card">
              <h2>Hosting</h2>
              <p><?= (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE company_type='hosting'")->fetchColumn() ?></p>
              <p class="muted small">Dedicated hosting</p>
            </article>
            <article class="kpi-card">
              <h2>MSP</h2>
              <p><?= (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE company_type='msp'")->fetchColumn() ?></p>
              <p class="muted small">Managed services</p>
            </article>
          </section>

          <!-- Filter + table -->
          <section class="panel">
            <header class="panel__header">
              <h2>Customer Register</h2>
              <span class="muted small"><?= count($customers) ?> results</span>
            </header>
            <div class="panel__body">
              <form method="get" action="/customers.php" class="filter-bar">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
                       placeholder="Search name, contact, email, account #…" />

                <select name="type">
                  <option value="all" <?= $typeFilter==='all'?'selected':'' ?>>All types</option>
                  <?php foreach ($typeOptions as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $typeFilter===$t?'selected':'' ?>>
                      <?= htmlspecialchars($t) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="sl">
                  <option value="all" <?= $slFilter==='all'?'selected':'' ?>>All service levels</option>
                  <?php foreach ($slOptions as $sl): ?>
                    <option value="<?= htmlspecialchars($sl) ?>" <?= $slFilter===$sl?'selected':'' ?>>
                      <?= htmlspecialchars($sl) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <select name="status">
                  <option value="all"       <?= $statusFilter==='all'?'selected':''       ?>>All status</option>
                  <option value="active"    <?= $statusFilter==='active'?'selected':''    ?>>Active</option>
                  <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>Suspended</option>
                  <option value="churned"   <?= $statusFilter==='churned'?'selected':''   ?>>Churned</option>
                </select>

                <button class="btn" type="submit">Filter</button>
                <a class="btn" href="/customers.php">Reset</a>
              </form>

              <div class="table-scroll">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Customer</th>
                      <th>Type</th>
                      <th>Service Level</th>
                      <th>Status</th>
                      <th>Contact</th>
                      <th>Servers</th>
                      <?php if ($canSeeFinancial): ?><th>MRR</th><?php endif; ?>
                      <th>Contract</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$customers): ?>
                      <tr><td colspan="<?= $canSeeFinancial ? '9' : '8' ?>" class="muted">No customers match your filter.</td></tr>
                    <?php else: foreach ($customers as $c): ?>
                      <tr>
                        <td>
                          <a class="link" href="/customer.php?id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                          <?php if ($c['account_number']): ?>
                            <br><span class="muted small">#<?= htmlspecialchars($c['account_number']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="muted small"><?= htmlspecialchars((string)($c['company_type'] ?? '—')) ?></td>
                        <td>
                          <?php if ($c['service_level']): ?>
                            <span class="badge badge--muted"><?= htmlspecialchars($c['service_level']) ?></span>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="<?= status_badge_class((string)($c['account_status'] ?? 'unknown')) ?>">
                            <?= htmlspecialchars((string)($c['account_status'] ?? '—')) ?>
                          </span>
                        </td>
                        <td class="muted small">
                          <?= $c['contact_name'] ? htmlspecialchars($c['contact_name']) : '—' ?>
                          <?php if (in_array($role, ['owner','admin'], true) && $c['contact_email']): ?>
                            <br><a class="link" href="mailto:<?= htmlspecialchars($c['contact_email']) ?>">
                              <?= htmlspecialchars($c['contact_email']) ?>
                            </a>
                          <?php endif; ?>
                        </td>
                        <td>
                          <a class="link" href="/hardware.php?customer=<?= (int)$c['id'] ?>"><?= (int)$c['server_count'] ?></a>
                        </td>
                        <?php if ($canSeeFinancial): ?>
                          <td class="muted small">
                            <?= $c['mrr_cents'] ? ('$' . number_format((int)$c['mrr_cents'] / 100, 2) . '/mo') : '—' ?>
                          </td>
                        <?php endif; ?>
                        <td class="muted small">
                          <?php if ($c['contract_start'] || $c['contract_end']): ?>
                            <?= $c['contract_start'] ? htmlspecialchars(substr($c['contract_start'],0,10)) : '?' ?>
                            →
                            <?= $c['contract_end'] ? htmlspecialchars(substr($c['contract_end'],0,10)) : '∞' ?>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td>
                          <a class="btn" style="padding:4px 10px;font-size:12px;" href="/customer.php?id=<?= (int)$c['id'] ?>">View</a>
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
