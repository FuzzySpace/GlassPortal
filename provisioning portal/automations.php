<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/bootstrap.php'; // for csrf_token()

$u = current_user();
$role = $u['role'] ?? 'operator';

// Permissions
$canCustomScript = in_array($role, ['owner','admin'], true);  // tighten now; loosen later if desired

// Data for dropdowns
$sites = $pdo->query("SELECT DISTINCT site FROM nodes ORDER BY site ASC")->fetchAll();
$providers = $pdo->query("SELECT DISTINCT provider FROM nodes WHERE provider IS NOT NULL AND provider<>'' ORDER BY provider ASC")->fetchAll();

$nodes = $pdo->query(
  "SELECT id, name, site, provider, status
   FROM nodes
   ORDER BY site ASC, name ASC"
)->fetchAll();

$scripts = $pdo->query(
  "SELECT id, name, description, script_type, command
   FROM ansible_scripts
   WHERE is_active = 1
   ORDER BY name ASC"
)->fetchAll();

// KPIs (optional but makes page feel legit)
$enabledAutomations = (int)$pdo->query("SELECT COUNT(*) FROM automations WHERE enabled=1")->fetchColumn();
$totalAutomations   = (int)$pdo->query("SELECT COUNT(*) FROM automations")->fetchColumn();
$queuedRuns         = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE status='queued'")->fetchColumn();
$runningRuns        = (int)$pdo->query("SELECT COUNT(*) FROM automation_runs WHERE status='running'")->fetchColumn();

