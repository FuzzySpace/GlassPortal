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
  <title>Sign in • Provisioning Portal</title>
  <link rel="stylesheet" href="assets/css/styles.css" />
</head>
<body>
  <main class="auth-page">
    <section class="auth-card">
      <h1>Sign in</h1>

      <?php if ($err): ?>
        <div class="form-alert" role="alert"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <form method="post" action="/login_handler.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

<div class="form-row">
  <label>Email</label>
  <input name="email" type="email" autocomplete="username" required>
</div>

<div class="form-row">
  <label>Password</label>
  <input name="password" type="password" autocomplete="current-password" required>
</div>

        <button type="submit">Sign in</button>
      </form>
    </section>
  </main>
</body>
</html>