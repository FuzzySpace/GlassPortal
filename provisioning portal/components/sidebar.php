<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guard.php';
$u = current_user();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$role = $u['role'] ?? 'operator';

function nav_active(string $href, string $path): string {
  return $href === $path ? ' is-active' : '';
}
function can(array $roles, string $role): bool {
  return in_array($role, $roles, true);
}
?>
<nav class="sidebar" aria-label="Primary navigation">
  <ul class="nav-list">

    <li class="nav-section">Operations</li>
    <li><a class="nav-link<?= nav_active('/dashboard.php', $path) ?>" href="/dashboard.php">Overview</a></li>
    <li><a class="nav-link<?= nav_active('/nodes.php', $path) ?>" href="/nodes.php">Nodes</a></li>

    <li class="nav-section">Automation</li>
    <li><a class="nav-link<?= nav_active('/automations.php', $path) ?>" href="/automations.php">Automations</a></li>
    <li><a class="nav-link<?= nav_active('/runs.php', $path) ?>" href="/runs.php">Runs</a></li>

    <?php if (can(['owner','admin','security'], $role)): ?>
      <li class="nav-section">Security</li>
      <li><a class="nav-link<?= nav_active('/audit.php', $path) ?>" href="/audit.php">Audit</a></li>
      <li><a class="nav-link<?= nav_active('/access.php', $path) ?>" href="/access.php">Access</a></li>
    <?php endif; ?>

    <?php if (can(['owner','admin'], $role)): ?>
      <li class="nav-section">Admin</li>
      <li><a class="nav-link<?= nav_active('/settings.php', $path) ?>" href="/settings.php">Settings</a></li>
      <li><a class="nav-link<?= nav_active('/users.php', $path) ?>" href="/users.php">Users</a></li>
    <?php endif; ?>

  </ul>
</nav>