<?php
declare(strict_types=1);

require_once __DIR__ . '/remember.php';

function current_user(): ?array {
  return $_SESSION[AUTH_SESSION_KEY] ?? null;
}

function require_auth(): void {
  global $pdo;

  if (!empty($_SESSION[AUTH_SESSION_KEY])) return;

  // Attempt remember-me
  $remembered = remember_attempt($pdo);
  if ($remembered) {
    session_regenerate_id(true);
    $_SESSION[AUTH_SESSION_KEY] = $remembered;
    return;
  }

  header('Location: /login.php');
  exit;
}