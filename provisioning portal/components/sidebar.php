<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guard.php';
$u = current_user();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$role = $u['role'] ?? 'operator';

function nav_active(string $href, string $path): string {
  return $href === $path ? ' is-active' : '';
}
function nav_active_prefix(string $prefix, string $path): string {
  return str_starts_with($path, $prefix) ? ' is-active' : '';
}
function can(array $roles, string $role): bool {
  return in_array($role, $roles, true);
}
?>
<nav class="sidebar" aria-label="Primary navigation">
  <ul class="nav-list">

    <!-- ── Operations ──────────────────── -->
    <li class="nav-section">Operations</li>
    <li>
      <a class="nav-link<?= nav_active('/dashboard.php', $path) ?>" href="/dashboard.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2" y="2" width="7" height="7" rx="1.5" fill="currentColor" opacity=".9"/><rect x="11" y="2" width="7" height="7" rx="1.5" fill="currentColor" opacity=".6"/><rect x="2" y="11" width="7" height="7" rx="1.5" fill="currentColor" opacity=".6"/><rect x="11" y="11" width="7" height="7" rx="1.5" fill="currentColor" opacity=".4"/></svg>
        Overview
      </a>
    </li>
    <li>
      <a class="nav-link<?= nav_active('/nodes.php', $path) ?>" href="/nodes.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="3" fill="currentColor"/><circle cx="10" cy="3"  r="1.5" fill="currentColor" opacity=".6"/><circle cx="10" cy="17" r="1.5" fill="currentColor" opacity=".6"/><circle cx="3"  cy="10" r="1.5" fill="currentColor" opacity=".6"/><circle cx="17" cy="10" r="1.5" fill="currentColor" opacity=".6"/></svg>
        Nodes
      </a>
    </li>

    <!-- ── Infrastructure ──────────────── -->
    <li class="nav-section">Infrastructure</li>
    <li>
      <a class="nav-link<?= nav_active_prefix('/hardware', $path) ?>" href="/hardware.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2" y="6" width="16" height="3" rx="1" fill="currentColor" opacity=".9"/><rect x="2" y="11" width="16" height="3" rx="1" fill="currentColor" opacity=".6"/><rect x="2" y="16" width="16" height="2" rx="1" fill="currentColor" opacity=".4"/><circle cx="5" cy="7.5" r="1" fill="var(--ok)"/><circle cx="5" cy="12.5" r="1" fill="var(--warn)"/></svg>
        Hardware
      </a>
    </li>
    <li>
      <a class="nav-link<?= nav_active_prefix('/rack', $path) ?>" href="/rack.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="5" y="2" width="10" height="16" rx="1.5" stroke="currentColor" stroke-width="1.5" opacity=".9"/><rect x="7" y="5" width="6" height="2" rx=".5" fill="currentColor" opacity=".7"/><rect x="7" y="9" width="6" height="2" rx=".5" fill="currentColor" opacity=".5"/><rect x="7" y="13" width="6" height="2" rx=".5" fill="currentColor" opacity=".3"/></svg>
        Rack View
      </a>
    </li>
    <li>
      <a class="nav-link<?= nav_active_prefix('/datacenters', $path) ?>" href="/datacenters.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 16V8l7-5 7 5v8H3z" stroke="currentColor" stroke-width="1.5" opacity=".9"/><rect x="8" y="11" width="4" height="5" rx=".5" fill="currentColor" opacity=".6"/></svg>
        Data Centers
      </a>
    </li>

    <!-- ── Customers ───────────────────── -->
    <li class="nav-section">Customers</li>
    <li>
      <a class="nav-link<?= nav_active_prefix('/customers', $path) ?>" href="/customers.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8" cy="7" r="3" fill="currentColor" opacity=".9"/><path d="M2 17c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="currentColor" stroke-width="1.5" opacity=".7"/><circle cx="15" cy="7" r="2.5" fill="currentColor" opacity=".5"/><path d="M15 12c1.7.5 3 2.2 3 4" stroke="currentColor" stroke-width="1.5" opacity=".4"/></svg>
        Customers
      </a>
    </li>

    <!-- ── Automation ──────────────────── -->
    <li class="nav-section">Automation</li>
    <li>
      <a class="nav-link<?= nav_active('/automations.php', $path) ?>" href="/automations.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3v4M10 13v4M3 10h4m6 0h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity=".7"/><circle cx="10" cy="10" r="2.5" fill="currentColor"/></svg>
        Automations
      </a>
    </li>
    <li>
      <a class="nav-link<?= nav_active('/runs.php', $path) ?>" href="/runs.php">
        <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 3l10 7-10 7V3z" fill="currentColor" opacity=".9"/></svg>
        Runs
      </a>
    </li>

    <!-- ── Security (role-gated) ────────── -->
    <?php if (can(['owner','admin','security'], $role)): ?>
      <li class="nav-section">Security</li>
      <li>
        <a class="nav-link<?= nav_active('/audit.php', $path) ?>" href="/audit.php">
          <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2l6 3v5c0 3.5-2.5 6.8-6 8-3.5-1.2-6-4.5-6-8V5l6-3z" stroke="currentColor" stroke-width="1.5" opacity=".8"/></svg>
          Audit
        </a>
      </li>
      <li>
        <a class="nav-link<?= nav_active('/access.php', $path) ?>" href="/access.php">
          <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M4 18c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="currentColor" stroke-width="1.5"/></svg>
          Access
        </a>
      </li>
    <?php endif; ?>

    <!-- ── Admin (role-gated) ───────────── -->
    <?php if (can(['owner','admin'], $role)): ?>
      <li class="nav-section">Admin</li>
      <li>
        <a class="nav-link<?= nav_active('/users.php', $path) ?>" href="/users.php">
          <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 18c0-3.9 3.1-7 7-7s7 3.1 7 7" stroke="currentColor" stroke-width="1.5"/></svg>
          Users
        </a>
      </li>
      <li>
        <a class="nav-link<?= nav_active('/settings.php', $path) ?>" href="/settings.php">
          <svg class="nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 2v2m0 12v2M2 10h2m12 0h2m-2.9-5.1-1.4 1.4M8.3 13.7l-1.4 1.4M17.1 15.1l-1.4-1.4M8.3 6.3 6.9 4.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          Settings
        </a>
      </li>
    <?php endif; ?>

  </ul>
</nav>
