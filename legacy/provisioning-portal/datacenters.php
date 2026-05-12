<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';
$canEdit = in_array($role, ['owner', 'admin'], true);

$errors  = [];
$success = null;

// ---- Single DC detail view ----
$dcId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : null;

// ---- Handle POST actions (owner/admin only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action       = (string)($_POST['action'] ?? '');
        $allowedSt    = ['active', 'maintenance', 'decommissioned'];

        // ---- ADD DATA CENTER ----
        if ($action === 'add_dc') {
            $name    = trim((string)($_POST['name']             ?? ''));
            $code    = trim((string)($_POST['code']             ?? ''));
            $city    = trim((string)($_POST['city']             ?? ''));
            $state   = trim((string)($_POST['state']            ?? ''));
            $country = trim((string)($_POST['country']          ?? ''));
            $st      = trim((string)($_POST['status']           ?? 'active'));
            $cname   = trim((string)($_POST['contact_name']     ?? ''));
            $cemail  = trim((string)($_POST['contact_email']    ?? ''));
            $cphone  = trim((string)($_POST['contact_phone']    ?? ''));
            $power   = (int)($_POST['power_capacity_kw']        ?? 0);
            $sqft    = (int)($_POST['total_sqft']               ?? 0);
            $notes   = trim((string)($_POST['notes']            ?? ''));

            if ($name === '')                         $errors[] = 'Data center name is required.';
            if (!in_array($st, $allowedSt, true))    $errors[] = 'Invalid status.';

            if (!$errors) {
                $pdo->prepare("
                    INSERT INTO datacenters
                      (name, code, city, state, country, status,
                       contact_name, contact_email, contact_phone,
                       power_capacity_kw, total_sqft, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([$name, $code ?: null, $city ?: null, $state ?: null,
                             $country ?: null, $st, $cname ?: null, $cemail ?: null,
                             $cphone ?: null, $power ?: null, $sqft ?: null, $notes ?: null]);
                $newId = (int)$pdo->lastInsertId();
                header("Location: /datacenters.php?id={$newId}");
                exit;
            }

        // ---- EDIT DATA CENTER ----
        } elseif ($action === 'edit_dc' && ctype_digit($_POST['dc_id'] ?? '')) {
            $tid     = (int)$_POST['dc_id'];
            $dcId    = $tid;
            $name    = trim((string)($_POST['name']             ?? ''));
            $code    = trim((string)($_POST['code']             ?? ''));
            $city    = trim((string)($_POST['city']             ?? ''));
            $state   = trim((string)($_POST['state']            ?? ''));
            $country = trim((string)($_POST['country']          ?? ''));
            $st      = trim((string)($_POST['status']           ?? 'active'));
            $cname   = trim((string)($_POST['contact_name']     ?? ''));
            $cemail  = trim((string)($_POST['contact_email']    ?? ''));
            $cphone  = trim((string)($_POST['contact_phone']    ?? ''));
            $power   = (int)($_POST['power_capacity_kw']        ?? 0);
            $sqft    = (int)($_POST['total_sqft']               ?? 0);
            $notes   = trim((string)($_POST['notes']            ?? ''));

            if ($name === '')                         $errors[] = 'Data center name is required.';
            if (!in_array($st, $allowedSt, true))    $errors[] = 'Invalid status.';

            if (!$errors) {
                $pdo->prepare("
                    UPDATE datacenters
                       SET name=?, code=?, city=?, state=?, country=?, status=?,
                           contact_name=?, contact_email=?, contact_phone=?,
                           power_capacity_kw=?, total_sqft=?, notes=?
                     WHERE id=?
                ")->execute([$name, $code ?: null, $city ?: null, $state ?: null,
                             $country ?: null, $st, $cname ?: null, $cemail ?: null,
                             $cphone ?: null, $power ?: null, $sqft ?: null,
                             $notes ?: null, $tid]);
                header("Location: /datacenters.php?id={$tid}");
                exit;
            }

        // ---- DELETE DATA CENTER ----
        } elseif ($action === 'delete_dc' && ctype_digit($_POST['dc_id'] ?? '')) {
            $tid  = (int)$_POST['dc_id'];
            $chk  = $pdo->prepare("SELECT COUNT(*) FROM racks WHERE datacenter_id = ?");
            $chk->execute([$tid]);
            $cnt  = (int)$chk->fetchColumn();
            if ($cnt > 0) {
                $errors[] = "Cannot delete: {$cnt} rack(s) still in this DC. Remove all racks first.";
                $dcId = $tid;
            } else {
                $pdo->prepare("DELETE FROM datacenters WHERE id = ?")->execute([$tid]);
                header('Location: /datacenters.php');
                exit;
            }

        // ---- ADD RACK ----
        } elseif ($action === 'add_rack' && ctype_digit($_POST['dc_id'] ?? '')) {
            $dcId    = (int)$_POST['dc_id'];
            $name    = trim((string)($_POST['name']         ?? ''));
            $row     = trim((string)($_POST['row_label']    ?? ''));
            $pos     = trim((string)($_POST['position']     ?? ''));
            $units   = max(1, min(100, (int)($_POST['total_units'] ?? 42)));
            $amps    = (int)($_POST['power_amps']           ?? 0);
            $rst     = trim((string)($_POST['status']       ?? 'active'));
            $notes   = trim((string)($_POST['notes']        ?? ''));

            if ($name === '')                          $errors[] = 'Rack name is required.';
            if (!in_array($rst, $allowedSt, true))    $errors[] = 'Invalid status.';

            if (!$errors) {
                $pdo->prepare("
                    INSERT INTO racks
                      (datacenter_id, name, row_label, position, total_units, power_amps, status, notes)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([$dcId, $name, $row ?: null, $pos ?: null,
                             $units, $amps ?: null, $rst, $notes ?: null]);
                header("Location: /datacenters.php?id={$dcId}");
                exit;
            }

        // ---- EDIT RACK ----
        } elseif ($action === 'edit_rack' && ctype_digit($_POST['rack_id'] ?? '')) {
            $rid     = (int)$_POST['rack_id'];
            $dcId    = ctype_digit($_POST['dc_id'] ?? '') ? (int)$_POST['dc_id'] : $dcId;
            $name    = trim((string)($_POST['name']         ?? ''));
            $row     = trim((string)($_POST['row_label']    ?? ''));
            $pos     = trim((string)($_POST['position']     ?? ''));
            $units   = max(1, min(100, (int)($_POST['total_units'] ?? 42)));
            $amps    = (int)($_POST['power_amps']           ?? 0);
            $rst     = trim((string)($_POST['status']       ?? 'active'));
            $notes   = trim((string)($_POST['notes']        ?? ''));

            if ($name === '')                          $errors[] = 'Rack name is required.';
            if (!in_array($rst, $allowedSt, true))    $errors[] = 'Invalid status.';

            if (!$errors) {
                $pdo->prepare("
                    UPDATE racks
                       SET name=?, row_label=?, position=?, total_units=?, power_amps=?, status=?, notes=?
                     WHERE id=?
                ")->execute([$name, $row ?: null, $pos ?: null,
                             $units, $amps ?: null, $rst, $notes ?: null, $rid]);
                header("Location: /datacenters.php?id={$dcId}");
                exit;
            }

        // ---- DELETE RACK ----
        } elseif ($action === 'delete_rack' && ctype_digit($_POST['rack_id'] ?? '')) {
            $rid   = (int)$_POST['rack_id'];
            $dcId  = ctype_digit($_POST['dc_id'] ?? '') ? (int)$_POST['dc_id'] : $dcId;
            $chk   = $pdo->prepare("SELECT COUNT(*) FROM nodes WHERE rack_id = ?");
            $chk->execute([$rid]);
            $cnt   = (int)$chk->fetchColumn();
            if ($cnt > 0) {
                $errors[] = "Cannot delete: {$cnt} server(s) still in this rack. Reassign them first.";
            } else {
                $pdo->prepare("DELETE FROM racks WHERE id = ?")->execute([$rid]);
                header("Location: /datacenters.php?id={$dcId}");
                exit;
            }
        }
    }
}