// Existing automations list
$automationList = $pdo->query(
  "SELECT id, name, description, enabled, trigger_type, schedule_cron, created_at
   FROM automations
   ORDER BY created_at DESC
   LIMIT 50"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Automations • Provisioning Portal</title>
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
            <h1>Automations</h1>
            <p class="muted">Run playbooks across single or multiple nodes. Group targeting supported.</p>
          </div>
          <div class="page-header__actions">
            <a class="btn" href="/runs.php">View runs</a>
          </div>
        </header>

        <section class="kpi-grid" aria-label="Automation KPIs">
          <article class="kpi-card">
            <h2>Automations</h2>
            <p><?= $totalAutomations ?></p>
            <p class="muted small">Enabled <?= $enabledAutomations ?></p>
          </article>
          <article class="kpi-card">
            <h2>Queued</h2>
            <p><?= $queuedRuns ?></p>
            <p class="muted small">Waiting execution</p>
          </article>
          <article class="kpi-card">
            <h2>Running</h2>
            <p><?= $runningRuns ?></p>
            <p class="muted small">In-flight</p>
          </article>
          <article class="kpi-card">
            <h2>Mode</h2>
            <p>Internal</p>
            <p class="muted small">Standalone control plane</p>
          </article>
        </section>

        <!-- RUN FORM -->
        <section class="panel">
          <header class="panel__header">
            <h2>Run automation</h2>
            <span class="muted small">Select targets + choose script + submit run</span>
          </header>

          <div class="panel__body">
            <form method="post" action="/automation_run_handler.php" class="auth-form" style="padding:0; gap:16px;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />

              <!-- Target Mode -->
              <div class="form-row">
                <label>Target mode</label>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                  <label style="display:flex; gap:10px; align-items:center;">
                    <input type="radio" name="target_mode" value="single" checked />
                    Single server
                  </label>
                  <label style="display:flex; gap:10px; align-items:center;">
                    <input type="radio" name="target_mode" value="multi" />
                    Multiple servers
                  </label>
                </div>
                <p class="muted small">Single = pick one node. Multi = select by groups and/or nodes.</p>
              </div>

              <!-- Single Node -->
              <div class="form-row" id="singleNodeBlock">
                <label>Single node</label>
                <select name="single_node_id">
                  <option value="">Select a node…</option>
                  <?php foreach ($nodes as $n): ?>
                    <option value="<?= (int)$n['id'] ?>">
                      <?= htmlspecialchars($n['site']) ?> • <?= htmlspecialchars($n['name']) ?> (<?= htmlspecialchars($n['status']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Multi Targets -->
              <div class="form-row" id="multiTargetsBlock" style="display:none;">
                <label>Multi-target selection</label>

                <div class="panel" style="margin:0;">
                  <div class="panel__body">

                    <!-- Group selection -->
                    <div class="grid-2">
                      <div class="form-row">
                        <label>By site (group)</label>
                        <div style="display:grid; gap:8px; max-height:180px; overflow:auto; padding:8px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                          <?php foreach ($sites as $s): $site = (string)$s['site']; ?>
                            <label style="display:flex; gap:10px; align-items:center;">
                              <input type="checkbox" name="site_groups[]" value="<?= htmlspecialchars($site) ?>" class="site-group" />
                              <?= htmlspecialchars($site) ?>
                              <span class="muted small" style="margin-left:auto;">group</span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>

                      <div class="form-row">
                        <label>By provider (group)</label>
                        <div style="display:grid; gap:8px; max-height:180px; overflow:auto; padding:8px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                          <?php foreach ($providers as $p): $prov = (string)$p['provider']; ?>
                            <label style="display:flex; gap:10px; align-items:center;">
                              <input type="checkbox" name="provider_groups[]" value="<?= htmlspecialchars($prov) ?>" class="provider-group" />
                              <?= htmlspecialchars($prov) ?>
                              <span class="muted small" style="margin-left:auto;">group</span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>

                    <div style="height:12px;"></div>

                    <!-- Node selection -->
                    <div class="form-row">
                      <label>And/or individual nodes</label>

                      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <button class="btn" type="button" id="selectAllNodesBtn">Select all</button>
                        <button class="btn" type="button" id="clearAllNodesBtn">Clear</button>
                        <span class="muted small" id="selectedCount">0 selected</span>
                      </div>

                      <div style="display:grid; gap:8px; max-height:260px; overflow:auto; padding:10px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                        <?php foreach ($nodes as $n): ?>
                          <?php
                            $id = (int)$n['id'];
                            $site = (string)$n['site'];
                            $prov = (string)($n['provider'] ?? '');
                            $status = (string)($n['status'] ?? 'unknown');
                          ?>
                          <label style="display:flex; gap:10px; align-items:center;"
                                 data-site="<?= htmlspecialchars($site) ?>"
                                 data-provider="<?= htmlspecialchars($prov) ?>">
                            <input type="checkbox" name="node_ids[]" value="<?= $id ?>" class="node-check" />
                            <span><?= htmlspecialchars($site) ?> • <?= htmlspecialchars($n['name']) ?></span>
                            <span class="muted small" style="margin-left:auto;"><?= htmlspecialchars($status) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>

                      <p class="muted small">Tip: group checkboxes don’t auto-select nodes yet—use them for “target rule” and keep nodes for explicit overrides. (We can auto-sync later.)</p>
                    </div>

                  </div>
                </div>
              </div>

              <!-- Script selection -->
              <div class="form-row">
                <label>Ansible script</label>

                <select name="script_id" id="scriptSelect">
                  <option value="">Select a saved script…</option>
                  <?php foreach ($scripts as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"
                      data-command="<?= htmlspecialchars($s['command']) ?>"
                      data-type="<?= htmlspecialchars($s['script_type']) ?>">
                      <?= htmlspecialchars($s['name']) ?> — <?= htmlspecialchars((string)($s['description'] ?? '')) ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ($canCustomScript): ?>
                    <option value="__custom__">Custom script…</option>
                  <?php endif; ?>
                </select>

                <p class="muted small">
                  Saved scripts are recommended. Custom script is restricted to owner/admin.
                </p>
              </div>

              <!-- Custom script block -->
              <div class="form-row" id="customScriptBlock" style="display:none;">
                <label>Custom script (saved later optional)</label>
                <textarea name="custom_command" rows="6"
                  placeholder="Example: ansible-playbook playbooks/patch.yml -i inventories/prod.ini"
                  style="width:100%; box-sizing:border-box; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,0.10); background: rgba(0,0,0,0.26); color: rgba(255,255,255,0.92);"></textarea>

                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                  <label style="display:flex; gap:10px; align-items:center;">
                    <input type="checkbox" name="save_custom" value="1" />
                    Save this script for later reuse
                  </label>
                </div>

                <div class="grid-2" style="margin-top:8px;">
                  <div class="form-row">
                    <label>Saved name</label>
                    <input type="text" name="custom_name" placeholder="e.g. Patch Kernel (Fleet)" />
                  </div>
                  <div class="form-row">
                    <label>Description</label>
                    <input type="text" name="custom_desc" placeholder="Short operator description" />
                  </div>
                </div>
              </div>

              <!-- Run metadata -->
              <div class="grid-2">
                <div class="form-row">
                  <label>Run label (optional)</label>
                  <input type="text" name="run_label" placeholder="e.g. VH fleet patch wave 1" />
                </div>
                <div class="form-row">
                  <label>Initiation method</label>
                  <select name="initiated_via">
                    <option value="manual" selected>manual</option>
                    <option value="web">web</option>
                    <option value="api">api</option>
                  </select>
                </div>
              </div>

              <!-- Submit -->
              <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button class="btn btn--primary" type="submit">Create run</button>
                <a class="btn" href="/runs.php">View runs</a>
              </div>

            </form>
          </div>
        </section>

        <!-- EXISTING AUTOMATIONS -->
        <section class="panel">
          <header class="panel__header">
            <h2>Automation catalog</h2>
            <span class="muted small">Existing definitions (read-only for now)</span>
          </header>
          <div class="panel__body">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Enabled</th>
                  <th>Trigger</th>
                  <th>Schedule</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$automationList): ?>
                  <tr><td colspan="5" class="muted">No automations yet.</td></tr>
                <?php else: foreach ($automationList as $a): ?>
                  <tr>
                    <td><?= htmlspecialchars($a['name']) ?></td>
                    <td class="muted small"><?= (int)$a['enabled'] === 1 ? 'yes' : 'no' ?></td>
                    <td class="muted small"><?= htmlspecialchars((string)$a['trigger_type']) ?></td>
                    <td class="muted small"><?= htmlspecialchars((string)($a['schedule_cron'] ?? '—')) ?></td>
                    <td class="muted small"><?= htmlspecialchars((string)$a['created_at']) ?></td>
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

<script>
// Toggle single/multi UI
const singleBlock = document.getElementById('singleNodeBlock');
const multiBlock  = document.getElementById('multiTargetsBlock');

document.querySelectorAll('input[name="target_mode"]').forEach(r => {
  r.addEventListener('change', () => {
    const mode = document.querySelector('input[name="target_mode"]:checked')?.value;
    if (mode === 'multi') {
      singleBlock.style.display = 'none';
      multiBlock.style.display  = 'block';
    } else {
      singleBlock.style.display = 'block';
      multiBlock.style.display  = 'none';
    }
  });
});

// Custom script block
const scriptSelect = document.getElementById('scriptSelect');
const customBlock  = document.getElementById('customScriptBlock');

scriptSelect?.addEventListener('change', () => {
  const val = scriptSelect.value;
  if (val === '__custom__') customBlock.style.display = 'block';
  else customBlock.style.display = 'none';
});

// Node selection helpers
const nodeChecks = () => Array.from(document.querySelectorAll('.node-check'));
const selectedCountEl = document.getElementById('selectedCount');

function updateSelectedCount(){
  const count = nodeChecks().filter(c => c.checked).length;
  if (selectedCountEl) selectedCountEl.textContent = `${count} selected`;
}

document.getElementById('selectAllNodesBtn')?.addEventListener('click', () => {
  nodeChecks().forEach(c => c.checked = true);
  updateSelectedCount();
});
document.getElementById('clearAllNodesBtn')?.addEventListener('click', () => {
  nodeChecks().forEach(c => c.checked = false);
  updateSelectedCount();
});
nodeChecks().forEach(c => c.addEventListener('change', updateSelectedCount));
updateSelectedCount();
</script>

</body>
</html>