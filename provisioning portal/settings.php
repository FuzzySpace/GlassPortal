<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/auth/guard.php';
require_once __DIR__ . '/auth/bootstrap.php';

$u    = current_user();
$role = $u['role'] ?? 'operator';

if (!in_array($role, ['owner', 'admin'], true)) {
    header('Location: /dashboard.php');
    exit;
}

$errors  = [];
$success = null;

// ---- Handle password change ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'change_own_password') {
            $current  = (string)($_POST['current_password'] ?? '');
            $new      = (string)($_POST['new_password'] ?? '');
            $confirm  = (string)($_POST['confirm_password'] ?? '');

            if ($new !== $confirm)        $errors[] = 'New passwords do not match.';
            if (strlen($new) < 10)        $errors[] = 'New password must be at least 10 characters.';

            if (!$errors) {
                $me = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $me->execute([(int)$u['id']]);
                $row = $me->fetch();
                if (!$row || !password_verify($current, $row['password_hash'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, (int)$u['id']]);
                    $pdo->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([(int)$u['id']]);
                    $success = 'Password changed. All remember-me sessions have been revoked.';
                }
            }
        }
    }
}

// ---- Portal stats ----
$stats = [
    'nodes'      => (int)$pdo->query("SELECT COUNT(*) FROM nodes")->fetchColumn(),
    'customers'  => (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
    'racks'      => (int)$pdo->query("SELECT COUNT(*) FROM racks")->fetchColumn(),
    'dcs'        => (int)$pdo->query("SELECT COUNT(*) FROM datacenters")->fetchColumn(),
    'scripts'    => (int)$pdo->query("SELECT COUNT(*) FROM ansible_scripts WHERE is_active=1")->fetchColumn(),
    'runs_total' => (int)$pdo->query("SELECT COUNT(*) FROM automation_runs")->fetchColumn(),
    'users'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Settings • NOC Portal</title>
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
              <h1>Portal Settings</h1>
              <p class="muted">Account, portal configuration, and system information.</p>
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

          <!-- Portal statistics -->
          <section class="panel">
            <header class="panel__header"><h2>Portal Overview</h2></header>
            <div class="panel__body">
              <dl class="detail-dl detail-dl--row">
                <dt>Active Servers</dt><dd><?= $stats['nodes'] ?></dd>
                <dt>Customers</dt><dd><?= $stats['customers'] ?></dd>
                <dt>Racks</dt><dd><?= $stats['racks'] ?></dd>
                <dt>Data Centers</dt><dd><?= $stats['dcs'] ?></dd>
                <dt>Active Scripts</dt><dd><?= $stats['scripts'] ?></dd>
                <dt>Total Runs</dt><dd><?= number_format($stats['runs_total']) ?></dd>
                <dt>Active Users</dt><dd><?= $stats['users'] ?></dd>
              </dl>
            </div>
          </section>

          <!-- Account settings -->
          <section class="panel">
            <header class="panel__header"><h2>My Account</h2></header>
            <div class="panel__body">
              <dl class="detail-dl" style="margin-bottom:24px;">
                <dt>Email</dt>
                <dd><?= htmlspecialchars($u['email'] ?? '—') ?></dd>
                <dt>Role</dt>
                <dd><span class="badge badge--muted"><?= htmlspecialchars($u['role'] ?? '—') ?></span></dd>
              </dl>

              <h3 style="font-size:14px; font-weight:600; margin-bottom:12px; color:rgba(255,255,255,0.8);">Change Password</h3>
              <form method="post" action="/settings.php" style="display:flex; flex-direction:column; gap:12px; max-width:400px;">
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                <input type="hidden" name="action" value="change_own_password" />
                <div class="form-row">
                  <label>Current password</label>
                  <input type="password" name="current_password" required />
                </div>
                <div class="form-row">
                  <label>New password <span class="muted small">(min 10 chars)</span></label>
                  <input type="password" name="new_password" minlength="10" required />
                </div>
                <div class="form-row">
                  <label>Confirm new password</label>
                  <input type="password" name="confirm_password" minlength="10" required />
                </div>
                <div>
                  <button class="btn btn--primary" type="submit">Update password</button>
                </div>
              </form>
            </div>
          </section>

          <!-- Quick links -->
          <section class="panel">
            <header class="panel__header"><h2>Administration</h2></header>
            <div class="panel__body">
              <div style="display:flex; flex-wrap:wrap; gap:10px; padding-bottom:4px;">
                <a class="btn" href="/users.php">Manage Users</a>
                <a class="btn" href="/audit.php">Audit Log</a>
                <a class="btn" href="/access.php">Access Log</a>
                <a class="btn" href="/scripts.php">Script Library</a>
                <a class="btn" href="/datacenters.php">Data Centers</a>
              </div>
            </div>
          </section>

          <!-- System info -->
          <section class="panel">
            <header class="panel__header"><h2>System Information</h2></header>
            <div class="panel__body">
              <dl class="detail-dl detail-dl--row">
                <dt>PHP Version</dt><dd><?= htmlspecialchars(PHP_VERSION) ?></dd>
                <dt>Server Time</dt><dd class="muted small"><?= date('Y-m-d H:i:s T') ?></dd>
                <dt>Portal</dt><dd>Glasshouse NOC Provisioning Portal</dd>
              </dl>
            </div>
          </section>

        </section>
        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
</body>
</html>
