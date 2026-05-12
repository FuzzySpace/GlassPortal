<?php
declare(strict_types=1);

session_start([
  'cookie_httponly' => true,
  'cookie_samesite' => 'Lax',
  // 'cookie_secure' => true, // enable when HTTPS
  'use_strict_mode' => true,
]);

require_once __DIR__ . '/../database/connection.php';

// ---- CONFIG ----
const AUTH_SESSION_KEY = 'auth_user';
const CSRF_KEY = 'csrf';
const REMEMBER_COOKIE = 'gh_remember';
const REMEMBER_TTL_DAYS = 14;

// Rate limit / lockout knobs
const RL_WINDOW_SECONDS = 300;      // 5 minutes
const RL_MAX_ATTEMPTS_IP = 25;      // per IP per window
const RL_MAX_ATTEMPTS_EMAIL = 8;    // per email per window
const LOCKOUT_THRESHOLD = 8;        // consecutive failures per email within window
const LOCKOUT_SECONDS = 900;        // 15 minutes

function client_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function user_agent(): string {
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  return mb_substr($ua, 0, 255);
}

// ---- CSRF ----
function csrf_token(): string {
  if (empty($_SESSION[CSRF_KEY])) {
    $_SESSION[CSRF_KEY] = bin2hex(random_bytes(32));
  }
  return $_SESSION[CSRF_KEY];
}

function csrf_verify(string $token): bool {
  return isset($_SESSION[CSRF_KEY]) && hash_equals($_SESSION[CSRF_KEY], $token);
}

// ---- RATE LIMIT (session-based; good enough for single-operator dev) ----
// For production, move to Redis to survive multi-worker scaling.
function rate_limit(string $bucket, int $maxAttempts, int $windowSeconds): void {
  $now = time();
  $_SESSION['rl'] ??= [];

  $entry = $_SESSION['rl'][$bucket] ?? ['count' => 0, 'reset' => $now + $windowSeconds];

  if ($now > $entry['reset']) {
    $entry = ['count' => 0, 'reset' => $now + $windowSeconds];
  }

  $entry['count']++;
  $_SESSION['rl'][$bucket] = $entry;

  if ($entry['count'] > $maxAttempts) {
    http_response_code(429);
    exit('Too many attempts. Try again later.');
  }
}

function is_locked_out(PDO $pdo, string $email): bool {
  // Lockout if too many failures for this email in last LOCKOUT_SECONDS
  $stmt = $pdo->prepare(
    "SELECT COUNT(*) 
     FROM auth_logins 
     WHERE email_attempted = ? 
       AND success = 0 
       AND created_at >= (NOW() - INTERVAL ? SECOND)"
  );
  $stmt->execute([$email, LOCKOUT_SECONDS]);
  $fails = (int)$stmt->fetchColumn();
  return $fails >= LOCKOUT_THRESHOLD;
}

function audit_login(PDO $pdo, ?int $userId, string $email, bool $success, ?string $reason = null): void {
  $stmt = $pdo->prepare(
    "INSERT INTO auth_logins (user_id, email_attempted, ip, user_agent, success, fail_reason)
     VALUES (?, ?, ?, ?, ?, ?)"
  );
  $stmt->execute([
    $userId,
    $email,
    client_ip(),
    user_agent(),
    $success ? 1 : 0,
    $reason,
  ]);
}
