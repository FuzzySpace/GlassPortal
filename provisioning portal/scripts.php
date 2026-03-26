<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/auth/bootstrap.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

$canEdit = in_array($role, ['owner', 'admin'], true);

// ---- Handle POST actions ----
$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // Toggle active
        if ($action === 'toggle' && ctype_digit($_POST['id'] ?? '')) {
            $tid = (int)$_POST['id'];
            $pdo->prepare("UPDATE ansible_scripts SET is_active = NOT is_active WHERE id=?")->execute([$tid]);
            $success = 'Script status toggled.';

        // Add new script
        } elseif ($action === 'add') {
            $name    = trim((string)($_POST['name'] ?? ''));
            $desc    = trim((string)($_POST['description'] ?? ''));
            $type    = trim((string)($_POST['script_type'] ?? 'playbook'));
            $cat     = trim((string)($_POST['category'] ?? ''));
            $command = trim((string)($_POST['command'] ?? ''));
            $tags    = trim((string)($_POST['tags'] ?? ''));
            $timeout = (int)($_POST['timeout_seconds'] ?? 3600);
            $sudo    = (int)($_POST['requires_sudo'] ?? 0);
            $userId  = (int)($u['id'] ?? 0);

            if ($name === '')    $errors[] = 'Script name required.';
            if ($command === '') $errors[] = 'Command / playbook path required.';
            $allowedTypes = ['playbook','adhoc','role'];
            if (!in_array($type, $allowedTypes, true)) $errors[] = 'Invalid script type.';

            if (!$errors) {
                $stmt = $pdo->prepare("
                    INSERT INTO ansible_scripts
                      (name, description, script_type, category, command, tags, timeout_seconds, requires_sudo, is_active, created_by_user_id)
                    VALUES (?,?,?,?,?,?,?,?,1,?)
                ");
                $stmt->execute([$name, $desc ?: null, $type, $cat ?: null, $command, $tags ?: null, $timeout, $sudo ? 1 : 0, $userId ?: null]);
                $success = "Script \"$name\" added successfully.";
            }

        // Delete
        } elseif ($action === 'delete' && ctype_digit($_POST['id'] ?? '')) {
            $tid = (int)$_POST['id'];
            // Safety: check not in use by recent runs
            $inUse = (int)$pdo->prepare("SELECT COUNT(*) FROM automation_run_logs WHERE message LIKE ? LIMIT 1")->execute(['%script_id%']) ? 0 : 0;
            $pdo->prepare("DELETE FROM ansible_scripts WHERE id=?")->execute([$tid]);
            $success = 'Script deleted.';
        }
    }
}

// ---- Filter ----
$catFilter  = trim((string)($_GET['cat'] ?? 'all'));
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
$q          = trim((string)($_GET['q'] ?? ''));

$where  = ["1=1"];
$params = [];

if ($catFilter !== 'all' && $catFilter !== '') {
    $where[]  = "s.category = ?";
    $params[] = $catFilter;
}
if ($typeFilter !== 'all' && $typeFilter !== '') {
    $where[]  = "s.script_type = ?";
    $params[] = $typeFilter;
}
if ($q !== '') {
    $like = '%'.$q.'%';
    $where[]  = "(s.name LIKE ? OR s.description LIKE ? OR s.command LIKE ?)";
    array_push($params, $like, $like, $like);
}

$whereSql = implode(" AND ", $where);

