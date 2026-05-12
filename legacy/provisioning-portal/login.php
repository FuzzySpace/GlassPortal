<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/bootstrap.php';

$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="en">


<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sign in • Glasshouse Portal</title>
  <link rel="stylesheet" href="assets/css/styles.css" />
</head>
<body>
  <main class="auth-page">
    <section class="auth-card">

      <!-- Glasshouse logo with orange glow -->
      <div class="auth-logo-wrap">
        <img class="auth-logo"
             src="assets/images/glasshouse-logo.svg"
             alt="Glasshouse"
             width="64" height="74" />
        <h1>Glasshouse Portal</h1>
        <p>NOC Provisioning Platform</p>
      </div>

      <?php if ($err): ?>
        <div class="form-alert" role="alert"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <form class="auth-form" method="post" action="/login_handler.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

        <div class="form-row">
          <label for="login-email">Email</label>
          <input id="login-email" name="email" type="email" autocomplete="username"
                 placeholder="you@glasshouse.hosting" required>
        </div>

        <div class="form-row">
          <label for="login-pass">Password</label>
          <input id="login-pass" name="password" type="password"
                 autocomplete="current-password" placeholder="••••••••••" required>
        </div>

        <label style="display:flex;align-items:center;gap:10px;font-size:13px;color:var(--muted);">
          <input type="checkbox" name="remember" value="1"> Remember me for 30 days
        </label>

        <button class="btn btn--primary btn--block" type="submit">Sign in →</button>
      </form>

    </section>
  </main>
</body>
</html>