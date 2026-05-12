<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u       = current_user();
$role    = $u['role'] ?? 'operator';
$canEdit = in_array($role, ['owner', 'admin'], true);

$errors  = [];
$success = null;

// ---- Handle POST (add customer) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add') {
            $name      = trim((string)($_POST['name']           ?? ''));
            $ctype     = trim((string)($_POST['company_type']   ?? ''));
            $sl        = trim((string)($_POST['service_level']  ?? ''));
            $status    = trim((string)($_POST['account_status'] ?? 'active'));
            $cname     = trim((string)($_POST['contact_name']   ?? ''));
            $cemail    = trim((string)($_POST['contact_email']  ?? ''));
            $cphone    = trim((string)($_POST['contact_phone']  ?? ''));
            $accno     = trim((string)($_POST['account_number'] ?? ''));
            $mrr       = (int)($_POST['mrr_dollars'] ?? 0);
            $notes     = trim((string)($_POST['notes']          ?? ''));

            $allowedStatus = ['active', 'suspended', 'churned'];
            $allowedType   = ['hosting', 'msp', 'enterprise', 'smb', 'other', ''];
            $allowedSL     = ['platinum', 'gold', 'silver', 'bronze', 'standard', ''];

            if ($name === '')                               $errors[] = 'Customer name is required.';
            if (!in_array($status, $allowedStatus, true))  $errors[] = 'Invalid account status.';
            if (!in_array($ctype,  $allowedType,   true))  $errors[] = 'Invalid company type.';
            if (!in_array($sl,     $allowedSL,     true))  $errors[] = 'Invalid service level.';

            if (!$errors) {
                $pdo->prepare("
                    INSERT INTO customers
                      (name, company_type, service_level, account_status,
                       contact_name, contact_email, contact_phone,
                       account_number, mrr_cents, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $name,
                    $ctype   ?: null,
                    $sl      ?: null,
                    $status,
                    $cname   ?: null,
                    $cemail  ?: null,
                    $cphone  ?: null,
                    $accno   ?: null,
                    $mrr > 0 ? $mrr * 100 : null,
                    $notes   ?: null,
                ]);
                header('Location: /customers.php');
                exit;
            }
        }
    }
}

$addMode = ($canEdit && isset($_GET['mode']) && $_GET['mode'] === 'add');

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
            <?php if ($canEdit): ?>
            <div class="page-header__actions">
              <?php if ($addMode): ?>
                <a class="btn" href="/customers.php">Cancel</a>
              <?php else: ?>
                <a class="btn btn--primary" href="/customers.php?mode=add">+ Add Customer</a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </header>

          <?php foreach ($errors as $e): ?>
            <div class="form-alert"><?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>

          <?php if ($addMode): ?>
          <!-- ── Add Customer form ── -->
          <section class="panel">
            <header class="panel__header"><h2>New Customer</h2></header>
            <div class="panel__body">
              <form method="post" action="/customers.php">
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                <input type="hidden" name="action" value="add" />
                <div class="node-edit-grid">
                  <div class="form-row">
                    <label>Company Name <span class="badge badge--danger">required</span></label>
                    <input type="text" name="name" required />
                  </div>
                  <div class="form-row">
                    <label>Account Number</label>
                    <input type="text" name="account_number" placeholder="e.g. CUST-0042" />
                  </div>
                  <div class="form-row">
                    <label>Company Type</label>
                    <select name="company_type">
                      <option value="">— select —</option>
                      <option value="hosting">Hosting</option>
                      <option value="msp">MSP</option>
                      <option value="enterprise">Enterprise</option>
                      <option value="smb">SMB</option>
                      <option value="other">Other</option>
                    </select>
                  </div>
                  <div class="form-row">
                    <label>Service Level</label>
                    <select name="service_level">
                      <option value="">— select —</option>
                      <option value="platinum">Platinum</option>
                      <option value="gold">Gold</option>
                      <option value="silver">Silver</option>
                      <option value="bronze">Bronze</option>
                      <option value="standard">Standard</option>
                    </select>
                  </div>
                  <div class="form-row">
                    <label>Account Status</label>
                    <select name="account_status">
                      <option value="active">Active</option>
                      <option value="suspended">Suspended</option>
                      <option value="churned">Churned</option>
                    </select>
                  </div>
                  <div class="form-row">
                    <label>MRR ($)</label>
                    <input type="number" name="mrr_dollars" min="0" placeholder="Monthly recurring revenue" />
                  </div>
                  <div class="form-row">
                    <label>Contact Name</label>
                    <input type="text" name="contact_name" />
                  </div>
                  <div class="form-row">
                    <label>Contact Email</label>
                    <input type="email" name="contact_email" />
                  </div>
                  <div class="form-row">
                    <label>Contact Phone</label>
                    <input type="text" name="contact_phone" />
                  </div>
                </div>
                <div style="padding:0 18px 14px;">
                  <div class="form-row" style="margin-bottom:14px;">
                    <label>Notes</label>
                    <textarea name="notes" rows="3"></textarea>
                  </div>
                  <div style="display:flex;gap:8px;">
                    <button class="btn btn--primary" type="submit">Add Customer</button>
                    <a class="btn" href="/customers.php">Cancel</a>
                  </div>
                </div>
              </form>
            </div>
          </section>
          <?php endif; ?>

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
