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

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: /customers.php');
    exit;
}

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // ---- EDIT ----
        if ($action === 'edit' && ctype_digit($_POST['customer_id'] ?? '')) {
            $tid       = (int)$_POST['customer_id'];
            $name      = trim((string)($_POST['name']           ?? ''));
            $ctype     = trim((string)($_POST['company_type']   ?? ''));
            $sl        = trim((string)($_POST['service_level']  ?? ''));
            $status    = trim((string)($_POST['account_status'] ?? 'active'));
            $cname     = trim((string)($_POST['contact_name']   ?? ''));
            $cemail    = trim((string)($_POST['contact_email']  ?? ''));
            $cphone    = trim((string)($_POST['contact_phone']  ?? ''));
            $accno     = trim((string)($_POST['account_number'] ?? ''));
            $mrr       = (int)($_POST['mrr_dollars']            ?? 0);
            $address   = trim((string)($_POST['address']        ?? ''));
            $city      = trim((string)($_POST['city']           ?? ''));
            $state     = trim((string)($_POST['state']          ?? ''));
            $country   = trim((string)($_POST['country']        ?? ''));
            $cstart    = trim((string)($_POST['contract_start'] ?? ''));
            $cend      = trim((string)($_POST['contract_end']   ?? ''));
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
                    UPDATE customers
                       SET name=?, company_type=?, service_level=?, account_status=?,
                           contact_name=?, contact_email=?, contact_phone=?,
                           account_number=?, mrr_cents=?,
                           address=?, city=?, state=?, country=?,
                           contract_start=?, contract_end=?, notes=?
                     WHERE id=?
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
                    $address ?: null,
                    $city    ?: null,
                    $state   ?: null,
                    $country ?: null,
                    ($cstart !== '' ? $cstart : null),
                    ($cend   !== '' ? $cend   : null),
                    $notes   ?: null,
                    $tid,
                ]);
                header("Location: /customer.php?id={$tid}");
                exit;
            }

        // ---- DELETE ----
        } elseif ($action === 'delete' && ctype_digit($_POST['customer_id'] ?? '')) {
            $tid = (int)$_POST['customer_id'];
            $chk = $pdo->prepare("SELECT COUNT(*) FROM nodes WHERE customer_id = ?");
            $chk->execute([$tid]);
            $cnt = (int)$chk->fetchColumn();
            if ($cnt > 0) {
                $errors[] = "Cannot delete: {$cnt} server(s) assigned to this customer. Reassign them first.";
            } else {
                $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$tid]);
                header('Location: /customers.php');
                exit;
            }
        }
    }
}

$editMode = ($canEdit && isset($_GET['edit']));

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
              <?php if ($canEdit): ?>
                <?php if ($editMode): ?>
                  <a class="btn" href="/customer.php?id=<?= $id ?>">Cancel Edit</a>
                <?php else: ?>
                  <a class="btn" href="/customer.php?id=<?= $id ?>&edit=1">Edit</a>
                <?php endif; ?>
                <?php if ($totalServers === 0): ?>
                  <form method="post" action="/customer.php" style="display:inline;"
                        onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($customer['name'])) ?>? This cannot be undone.')">
                    <input type="hidden" name="csrf"        value="<?= htmlspecialchars(csrf_token()) ?>" />
                    <input type="hidden" name="action"      value="delete" />
                    <input type="hidden" name="customer_id" value="<?= $id ?>" />
                    <button class="btn" style="color:var(--danger,#f87171);" type="submit">Delete</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </header>

          <?php foreach ($errors as $e): ?>
            <div class="form-alert"><?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>

          <?php if ($editMode): ?>
          <!-- ── Edit Customer form ── -->
          <section class="panel">
            <header class="panel__header"><h2>Edit Customer</h2></header>
            <div class="panel__body">
              <form method="post" action="/customer.php">
                <input type="hidden" name="csrf"        value="<?= htmlspecialchars(csrf_token()) ?>" />
                <input type="hidden" name="action"      value="edit" />
                <input type="hidden" name="customer_id" value="<?= $id ?>" />
                <div class="node-edit-grid">
                  <div class="form-row">
                    <label>Company Name <span class="badge badge--danger">required</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($customer['name']) ?>" required />
                  </div>
                  <div class="form-row">
                    <label>Account Number</label>
                    <input type="text" name="account_number" value="<?= htmlspecialchars((string)($customer['account_number']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>Company Type</label>
                    <select name="company_type">
                      <option value="">— select —</option>
                      <?php foreach (['hosting'=>'Hosting','msp'=>'MSP','enterprise'=>'Enterprise','smb'=>'SMB','other'=>'Other'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($customer['company_type']??'')===$v?'selected':'' ?>><?= $l ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-row">
                    <label>Service Level</label>
                    <select name="service_level">
                      <option value="">— select —</option>
                      <?php foreach (['platinum'=>'Platinum','gold'=>'Gold','silver'=>'Silver','bronze'=>'Bronze','standard'=>'Standard'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($customer['service_level']??'')===$v?'selected':'' ?>><?= $l ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-row">
                    <label>Account Status</label>
                    <select name="account_status">
                      <?php foreach (['active'=>'Active','suspended'=>'Suspended','churned'=>'Churned'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($customer['account_status']??'active')===$v?'selected':'' ?>><?= $l ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-row">
                    <label>MRR ($)</label>
                    <input type="number" name="mrr_dollars" min="0"
                           value="<?= $customer['mrr_cents'] ? (int)round((int)$customer['mrr_cents'] / 100) : '' ?>" />
                  </div>
                  <div class="form-row">
                    <label>Contact Name</label>
                    <input type="text" name="contact_name" value="<?= htmlspecialchars((string)($customer['contact_name']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>Contact Email</label>
                    <input type="email" name="contact_email" value="<?= htmlspecialchars((string)($customer['contact_email']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>Contact Phone</label>
                    <input type="text" name="contact_phone" value="<?= htmlspecialchars((string)($customer['contact_phone']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>Address</label>
                    <input type="text" name="address" value="<?= htmlspecialchars((string)($customer['address']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>City</label>
                    <input type="text" name="city" value="<?= htmlspecialchars((string)($customer['city']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>State / Region</label>
                    <input type="text" name="state" value="<?= htmlspecialchars((string)($customer['state']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>Country</label>
                    <input type="text" name="country" value="<?= htmlspecialchars((string)($customer['country']??'')) ?>" />
                  </div>
                  <div class="form-row">
                    <label>Contract Start</label>
                    <input type="date" name="contract_start" value="<?= htmlspecialchars($customer['contract_start'] ? substr($customer['contract_start'],0,10) : '') ?>" />
                  </div>
                  <div class="form-row">
                    <label>Contract End</label>
                    <input type="date" name="contract_end" value="<?= htmlspecialchars($customer['contract_end'] ? substr($customer['contract_end'],0,10) : '') ?>" />
                  </div>
                </div>
                <div style="padding:0 18px 14px;">
                  <div class="form-row" style="margin-bottom:14px;">
                    <label>Notes</label>
                    <textarea name="notes" rows="3"><?= htmlspecialchars((string)($customer['notes']??'')) ?></textarea>
                  </div>
                  <div style="display:flex;gap:8px;">
                    <button class="btn btn--primary" type="submit">Save changes</button>
                    <a class="btn" href="/customer.php?id=<?= $id ?>">Cancel</a>
                  </div>
                </div>
              </form>
            </div>
          </section>
          <?php endif; ?>

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
