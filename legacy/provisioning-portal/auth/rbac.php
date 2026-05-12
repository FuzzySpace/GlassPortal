<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';

function require_role(array $allowedRoles): void {
  $u = current_user();
  if (!$u) {
    header('Location: /login.php');
    exit;
  }
  if (!in_array($u['role'], $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
  }
}