$scripts = $pdo->prepare("
    SELECT s.*, u.email AS created_by_email,
           COUNT(DISTINCT r.id) AS run_count
    FROM ansible_scripts s
    LEFT JOIN users u ON u.id = s.created_by_user_id
    LEFT JOIN automation_runs r ON r.meta->>'$.script.script_id' = CAST(s.id AS CHAR)
    WHERE $whereSql
    GROUP BY s.id
    ORDER BY s.category, s.name
");
$scripts->execute($params);
$scripts = $scripts->fetchAll();

$categories = $pdo->query("SELECT DISTINCT category FROM ansible_scripts WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$totalActive = (int)$pdo->query("SELECT COUNT(*) FROM ansible_scripts WHERE is_active=1")->fetchColumn();
$totalAll    = (int)$pdo->query("SELECT COUNT(*) FROM ansible_scripts")->fetchColumn();

// Group by category for display
$grouped = [];
foreach ($scripts as $s) {
    $grouped[$s['category'] ?: 'uncategorised'][] = $s;
}
ksort($grouped);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Ansible Scripts • NOC Portal</title>
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
              <h1>Ansible Script Library</h1>
              <p class="muted">Saved playbooks and ad-hoc commands. Use these on the <a class="link" href="/automations.php">Automations</a> page to run against servers.</p>
            </div>
            <div class="page-header__actions">
              <a class="btn" href="/automations.php">Run scripts →</a>
            </div>
          </header>

          <?php foreach ($errors as $e): ?>
            <div class="form-alert"><?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
          <?php if ($success): ?>
            <div class="form-alert" style="border-color:rgba(52,211,153,0.35);background:rgba(52,211,153,0.10);">
              <?= htmlspecialchars($success) ?>
            </div>
          <?php endif; ?>

          <!-- KPIs -->
          <section class="kpi-grid" aria-label="Script KPIs">
            <article class="kpi-card">
              <h2>Total Scripts</h2>
              <p><?= $totalAll ?></p>
              <p class="muted small"><?= $totalActive ?> active</p>
            </article>
            <article class="kpi-card">
              <h2>Categories</h2>
              <p><?= count($categories) ?></p>
              <p class="muted small">Provisioning, CIS, patching…</p>
            </article>
          </section>

          <!-- ── Add Script Form (admin/owner only) ── -->
          <?php if ($canEdit): ?>
          <section class="panel">
            <header class="panel__header">
              <h2>Add Script</h2>
              <span class="muted small">Owner / Admin only</span>
            </header>
            <div class="panel__body">
              <form method="post" action="/scripts.php" class="scripts-add-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
                <input type="hidden" name="action" value="add" />

                <div class="node-edit-grid">
                  <div class="form-row">
                    <label>Script name <span class="badge badge--danger">required</span></label>
                    <input type="text" name="name" placeholder="e.g. CIS Level 1 Hardening" required />
                  </div>

                  <div class="form-row">
                    <label>Type</label>
                    <select name="script_type">
                      <option value="playbook">Playbook</option>
                      <option value="adhoc">Ad-hoc command</option>
                      <option value="role">Role</option>
                    </select>
                  </div>

                  <div class="form-row">
                    <label>Category</label>
                    <select name="category">
                      <option value="">— Uncategorised —</option>
                      <option value="provisioning">Provisioning</option>
                      <option value="hardening">Hardening</option>
                      <option value="patching">Patching</option>
                      <option value="monitoring">Monitoring</option>
                      <option value="backup">Backup</option>
                      <option value="custom">Custom</option>
                    </select>
                  </div>

                  <div class="form-row">
                    <label>Timeout (seconds)</label>
                    <input type="number" name="timeout_seconds" value="3600" min="60" max="86400" />
                  </div>
                </div>

                <div style="padding:0 18px 14px;">
                  <div class="form-row" style="margin-bottom:10px;">
                    <label>Command / Playbook path <span class="badge badge--danger">required</span></label>
                    <input type="text" name="command"
                           placeholder="e.g. ansible-playbook playbooks/cis-level1.yml  OR  playbooks/patch.yml"
                           style="font-family:monospace;" required />
                    <span class="muted small">Full ansible-playbook command, or just the playbook path (worker appends -i inventory automatically).</span>
                  </div>

                  <div class="form-row" style="margin-bottom:10px;">
                    <label>Description</label>
                    <input type="text" name="description"
                           placeholder="Brief description shown in the run form" />
                  </div>

                  <div class="form-row" style="margin-bottom:10px;">
                    <label>Ansible tags (optional)</label>
                    <input type="text" name="tags" placeholder="e.g. cis,level1,ssh" />
                  </div>

                  <label style="display:flex; gap:10px; align-items:center; font-size:13px; margin-bottom:14px;">
                    <input type="checkbox" name="requires_sudo" value="1" />
                    Requires sudo / become
                  </label>

                  <button class="btn btn--primary" type="submit">Add script</button>
                </div>
              </form>
            </div>
          </section>
          <?php endif; ?>

          <!-- ── Filter ── -->
          <section class="panel">
            <header class="panel__header">
              <h2>Script Library</h2>
              <span class="muted small"><?= count($scripts) ?> scripts shown</span>
            </header>
            <div class="panel__body">
              <form method="get" action="/scripts.php" class="filter-bar">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, command…" />
                <select name="cat">
                  <option value="all" <?= $catFilter==='all'?'selected':'' ?>>All categories</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $catFilter===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="type">
                  <option value="all" <?= $typeFilter==='all'?'selected':'' ?>>All types</option>
                  <option value="playbook" <?= $typeFilter==='playbook'?'selected':'' ?>>Playbook</option>
                  <option value="adhoc"    <?= $typeFilter==='adhoc'?'selected':''    ?>>Ad-hoc</option>
                  <option value="role"     <?= $typeFilter==='role'?'selected':''     ?>>Role</option>
                </select>
                <button class="btn" type="submit">Filter</button>
                <a class="btn" href="/scripts.php">Reset</a>
              </form>

              <!-- Grouped script table -->
              <?php if (!$scripts): ?>
                <p class="muted" style="padding:18px;">No scripts found. <?= $canEdit ? 'Add one above.' : 'Ask an admin to add scripts.' ?></p>
              <?php else: foreach ($grouped as $cat => $catScripts): ?>

                <div class="scripts-category-header">
                  <span class="badge badge--muted"><?= htmlspecialchars(ucfirst($cat)) ?></span>
                  <span class="muted small"><?= count($catScripts) ?> scripts</span>
                </div>

                <table class="table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Type</th>
                      <th>Command</th>
                      <th>Timeout</th>
                      <th>Sudo</th>
                      <th>Runs</th>
                      <th>Status</th>
                      <?php if ($canEdit): ?><th>Actions</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($catScripts as $s): ?>
                      <tr class="<?= !$s['is_active'] ? 'row-inactive' : '' ?>">
                        <td>
                          <strong><?= htmlspecialchars($s['name']) ?></strong>
                          <?php if ($s['description']): ?>
                            <br><span class="muted small"><?= htmlspecialchars($s['description']) ?></span>
                          <?php endif; ?>
                          <?php if ($s['tags']): ?>
                            <br><span class="muted small">Tags: <?= htmlspecialchars($s['tags']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td><span class="badge badge--muted"><?= htmlspecialchars($s['script_type']) ?></span></td>
                        <td class="muted small" style="font-family:monospace; word-break:break-all; max-width:300px;">
                          <?= htmlspecialchars($s['command']) ?>
                        </td>
                        <td class="muted small"><?= (int)$s['timeout_seconds'] ?>s</td>
                        <td class="muted small"><?= $s['requires_sudo'] ? '<span class="badge badge--warn">yes</span>' : 'no' ?></td>
                        <td><?= (int)$s['run_count'] ?></td>
                        <td>
                          <span class="<?= $s['is_active'] ? 'badge badge--success' : 'badge badge--muted' ?>">
                            <?= $s['is_active'] ? 'active' : 'inactive' ?>
                          </span>
                        </td>
                        <?php if ($canEdit): ?>
                          <td style="white-space:nowrap;">
                            <form method="post" action="/scripts.php" style="display:inline;">
                              <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
                              <input type="hidden" name="action" value="toggle" />
                              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>" />
                              <button class="btn" style="padding:4px 10px;font-size:12px;" type="submit">
                                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
                              </button>
                            </form>
                            <a class="btn btn--primary" style="padding:4px 10px;font-size:12px;"
                               href="/automations.php?script=<?= (int)$s['id'] ?>">
                              Run →
                            </a>
                          </td>
                        <?php else: ?>
                          <td>
                            <a class="btn btn--primary" style="padding:4px 10px;font-size:12px;"
                               href="/automations.php?script=<?= (int)$s['id'] ?>">
                              Run →
                            </a>
                          </td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>

              <?php endforeach; endif; ?>
            </div>
          </section>

        </section>
        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
<style>
.scripts-category-header {
  display:flex; align-items:center; gap:10px;
  padding:12px 18px 6px;
  border-top: 1px solid rgba(255,255,255,0.05);
}
.row-inactive td { opacity: 0.5; }
</style>
</body>
</html>
