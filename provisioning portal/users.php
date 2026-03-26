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

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_verify($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add') {
            $email    = trim((string)($_POST['email'] ?? ''));
            $pw       = (string)($_POST['password'] ?? '');
            $newRole  = trim((string)($_POST['role'] ?? 'operator'));
            $allowed  = ['owner', 'admin', 'operator', 'security'];

            if ($email === '')                        $errors[] = 'Email is required.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
            if (strlen($pw) < 10)                     $errors[] = 'Password must be at least 10 characters.';
            if (!in_array($newRole, $allowed, true))  $errors[] = 'Invalid role.';

            // Only owner can create another owner
            if ($newRole === 'owner' && $role !== 'owner') {
                $errors[] = 'Only an owner can create another owner account.';
            }

            if (!$errors) {
                $exists = (int)$pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?")->execute([$email])
                    ? $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?")->execute([$email]) && false : 0;
                $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                $chk->execute([$email]);
                if ((int)$chk->fetchColumn() > 0) {
                    $errors[] = 'A user with that email already exists.';
                } else {
                    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, 1, NOW())")
                        ->execute([$email, $hash, $newRole]);
                    $success = "User \"$email\" created successfully.";
                }
            }

        } elseif ($action === 'toggle' && ctype_digit($_POST['id'] ?? '')) {
            $tid = (int)$_POST['id'];
            if ($tid === (int)($u['id'] ?? 0)) {
                $errors[] = 'You cannot deactivate your own account.';
            } else {
                $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$tid]);
                $success = 'User status updated.';
            }

        } elseif ($action === 'change_role' && ctype_digit($_POST['id'] ?? '')) {
            $tid      = (int)$_POST['id'];
            $newRole2 = trim((string)($_POST['role'] ?? ''));
            $allowed  = ['owner', 'admin', 'operator', 'security'];
            if (!in_array($newRole2, $allowed, true)) {
                $errors[] = 'Invalid role.';
            } elseif ($newRole2 === 'owner' && $role !== 'owner') {
                $errors[] = 'Only an owner can promote to owner.';
            } else {
                $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole2, $tid]);
                $success = 'User role updated.';
            }

        } elseif ($action === 'reset_password' && ctype_digit($_POST['id'] ?? '')) {
            $tid = (int)$_POST['id'];
            $pw2 = (string)($_POST['new_password'] ?? '');
            if (strlen($pw2) < 10) {
                $errors[] = 'New password must be at least 10 characters.';
            } else {
                $hash = password_hash($pw2, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $tid]);
                // Revoke all remember tokens
                $pdo->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")->execute([$tid]);
                $success = 'Password reset. All remember-me sessions revoked.';
            }
        }
    }
}

// ---- Load users ----
$users = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM auth_logins al WHERE al.user_id = u.id AND al.success = 0 AND al.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS recent_failures
    FROM users u
    ORDER BY u.role, u.email
")->fetchAll();

