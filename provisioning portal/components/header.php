<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/guard.php';
$u = current_user();
?>
<header class="topbar" role="banner">
  <div class="topbar__left">
    <a class="brand" href="/dashboard.php" aria-label="Glasshouse Portal Home">
      <img class="brand__logo"
           src="/assets/images/glasshouse-logo.svg"
           alt="Glasshouse"
           width="32" height="37"
           aria-hidden="true" />
      <span class="brand__name">Glasshouse Portal</span>
    </a>
    <span class="env-badge" data-env="internal">INTERNAL</span>
  </div>

  <div class="topbar__center">
    <form class="global-search" role="search" aria-label="Global search" action="/search.php" method="get">
      <label class="sr-only" for="globalSearch">Search</label>
      <input id="globalSearch" name="q" type="search" placeholder="Search nodes, runs, automations…" />
    </form>
  </div>

  <div class="topbar__right">
    <a class="icon-btn" href="/audit.php" aria-label="Audit" title="Audit">Audit</a>       |       
    <a class="icon-btn" href="/logout.php" aria-label="Logout" title="Logout">Logout ⏻</a>

    <div class="user-pill" aria-label="Signed in user">
      <span class="user-pill__email"><?= htmlspecialchars($u['email'] ?? 'unknown') ?></span>
      <span class="badge"><?= htmlspecialchars($u['role'] ?? 'unknown') ?></span>
    </div>
  </div>
</header>