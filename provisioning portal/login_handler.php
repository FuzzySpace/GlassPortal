<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/remember.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

// Rate limits (IP + email buckets)
rate_limit('ip:' . client_ip(), RL_MAX_ATTEMPTS_IP, RL_WINDOW_SECONDS);

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$pass  = (string)($_POST['password'] ?? '');
$csrf  = (string)($_POST['csrf'] ?? '');

if (!csrf_verify($csrf)) {
  http_response_code(400);
  exit('Invalid CSRF token');
}

if ($email === '' || $pass === '') {
  header('Location: /login.php?err=' . urlencode('Missing credentials.'));
  exit;
}

rate_limit('email:' . $email, RL_MAX_ATTEMPTS_EMAIL, RL_WINDOW_SECONDS);

// DB-backed lockout
if (is_locked_out($pdo, $email)) {
  audit_login($pdo, null, $email, false, 'locked_out');
  header('Location: /login.php?err=' . urlencode('Account temporarily locked. Try again later.'));
  exit;
}

// Lookup user
$stmt = $pdo->prepare("SELECT id, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
  audit_login($pdo, null, $email, false, 'no_such_user');
  header('Location: /login.php?err=' . urlencode('Invalid email or password.'));
  exit;
}

$userId = (int)$user['id'];

if ((int)$user['is_active'] !== 1) {
  audit_login($pdo, $userId, $email, false, 'inactive');
  header('Location: /login.php?err=' . urlencode('Account disabled.'));
  exit;
}

if (!password_verify($pass, $user['password_hash'])) {
  audit_login($pdo, $userId, $email, false, 'bad_password');
  header('Location: /login.php?err=' . urlencode('Invalid email or password.'));
  exit;
}

// Success
audit_login($pdo, $userId, $email, true, null);

$pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$userId]);

session_regenerate_id(true);
$_SESSION[AUTH_SESSION_KEY] = [
  'id' => $userId,
  'email' => $user['email'],
  'role' => $user['role'],
];

if (!empty($_POST['remember'])) {
  remember_create($pdo, $userId);
}

header('Location: /dashboard.php');
exit;