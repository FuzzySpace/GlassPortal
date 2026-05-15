<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function remember_create(PDO $pdo, int $userId): void {
  $selector = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
  $validator = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  $validatorHash = hash('sha256', $validator);

  $expiresAt = (new DateTimeImmutable())->modify('+' . REMEMBER_TTL_DAYS . ' days');

  $stmt = $pdo->prepare(
    "INSERT INTO auth_remember_tokens
      (user_id, selector, validator_hash, expires_at, ip_created, user_agent_created)
     VALUES (?, ?, ?, ?, ?, ?)"
  );
  $stmt->execute([
    $userId,
    $selector,
    $validatorHash,
    $expiresAt->format('Y-m-d H:i:s'),
    client_ip(),
    user_agent(),
  ]);

  // Cookie contains selector:validator (selector is public; validator is secret)
  $cookieValue = $selector . ':' . $validator;

  setcookie(REMEMBER_COOKIE, $cookieValue, [
    'expires' => $expiresAt->getTimestamp(),
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    // 'secure' => true, // enable on HTTPS
  ]);
}

function remember_clear(PDO $pdo): void {
  if (!empty($_COOKIE[REMEMBER_COOKIE])) {
    // best-effort revoke
    [$selector] = array_pad(explode(':', $_COOKIE[REMEMBER_COOKIE], 2), 2, null);
    if ($selector) {
      $stmt = $pdo->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE selector = ?");
      $stmt->execute([$selector]);
    }
  }

  setcookie(REMEMBER_COOKIE, '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    // 'secure' => true,
  ]);
}

function remember_attempt(PDO $pdo): ?array {
  if (empty($_COOKIE[REMEMBER_COOKIE])) return null;

  [$selector, $validator] = array_pad(explode(':', $_COOKIE[REMEMBER_COOKIE], 2), 2, null);
  if (!$selector || !$validator) return null;

  $stmt = $pdo->prepare(
    "SELECT t.id, t.user_id, t.validator_hash, t.expires_at, t.revoked_at, u.email, u.role, u.is_active
     FROM auth_remember_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.selector = ?
     LIMIT 1"
  );
  $stmt->execute([$selector]);
  $row = $stmt->fetch();

  if (!$row) return null;
  if (!empty($row['revoked_at'])) return null;
  if ((int)$row['is_active'] !== 1) return null;

  $expiresAt = new DateTimeImmutable($row['expires_at']);
  if ($expiresAt < new DateTimeImmutable()) return null;

  $validatorHash = hash('sha256', $validator);
  if (!hash_equals($row['validator_hash'], $validatorHash)) {
    // token theft attempt: revoke this selector
    $upd = $pdo->prepare("UPDATE auth_remember_tokens SET revoked_at = NOW() WHERE id = ?");
    $upd->execute([(int)$row['id']]);
    return null;
  }

  // rotate token on each use (prevents replay)
  $pdo->prepare("UPDATE auth_remember_tokens SET last_used_at = NOW(), revoked_at = NOW() WHERE id = ?")
      ->execute([(int)$row['id']]);

  remember_create($pdo, (int)$row['user_id']);

  return [
    'id' => (int)$row['user_id'],
    'email' => $row['email'],
    'role' => $row['role'],
  ];
}
