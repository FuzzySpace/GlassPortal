<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/auth/bootstrap.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

if (!in_array($role, ['owner', 'admin', 'operator'], true)) {
    http_response_code(403);
    exit('Access denied');
}

// Edit mode or Add mode
$nodeId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : null;
$isEdit = $nodeId !== null;

$node = [];
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch() ?: [];
    if (!$node) {
        header('Location: /hardware.php');
        exit;
    }
}

// ---- Dropdown data ----
$datacenters = $pdo->query("SELECT id, name, code FROM datacenters ORDER BY name ASC")->fetchAll();
$racks       = $pdo->query("SELECT r.id, r.name, r.total_units, d.name AS dc_name, d.id AS dc_id FROM racks r JOIN datacenters d ON d.id=r.datacenter_id ORDER BY d.name, r.name")->fetchAll();
$customers   = $pdo->query("SELECT id, name FROM customers WHERE account_status='active' ORDER BY name ASC")->fetchAll();

// ---- Handle POST ----
$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        // Sanitise inputs
        $fields = [
            'name'           => trim((string)($_POST['name'] ?? '')),
            'site'           => trim((string)($_POST['site'] ?? '')),
            'provider'       => trim((string)($_POST['provider'] ?? '')),
            'role'           => trim((string)($_POST['role'] ?? '')),
            'status'         => trim((string)($_POST['status'] ?? 'unknown')),
            'mgmt_ip'        => trim((string)($_POST['mgmt_ip'] ?? '')),
            'make'           => trim((string)($_POST['make'] ?? '')),
            'model'          => trim((string)($_POST['model'] ?? '')),
            'asset_tag'      => trim((string)($_POST['asset_tag'] ?? '')),
            'serial_number'  => trim((string)($_POST['serial_number'] ?? '')),
            'cpu_model'      => trim((string)($_POST['cpu_model'] ?? '')),
            'cpu_cores'      => (string)($_POST['cpu_cores'] ?? ''),
            'ram_gb'         => (string)($_POST['ram_gb'] ?? ''),
            'storage_gb'     => (string)($_POST['storage_gb'] ?? ''),
            'os_type'        => trim((string)($_POST['os_type'] ?? '')),
            'os_version'     => trim((string)($_POST['os_version'] ?? '')),
            'datacenter_id'  => (string)($_POST['datacenter_id'] ?? ''),
            'rack_id'        => (string)($_POST['rack_id'] ?? ''),
            'rack_unit_start'=> (string)($_POST['rack_unit_start'] ?? ''),
            'rack_unit_size' => (string)($_POST['rack_unit_size'] ?? '1'),
            'customer_id'    => (string)($_POST['customer_id'] ?? ''),
            'notes'          => trim((string)($_POST['notes'] ?? '')),
        ];

        // Validate required
        if ($fields['name'] === '') $errors[] = 'Server name is required.';
        $allowedStatuses = ['healthy','degraded','warning','down','unknown'];
        if (!in_array($fields['status'], $allowedStatuses, true)) $errors[] = 'Invalid status value.';

        // Coerce nullable ints
        $dcId        = $fields['datacenter_id'] !== '' ? (int)$fields['datacenter_id'] : null;
        $rackId      = $fields['rack_id'] !== '' ? (int)$fields['rack_id'] : null;
        $rackUnit    = $fields['rack_unit_start'] !== '' ? (int)$fields['rack_unit_start'] : null;
        $rackSize    = $fields['rack_unit_size'] !== '' ? max(1,(int)$fields['rack_unit_size']) : 1;
        $custId      = $fields['customer_id'] !== '' ? (int)$fields['customer_id'] : null;
        $cpuCores    = $fields['cpu_cores'] !== '' ? (int)$fields['cpu_cores'] : null;
        $ramGb       = $fields['ram_gb'] !== '' ? (int)$fields['ram_gb'] : null;
        $storageGb   = $fields['storage_gb'] !== '' ? (int)$fields['storage_gb'] : null;

        if (!$errors) {
            try {
                if ($isEdit) {
                    $stmt = $pdo->prepare("
                        UPDATE nodes SET
                          name=?, site=?, provider=?, role=?, status=?, mgmt_ip=?,
                          make=?, model=?, asset_tag=?, serial_number=?,
                          cpu_model=?, cpu_cores=?, ram_gb=?, storage_gb=?,
                          os_type=?, os_version=?,
                          datacenter_id=?, rack_id=?, rack_unit_start=?, rack_unit_size=?,
                          customer_id=?, notes=?,
                          updated_at=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $fields['name'], $fields['site'] ?: null, $fields['provider'] ?: null,
                        $fields['role'] ?: null, $fields['status'], $fields['mgmt_ip'] ?: null,
                        $fields['make'] ?: null, $fields['model'] ?: null,
                        $fields['asset_tag'] ?: null, $fields['serial_number'] ?: null,
                        $fields['cpu_model'] ?: null, $cpuCores, $ramGb, $storageGb,
                        $fields['os_type'] ?: null, $fields['os_version'] ?: null,
                        $dcId, $rackId, $rackUnit, $rackSize,
                        $custId, $fields['notes'] ?: null,
                        $nodeId,
                    ]);
                    $success = 'Server updated successfully.';
                    // Reload node
                    $reloadStmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
                    $reloadStmt->execute([$nodeId]);
                    $node = $reloadStmt->fetch() ?: $node;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO nodes
                          (name, site, provider, role, status, mgmt_ip,
                           make, model, asset_tag, serial_number,
                           cpu_model, cpu_cores, ram_gb, storage_gb,
                           os_type, os_version,
                           datacenter_id, rack_id, rack_unit_start, rack_unit_size,
                           customer_id, notes, created_at)
                        VALUES
                          (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    $stmt->execute([
                        $fields['name'], $fields['site'] ?: null, $fields['provider'] ?: null,
                        $fields['role'] ?: null, $fields['status'], $fields['mgmt_ip'] ?: null,
                        $fields['make'] ?: null, $fields['model'] ?: null,
                        $fields['asset_tag'] ?: null, $fields['serial_number'] ?: null,
                        $fields['cpu_model'] ?: null, $cpuCores, $ramGb, $storageGb,
                        $fields['os_type'] ?: null, $fields['os_version'] ?: null,
                        $dcId, $rackId, $rackUnit, $rackSize,
                        $custId, $fields['notes'] ?: null,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    header("Location: /server.php?id=$newId");
                    exit;
                }
            } catch (Throwable $e) {
                if ($e->getCode() == 1062 && str_contains($e->getMessage(), 'uq_nodes_name')) {
                    $errors[] = 'A server named "' . htmlspecialchars($fields['name']) . '" already exists. Use a unique name.';
                } else {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Helper to get field value (POST > node record)
function fv(string $key, array $node, array $post = []): string {
    if (!empty($post)) {
        return htmlspecialchars((string)($_POST[$key] ?? $node[$key] ?? ''));
    }
    return htmlspecialchars((string)($node[$key] ?? ''));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= $isEdit ? 'Edit '.htmlspecialchars($node['name']??'Server') : 'Add Server' ?> • NOC Portal</title>
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
            <?php if ($isEdit): ?>
              <span class="muted"> / </span>
              <a class="link" href="/server.php?id=<?= $nodeId ?>"><?= htmlspecialchars($node['name']??'Server') ?></a>
            <?php endif; ?>
            <span class="muted"> / </span>
            <span><?= $isEdit ? 'Edit' : 'Add Server' ?></span>
          </nav>

          <header class="page-header">
            <div class="page-header__titles">
              <h1><?= $isEdit ? 'Edit Server' : 'Add Server' ?></h1>
              <p class="muted">
                <?= $isEdit
                  ? 'Update hardware details, location, customer assignment, and system info.'
                  : 'Register a new server into the hardware inventory.' ?>
              </p>
            </div>
            <?php if ($isEdit): ?>
              <div class="page-header__actions">
                <a class="btn" href="/provision.php?node=<?= $nodeId ?>">Provisioning Checklist →</a>
                <a class="btn" href="/server.php?id=<?= $nodeId ?>">Cancel</a>
              </div>
            <?php endif; ?>
          </header>

          <!-- Alerts -->
          <?php foreach ($errors as $e): ?>
            <div class="form-alert"><?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
          <?php if ($success): ?>
            <div class="form-alert" style="border-color:rgba(52,211,153,0.35); background:rgba(52,211,153,0.10);">
              <?= htmlspecialchars($success) ?>
            </div>
          <?php endif; ?>

          <form method="post" action="/node_edit.php<?= $isEdit ? '?id='.$nodeId : '' ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />

            <!-- ── Identity ───────────────────────────────────────────── -->
            <section class="panel">
              <header class="panel__header"><h2>Identity</h2></header>
              <div class="panel__body">
                <div class="node-edit-grid">

                  <div class="form-row">
                    <label for="f_name">Server name <span class="badge badge--danger">required</span></label>
                    <input id="f_name" type="text" name="name"
                           value="<?= htmlspecialchars((string)($_POST['name'] ?? $node['name'] ?? '')) ?>"
                           placeholder="e.g. prod-web-01" required />
                  </div>

                  <div class="form-row">
                    <label for="f_status">Status</label>
                    <select id="f_status" name="status">
                      <?php foreach (['unknown','healthy','degraded','warning','down'] as $s): ?>
                        <option value="<?= $s ?>" <?= (($_POST['status'] ?? $node['status'] ?? 'unknown')===$s)?'selected':'' ?>>
                          <?= ucfirst($s) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-row">
                    <label for="f_role">Role / Function</label>
                    <input id="f_role" type="text" name="role"
                           value="<?= htmlspecialchars((string)($_POST['role'] ?? $node['role'] ?? '')) ?>"
                           placeholder="e.g. web, db, storage, hypervisor, firewall" />
                  </div>

                  <div class="form-row">
                    <label for="f_site">Site label</label>
                    <input id="f_site" type="text" name="site"
                           value="<?= htmlspecialchars((string)($_POST['site'] ?? $node['site'] ?? '')) ?>"
                           placeholder="e.g. syd-dc1, mel-dc2" />
                  </div>

                  <div class="form-row">
                    <label for="f_provider">Provider</label>
                    <input id="f_provider" type="text" name="provider"
                           value="<?= htmlspecialchars((string)($_POST['provider'] ?? $node['provider'] ?? '')) ?>"
                           placeholder="e.g. on-prem, AWS, Azure" />
                  </div>

                  <div class="form-row">
                    <label for="f_mgmt_ip">Management IP</label>
                    <input id="f_mgmt_ip" type="text" name="mgmt_ip"
                           value="<?= htmlspecialchars((string)($_POST['mgmt_ip'] ?? $node['mgmt_ip'] ?? '')) ?>"
                           placeholder="IPMI / iDRAC / iLO IP address" />
                  </div>

                </div>
              </div>
            </section>

            <!-- ── Physical Location ──────────────────────────────────── -->
            <section class="panel">
              <header class="panel__header"><h2>Physical Location</h2></header>
              <div class="panel__body">
                <div class="node-edit-grid">

                  <div class="form-row">
                    <label for="f_dc">Data Center</label>
                    <select id="f_dc" name="datacenter_id" onchange="filterRacks(this.value)">
                      <option value="">— Not assigned —</option>
                      <?php foreach ($datacenters as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"
                          <?= (($_POST['datacenter_id'] ?? $node['datacenter_id'] ?? '')==(string)$d['id'])?'selected':'' ?>>
                          <?= htmlspecialchars($d['code'] ? $d['code'].' – '.$d['name'] : $d['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-row">
                    <label for="f_rack">Rack</label>
                    <select id="f_rack" name="rack_id">
                      <option value="">— Not racked —</option>
                      <?php foreach ($racks as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"
                          data-dc="<?= (int)$r['dc_id'] ?>"
                          <?= (($_POST['rack_id'] ?? $node['rack_id'] ?? '')==(string)$r['id'])?'selected':'' ?>>
                          <?= htmlspecialchars($r['dc_name'] . ' / ' . $r['name']) ?>
                          (<?= (int)$r['total_units'] ?>U)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-row">
                    <label for="f_ruu">Rack Unit (start)</label>
                    <input id="f_ruu" type="number" name="rack_unit_start" min="1" max="52"
                           value="<?= htmlspecialchars((string)($_POST['rack_unit_start'] ?? $node['rack_unit_start'] ?? '')) ?>"
                           placeholder="e.g. 12" />
                  </div>

                  <div class="form-row">
                    <label for="f_rus">Size (U)</label>
                    <input id="f_rus" type="number" name="rack_unit_size" min="1" max="16"
                           value="<?= htmlspecialchars((string)($_POST['rack_unit_size'] ?? $node['rack_unit_size'] ?? '1')) ?>"
                           placeholder="1" />
                    <span class="muted small">Height in rack units (1U, 2U, 4U…)</span>
                  </div>

                </div>
              </div>
            </section>

            <!-- ── Hardware ───────────────────────────────────────────── -->
            <section class="panel">
              <header class="panel__header"><h2>Hardware Details</h2></header>
              <div class="panel__body">
                <div class="node-edit-grid">

                  <div class="form-row">
                    <label for="f_make">Make (Manufacturer)</label>
                    <input id="f_make" type="text" name="make"
                           value="<?= htmlspecialchars((string)($_POST['make'] ?? $node['make'] ?? '')) ?>"
                           placeholder="e.g. Dell, HPE, Supermicro, Lenovo" />
                  </div>

                  <div class="form-row">
                    <label for="f_model">Model</label>
                    <input id="f_model" type="text" name="model"
                           value="<?= htmlspecialchars((string)($_POST['model'] ?? $node['model'] ?? '')) ?>"
                           placeholder="e.g. PowerEdge R750, ProLiant DL380 Gen10" />
                  </div>

                  <div class="form-row">
                    <label for="f_asset">Asset Tag</label>
                    <input id="f_asset" type="text" name="asset_tag"
                           value="<?= htmlspecialchars((string)($_POST['asset_tag'] ?? $node['asset_tag'] ?? '')) ?>"
                           placeholder="e.g. GH-2024-0042" />
                  </div>

                  <div class="form-row">
                    <label for="f_serial">Serial Number</label>
                    <input id="f_serial" type="text" name="serial_number"
                           value="<?= htmlspecialchars((string)($_POST['serial_number'] ?? $node['serial_number'] ?? '')) ?>"
                           placeholder="Manufacturer serial (scan barcode)" />
                  </div>

                  <div class="form-row">
                    <label for="f_cpu_model">CPU Model</label>
                    <input id="f_cpu_model" type="text" name="cpu_model"
                           value="<?= htmlspecialchars((string)($_POST['cpu_model'] ?? $node['cpu_model'] ?? '')) ?>"
                           placeholder="e.g. Intel Xeon Gold 6338 @ 2.00GHz" />
                  </div>

                  <div class="form-row">
                    <label for="f_cpu_cores">CPU Cores (total)</label>
                    <input id="f_cpu_cores" type="number" name="cpu_cores" min="1" max="2048"
                           value="<?= htmlspecialchars((string)($_POST['cpu_cores'] ?? $node['cpu_cores'] ?? '')) ?>"
                           placeholder="e.g. 64" />
                  </div>

                  <div class="form-row">
                    <label for="f_ram">RAM (GB)</label>
                    <input id="f_ram" type="number" name="ram_gb" min="1" max="65536"
                           value="<?= htmlspecialchars((string)($_POST['ram_gb'] ?? $node['ram_gb'] ?? '')) ?>"
                           placeholder="e.g. 256" />
                  </div>

                  <div class="form-row">
                    <label for="f_storage">Storage (GB total)</label>
                    <input id="f_storage" type="number" name="storage_gb" min="1"
                           value="<?= htmlspecialchars((string)($_POST['storage_gb'] ?? $node['storage_gb'] ?? '')) ?>"
                           placeholder="e.g. 3840" />
                  </div>

                </div>
              </div>
            </section>

            <!-- ── Operating System ───────────────────────────────────── -->
            <section class="panel">
              <header class="panel__header"><h2>Operating System</h2></header>
              <div class="panel__body">
                <div class="node-edit-grid">

                  <div class="form-row">
                    <label for="f_os_type">OS Type</label>
                    <select id="f_os_type" name="os_type">
                      <option value="">— Not set —</option>
                      <?php foreach (['Ubuntu','Debian','RHEL','Rocky Linux','AlmaLinux','CentOS','Windows Server','FreeBSD','Other'] as $os): ?>
                        <option value="<?= $os ?>" <?= (($_POST['os_type'] ?? $node['os_type'] ?? '')===$os)?'selected':'' ?>>
                          <?= $os ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-row">
                    <label for="f_os_ver">OS Version</label>
                    <input id="f_os_ver" type="text" name="os_version"
                           value="<?= htmlspecialchars((string)($_POST['os_version'] ?? $node['os_version'] ?? '')) ?>"
                           placeholder="e.g. 22.04 LTS, 2022, 9.3" />
                  </div>

                </div>
              </div>
            </section>

            <!-- ── Customer Assignment ────────────────────────────────── -->
            <section class="panel">
              <header class="panel__header"><h2>Customer Assignment</h2></header>
              <div class="panel__body">
                <div class="node-edit-grid" style="--cols:1;">
                  <div class="form-row">
                    <label for="f_cust">Assigned Customer</label>
                    <select id="f_cust" name="customer_id">
                      <option value="">— Unassigned / Spare —</option>
                      <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                          <?= (($_POST['customer_id'] ?? $node['customer_id'] ?? '')==(string)$c['id'])?'selected':'' ?>>
                          <?= htmlspecialchars($c['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <span class="muted small">Leave blank if this is a spare or internal server.</span>
                  </div>
                </div>
              </div>
            </section>

            <!-- ── Notes ─────────────────────────────────────────────── -->
            <section class="panel">
              <header class="panel__header"><h2>Notes</h2></header>
              <div class="panel__body" style="padding:16px 18px;">
                <textarea name="notes" rows="4"
                  style="width:100%; box-sizing:border-box; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,0.10); background: rgba(0,0,0,0.26); color: rgba(255,255,255,0.92); font:inherit; resize:vertical;"
                  placeholder="Any additional notes, configurations, or history…"><?= htmlspecialchars((string)($_POST['notes'] ?? $node['notes'] ?? '')) ?></textarea>
              </div>
            </section>

            <!-- ── Actions ────────────────────────────────────────────── -->
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <button class="btn btn--primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Add server' ?>
              </button>
              <?php if ($isEdit): ?>
                <a class="btn" href="/server.php?id=<?= $nodeId ?>">Cancel</a>
                <a class="btn" href="/provision.php?node=<?= $nodeId ?>" style="margin-left:auto;">
                  Open Provisioning Checklist →
                </a>
              <?php else: ?>
                <a class="btn" href="/hardware.php">Cancel</a>
              <?php endif; ?>
            </div>

          </form>

        </section>
        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>

<script>
// Rack filter by DC
const allRackOpts = Array.from(document.querySelectorAll('#f_rack option')).map(o => ({
  el: o, dc: o.dataset.dc || '', val: o.value
}));

function filterRacks(dcId) {
  allRackOpts.forEach(o => {
    if (!o.dc) { o.el.style.display = ''; return; } // "not racked" always shown
    o.el.style.display = (!dcId || o.dc === dcId) ? '' : 'none';
  });
  // Reset rack if current selection is hidden
  const rack = document.getElementById('f_rack');
  const selected = rack.options[rack.selectedIndex];
  if (selected && selected.dataset.dc && selected.dataset.dc !== dcId) {
    rack.value = '';
  }
}

// Init filter on page load
filterRacks(document.getElementById('f_dc')?.value || '');
</script>

<style>
.node-edit-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px 20px;
  padding: 16px 18px;
}
.form-row { display: grid; gap: 6px; }
.form-row label { font-size: 12px; color: var(--muted); }
.form-row input[type="text"],
.form-row input[type="number"],
.form-row select,
.form-row textarea {
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.10);
  background: rgba(0,0,0,0.26);
  color: var(--text);
  font: inherit;
  font-size: 13px;
  outline: none;
  width: 100%;
  box-sizing: border-box;
  transition: border-color .12s, box-shadow .12s;
}
.form-row input:focus,
.form-row select:focus,
.form-row textarea:focus {
  border-color: rgba(111,182,255,0.45);
  box-shadow: 0 0 0 3px rgba(111,182,255,0.15);
}
.form-row select option { background: #0b1020; }
</style>
</body>
</html>