$roleCounts = [];
foreach ($users as $usr) {
    $roleCounts[$usr['role']] = ($roleCounts[$usr['role']] ?? 0) + 1;
}
$activeCount   = count(array_filter($users, fn($x) => $x['is_active']));
$inactiveCount = count($users) - $activeCount;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Users • NOC Portal</title>
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
              <h1>User Management</h1>
              <p class="muted">Portal accounts and role assignments.</p>
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
          <section class="kpi-grid" aria-label="User KPIs">
            <article class="kpi-card">
              <h2>Total Users</h2>
              <p><?= count($users) ?></p>
              <p class="muted small"><?= $activeCount ?> active · <?= $inactiveCount ?> inactive</p>
            </article>
            <?php foreach ($roleCounts as $r => $cnt): ?>
            <article class="kpi-card">
              <h2><?= ucfirst(htmlspecialchars($r)) ?>s</h2>
              <p><?= $cnt ?></p>
              <p class="muted small"><?= htmlspecialchars($r) ?> role</p>
            </article>
            <?php endforeach; ?>
          </section>

          <!-- Add user -->
          <section class="panel">
            <header class="panel__header">
              <h2>Add User</h2>
            </header>
            <div class="panel__body">
              <form method="post" action="/users.php" class="node-edit-grid">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>" />
                <input type="hidden" name="action" value="add" />

                <div class="form-row">
                  <label>Email <span class="badge badge--danger">required</span></label>
                  <input type="email" name="email" placeholder="user@example.com" required />
                </div>

                <div class="form-row">
                  <label>Password <span class="badge badge--danger">required</span></label>
                  <input type="password" name="password" minlength="10" placeholder="Min 10 characters" required />
                </div>

                <div class="form-row">
                  <label>Role</label>
                  <select name="role">
                    <option value="operator">Operator</option>
                    <option value="security">Security</option>
                    <option value="admin">Admin</option>
                    <?php if ($role === 'owner'): ?>
                      <option value="owner">Owner</option>
                    <?php endif; ?>
                  </select>
                </div>

                <div class="form-row" style="display:flex; align-items:flex-end;">
                  <button class="btn btn--primary" type="submit">Create user</button>
                </div>
              </form>
            </div>
          </section>

          <!-- User table -->
          <section class="panel">
            <header class="panel__header">
              <h2>All Users</h2>
              <span class="muted small"><?= count($users) ?> users</span>
            </header>
            <div class="panel__body">
              <table class="table">
                <thead>
                  <tr>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Failed (1 h)</th>
                    <th>Created</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $usr): ?>
                  <tr class="<?= !$usr['is_active'] ? 'row-inactive' : '' ?>">
                    <td><?= htmlspecialchars($usr['email']) ?></td>
                    <td><span class="badge badge--muted"><?= htmlspecialchars($usr['role']) ?></span></td>
                    <td>
                      <span class="<?= $usr['is_active'] ? 'badge badge--success' : 'badge badge--muted' ?>">
                        <?= $usr['is_active'] ? 'active' : 'inactive' ?>
                      </span>
                    </td>
                    <td class="muted small"><?= $usr['last_login_at'] ? htmlspecialchars($usr['last_login_at']) : '—' ?></td>
                    <td class="muted small">
                      <?php if ((int)$usr['recent_failures'] > 0): ?>
                        <span class="badge badge--danger"><?= (int)$usr['recent_failures'] ?></span>
                      <?php else: ?>
                        0
                      <?php endif; ?>
                    </td>
                    <td class="muted small"><?= htmlspecialchars($usr['created_at']) ?></td>
                    <td style="white-space:nowrap;">
                      <?php if ((int)$usr['id'] !== (int)($u['id'] ?? 0)): ?>
                        <!-- Toggle active -->
                        <form method="post" action="/users.php" style="display:inline;">
                          <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                          <input type="hidden" name="action" value="toggle" />
                          <input type="hidden" name="id"     value="<?= (int)$usr['id'] ?>" />
                          <button class="btn" style="padding:4px 10px;font-size:12px;" type="submit">
                            <?= $usr['is_active'] ? 'Disable' : 'Enable' ?>
                          </button>
                        </form>
                        <!-- Change role -->
                        <form method="post" action="/users.php" style="display:inline;">
                          <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                          <input type="hidden" name="action" value="change_role" />
                          <input type="hidden" name="id"     value="<?= (int)$usr['id'] ?>" />
                          <select name="role" style="font-size:12px; padding:3px 6px;">
                            <option value="operator" <?= $usr['role']==='operator'?'selected':'' ?>>operator</option>
                            <option value="security" <?= $usr['role']==='security'?'selected':'' ?>>security</option>
                            <option value="admin"    <?= $usr['role']==='admin'   ?'selected':'' ?>>admin</option>
                            <?php if ($role === 'owner'): ?>
                              <option value="owner" <?= $usr['role']==='owner'?'selected':'' ?>>owner</option>
                            <?php endif; ?>
                          </select>
                          <button class="btn btn--primary" style="padding:4px 10px;font-size:12px;" type="submit">Set role</button>
                        </form>
                      <?php else: ?>
                        <span class="muted small">You</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Reset password -->
          <section class="panel">
            <header class="panel__header"><h2>Reset a User's Password</h2></header>
            <div class="panel__body">
              <form method="post" action="/users.php" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars(csrf_token()) ?>" />
                <input type="hidden" name="action" value="reset_password" />
                <div class="form-row" style="min-width:200px;">
                  <label>User</label>
                  <select name="id">
                    <?php foreach ($users as $usr): ?>
                      <option value="<?= (int)$usr['id'] ?>"><?= htmlspecialchars($usr['email']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-row" style="min-width:250px;">
                  <label>New password</label>
                  <input type="password" name="new_password" minlength="10" placeholder="Min 10 characters" required />
                </div>
                <div class="form-row" style="display:flex; align-items:flex-end;">
                  <button class="btn btn--primary" type="submit">Reset password</button>
                </div>
              </form>
            </div>
          </section>

        </section>
        <?php require __DIR__ . '/components/footer.php'; ?>
      </main>
    </div>
  </div>
<style>
.row-inactive td { opacity: 0.5; }
</style>
</body>
</html>