$addMode     = ($canEdit && isset($_GET['mode']) && $_GET['mode'] === 'add');
$editMode    = ($canEdit && isset($_GET['edit_dc']));
$addRackMode = ($canEdit && isset($_GET['add_rack']));
$editRackId  = ($canEdit && ctype_digit($_GET['edit_rack'] ?? '')) ? (int)$_GET['edit_rack'] : 0;

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
                <?php if ($canEdit): ?>
                  <?php if ($editMode): ?>
                    <a class="btn" href="/datacenters.php?id=<?= $dcId ?>">Cancel Edit</a>
                  <?php else: ?>
                    <a class="btn" href="/datacenters.php?id=<?= $dcId ?>&edit_dc=1">Edit DC</a>
                  <?php endif; ?>
                  <?php $dcNodeCount = count($dcNodes); $dcRackCount = count($racks); ?>
                  <?php if ($dcRackCount === 0 && $dcNodeCount === 0): ?>
                    <form method="post" action="/datacenters.php" style="display:inline;"
                          onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($dc['name'])) ?>? This cannot be undone.')">
                      <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>" />
                      <input type="hidden" name="action" value="delete_dc" />
                      <input type="hidden" name="dc_id"  value="<?= $dcId ?>" />
                      <button class="btn" style="color:var(--danger,#f87171);" type="submit">Delete DC</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </header>

            <?php foreach ($errors as $e): ?>
              <div class="form-alert"><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>

            <?php if ($editMode): ?>
            <!-- ── Edit DC form ── -->
            <section class="panel">
              <header class="panel__header"><h2>Edit Data Center</h2></header>
              <div class="panel__body">
                <form method="post" action="/datacenters.php">
                  <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                  <input type="hidden" name="action" value="edit_dc" />
                  <input type="hidden" name="dc_id"  value="<?= $dcId ?>" />
                  <div class="node-edit-grid">
                    <div class="form-row">
                      <label>Name <span class="badge badge--danger">required</span></label>
                      <input type="text" name="name" value="<?= htmlspecialchars($dc['name']) ?>" required />
                    </div>
                    <div class="form-row">
                      <label>Code <span class="muted small">(e.g. NYC1)</span></label>
                      <input type="text" name="code" value="<?= htmlspecialchars((string)($dc['code']??'')) ?>" maxlength="20" />
                    </div>
                    <div class="form-row">
                      <label>City</label>
                      <input type="text" name="city" value="<?= htmlspecialchars((string)($dc['city']??'')) ?>" />
                    </div>
                    <div class="form-row">
                      <label>State / Region</label>
                      <input type="text" name="state" value="<?= htmlspecialchars((string)($dc['state']??'')) ?>" />
                    </div>
                    <div class="form-row">
                      <label>Country</label>
                      <input type="text" name="country" value="<?= htmlspecialchars((string)($dc['country']??'')) ?>" />
                    </div>
                    <div class="form-row">
                      <label>Status</label>
                      <select name="status">
                        <?php foreach (['active'=>'Active','maintenance'=>'Maintenance','decommissioned'=>'Decommissioned'] as $v=>$l): ?>
                          <option value="<?= $v ?>" <?= ($dc['status']??'active')===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-row">
                      <label>Contact Name</label>
                      <input type="text" name="contact_name" value="<?= htmlspecialchars((string)($dc['contact_name']??'')) ?>" />
                    </div>
                    <div class="form-row">
                      <label>Contact Email</label>
                      <input type="email" name="contact_email" value="<?= htmlspecialchars((string)($dc['contact_email']??'')) ?>" />
                    </div>
                    <div class="form-row">
                      <label>Contact Phone</label>
                      <input type="text" name="contact_phone" value="<?= htmlspecialchars((string)($dc['contact_phone']??'')) ?>" />
                    </div>
                    <div class="form-row">
                      <label>Power Capacity (kW)</label>
                      <input type="number" name="power_capacity_kw" value="<?= (int)($dc['power_capacity_kw']??0) ?: '' ?>" min="0" />
                    </div>
                    <div class="form-row">
                      <label>Floor Space (sqft)</label>
                      <input type="number" name="total_sqft" value="<?= (int)($dc['total_sqft']??0) ?: '' ?>" min="0" />
                    </div>
                  </div>
                  <div style="padding:0 18px 14px;">
                    <div class="form-row" style="margin-bottom:14px;">
                      <label>Notes</label>
                      <textarea name="notes" rows="3"><?= htmlspecialchars((string)($dc['notes']??'')) ?></textarea>
                    </div>
                    <div style="display:flex;gap:8px;">
                      <button class="btn btn--primary" type="submit">Save changes</button>
                      <a class="btn" href="/datacenters.php?id=<?= $dcId ?>">Cancel</a>
                    </div>
                  </div>
                </form>
              </div>
            </section>
            <?php endif; ?>

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
                <div style="display:flex;gap:8px;align-items:center;">
                  <a class="link" href="/rack.php?dc=<?= $dcId ?>">Rack View →</a>
                  <?php if ($canEdit): ?>
                    <?php if ($addRackMode): ?>
                      <a class="btn" href="/datacenters.php?id=<?= $dcId ?>">Cancel</a>
                    <?php else: ?>
                      <a class="btn btn--primary" style="padding:4px 12px;font-size:12px;"
                         href="/datacenters.php?id=<?= $dcId ?>&add_rack=1">+ Add Rack</a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </header>
              <div class="panel__body">

                <?php if ($addRackMode): ?>
                <!-- ── Add Rack Form ── -->
                <form method="post" action="/datacenters.php" style="padding:0 0 18px;">
                  <input type="hidden" name="csrf"      value="<?= htmlspecialchars(csrf_token()) ?>" />
                  <input type="hidden" name="action"    value="add_rack" />
                  <input type="hidden" name="dc_id"     value="<?= $dcId ?>" />
                  <div class="node-edit-grid">
                    <div class="form-row">
                      <label>Rack Name <span class="badge badge--danger">required</span></label>
                      <input type="text" name="name" placeholder="e.g. R01 or Rack-A-01" required />
                    </div>
                    <div class="form-row">
                      <label>Row Label</label>
                      <input type="text" name="row_label" placeholder="e.g. A" maxlength="50" />
                    </div>
                    <div class="form-row">
                      <label>Position</label>
                      <input type="text" name="position" placeholder="e.g. A-03" maxlength="50" />
                    </div>
                    <div class="form-row">
                      <label>Total Units</label>
                      <input type="number" name="total_units" value="42" min="1" max="100" />
                    </div>
                    <div class="form-row">
                      <label>Power (Amps)</label>
                      <input type="number" name="power_amps" placeholder="e.g. 20" min="0" max="255" />
                    </div>
                    <div class="form-row">
                      <label>Status</label>
                      <select name="status">
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="decommissioned">Decommissioned</option>
                      </select>
                    </div>
                  </div>
                  <div style="padding:0 0 0 0;">
                    <div class="form-row" style="margin-bottom:10px;">
                      <label>Notes</label>
                      <input type="text" name="notes" placeholder="Optional notes" />
                    </div>
                    <div style="display:flex;gap:8px;">
                      <button class="btn btn--primary" type="submit">Add Rack</button>
                      <a class="btn" href="/datacenters.php?id=<?= $dcId ?>">Cancel</a>
                    </div>
                  </div>
                </form>
                <?php endif; ?>

                <?php if (!$racks): ?>
                  <p class="muted">No racks configured in this data center.<?= $canEdit ? ' Use "+ Add Rack" above to create one.' : '' ?></p>
                <?php else: ?>

                  <!-- Rack table with inline edit -->
                  <table class="table">
                    <thead>
                      <tr>
                        <th>Rack</th>
                        <th>Row</th>
                        <th>Units</th>
                        <th>Used</th>
                        <th>Power</th>
                        <th>Servers</th>
                        <th>Status</th>
                        <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($racks as $r): ?>
                        <tr>
                          <td>
                            <a class="link" href="/rack.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a>
                            <?php if ($r['position']): ?><br><span class="muted small"><?= htmlspecialchars($r['position']) ?></span><?php endif; ?>
                          </td>
                          <td class="muted small"><?= $r['row_label'] ? htmlspecialchars($r['row_label']) : '—' ?></td>
                          <td><?= (int)$r['total_units'] ?>U</td>
                          <td><?= (int)($r['used_units']??0) ?>U</td>
                          <td class="muted small"><?= $r['power_amps'] ? (int)$r['power_amps'].'A' : '—' ?></td>
                          <td><?= (int)$r['node_count'] ?></td>
                          <td><span class="<?= status_badge_class((string)($r['status']??'active')) ?>"><?= htmlspecialchars($r['status']??'active') ?></span></td>
                          <?php if ($canEdit): ?>
                            <td style="white-space:nowrap;">
                              <?php if ($editRackId === (int)$r['id']): ?>
                                <a class="btn" style="padding:4px 10px;font-size:12px;"
                                   href="/datacenters.php?id=<?= $dcId ?>">Cancel</a>
                              <?php else: ?>
                                <a class="btn" style="padding:4px 10px;font-size:12px;"
                                   href="/datacenters.php?id=<?= $dcId ?>&edit_rack=<?= (int)$r['id'] ?>">Edit</a>
                              <?php endif; ?>
                              <?php if ((int)$r['node_count'] === 0): ?>
                                <form method="post" action="/datacenters.php" style="display:inline;"
                                      onsubmit="return confirm('Delete rack <?= htmlspecialchars(addslashes($r['name'])) ?>?')">
                                  <input type="hidden" name="csrf"     value="<?= htmlspecialchars(csrf_token()) ?>" />
                                  <input type="hidden" name="action"   value="delete_rack" />
                                  <input type="hidden" name="rack_id"  value="<?= (int)$r['id'] ?>" />
                                  <input type="hidden" name="dc_id"    value="<?= $dcId ?>" />
                                  <button class="btn" style="padding:4px 10px;font-size:12px;color:var(--danger,#f87171);" type="submit">Del</button>
                                </form>
                              <?php else: ?>
                                <span class="muted small" title="Remove servers first">Del</span>
                              <?php endif; ?>
                            </td>
                          <?php endif; ?>
                        </tr>
                        <?php if ($canEdit && $editRackId === (int)$r['id']): ?>
                        <tr class="edit-row-expanded">
                          <td colspan="<?= $canEdit ? 8 : 7 ?>">
                            <form method="post" action="/datacenters.php">
                              <input type="hidden" name="csrf"     value="<?= htmlspecialchars(csrf_token()) ?>" />
                              <input type="hidden" name="action"   value="edit_rack" />
                              <input type="hidden" name="rack_id"  value="<?= (int)$r['id'] ?>" />
                              <input type="hidden" name="dc_id"    value="<?= $dcId ?>" />
                              <div class="node-edit-grid">
                                <div class="form-row">
                                  <label>Name <span class="badge badge--danger">required</span></label>
                                  <input type="text" name="name" value="<?= htmlspecialchars($r['name']) ?>" required />
                                </div>
                                <div class="form-row">
                                  <label>Row Label</label>
                                  <input type="text" name="row_label" value="<?= htmlspecialchars((string)($r['row_label']??'')) ?>" />
                                </div>
                                <div class="form-row">
                                  <label>Position</label>
                                  <input type="text" name="position" value="<?= htmlspecialchars((string)($r['position']??'')) ?>" />
                                </div>
                                <div class="form-row">
                                  <label>Total Units</label>
                                  <input type="number" name="total_units" value="<?= (int)($r['total_units']??42) ?>" min="1" max="100" />
                                </div>
                                <div class="form-row">
                                  <label>Power (Amps)</label>
                                  <input type="number" name="power_amps" value="<?= (int)($r['power_amps']??0) ?: '' ?>" min="0" max="255" />
                                </div>
                                <div class="form-row">
                                  <label>Status</label>
                                  <select name="status">
                                    <?php foreach (['active'=>'Active','maintenance'=>'Maintenance','decommissioned'=>'Decommissioned'] as $v=>$l): ?>
                                      <option value="<?= $v ?>" <?= ($r['status']??'active')===$v?'selected':'' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>
                              </div>
                              <div style="padding:0 0 10px;">
                                <div class="form-row" style="margin-bottom:10px;">
                                  <label>Notes</label>
                                  <input type="text" name="notes" value="<?= htmlspecialchars((string)($r['notes']??'')) ?>" />
                                </div>
                                <div style="display:flex;gap:8px;">
                                  <button class="btn btn--primary" type="submit">Save</button>
                                  <a class="btn" href="/datacenters.php?id=<?= $dcId ?>">Cancel</a>
                                </div>
                              </div>
                            </form>
                          </td>
                        </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
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
              <?php if ($canEdit): ?>
              <div class="page-header__actions">
                <?php if ($addMode): ?>
                  <a class="btn" href="/datacenters.php">Cancel</a>
                <?php else: ?>
                  <a class="btn btn--primary" href="/datacenters.php?mode=add">+ Add Data Center</a>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </header>

            <?php foreach ($errors as $e): ?>
              <div class="form-alert"><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>

            <?php if ($addMode): ?>
            <!-- ── Add DC form ── -->
            <section class="panel">
              <header class="panel__header"><h2>New Data Center</h2></header>
              <div class="panel__body">
                <form method="post" action="/datacenters.php">
                  <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                  <input type="hidden" name="action" value="add_dc" />
                  <div class="node-edit-grid">
                    <div class="form-row">
                      <label>Name <span class="badge badge--danger">required</span></label>
                      <input type="text" name="name" required />
                    </div>
                    <div class="form-row">
                      <label>Code <span class="muted small">(e.g. NYC1)</span></label>
                      <input type="text" name="code" maxlength="20" />
                    </div>
                    <div class="form-row">
                      <label>City</label>
                      <input type="text" name="city" />
                    </div>
                    <div class="form-row">
                      <label>State / Region</label>
                      <input type="text" name="state" />
                    </div>
                    <div class="form-row">
                      <label>Country</label>
                      <input type="text" name="country" />
                    </div>
                    <div class="form-row">
                      <label>Status</label>
                      <select name="status">
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="decommissioned">Decommissioned</option>
                      </select>
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
                    <div class="form-row">
                      <label>Power Capacity (kW)</label>
                      <input type="number" name="power_capacity_kw" min="0" />
                    </div>
                    <div class="form-row">
                      <label>Floor Space (sqft)</label>
                      <input type="number" name="total_sqft" min="0" />
                    </div>
                  </div>
                  <div style="padding:0 18px 14px;">
                    <div class="form-row" style="margin-bottom:14px;">
                      <label>Notes</label>
                      <textarea name="notes" rows="3"></textarea>
                    </div>
                    <div style="display:flex;gap:8px;">
                      <button class="btn btn--primary" type="submit">Add Data Center</button>
                      <a class="btn" href="/datacenters.php">Cancel</a>
                    </div>
                  </div>
                </form>
              </div>
            </section>
            <?php endif; ?>

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
                          <td style="white-space:nowrap;">
                            <a class="btn" style="padding:4px 10px;font-size:12px;" href="/datacenters.php?id=<?= (int)$d['id'] ?>">View</a>
                            <?php if ($canEdit): ?>
                              <a class="btn" style="padding:4px 10px;font-size:12px;" href="/datacenters.php?id=<?= (int)$d['id'] ?>&edit_dc=1">Edit</a>
                              <?php if ((int)$d['rack_count'] === 0 && (int)$d['node_count'] === 0): ?>
                                <form method="post" action="/datacenters.php" style="display:inline;"
                                      onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($d['name'])) ?>?')">
                                  <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                                  <input type="hidden" name="action" value="delete_dc" />
                                  <input type="hidden" name="dc_id"  value="<?= (int)$d['id'] ?>" />
                                  <button class="btn" style="padding:4px 10px;font-size:12px;color:var(--danger,#f87171);" type="submit">Del</button>
                                </form>
                              <?php else: ?>
                                <span class="muted small" title="Remove racks/servers first">Del</span>
                              <?php endif; ?>
                            <?php endif; ?>
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
