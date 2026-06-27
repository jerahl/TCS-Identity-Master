<?php
/** @var string $content @var string $title @var string $activeNav @var string $crumb */
/** @var int $queueCount @var string $searchQuery @var array $currentUser */
$env = strtoupper((string) \App\Config::get('APP_ENV', 'development'));
$nav = static fn(string $key): string => $activeNav === $key ? ' is-active' : '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="sidebar__brand">
      <div class="brand__logo">
        <svg width="20" height="20" viewBox="0 0 20 20"><circle cx="7.5" cy="10" r="4.2" fill="none" stroke="#fff" stroke-width="1.7"/><circle cx="12.5" cy="10" r="4.2" fill="none" stroke="rgba(255,255,255,.62)" stroke-width="1.7"/></svg>
      </div>
      <div>
        <div class="brand__name">Identity Master</div>
        <div class="brand__sub">Tuscaloosa City Schools</div>
      </div>
    </div>

    <nav class="nav">
      <a class="nav-item<?= $nav('home') ?>" href="<?= e(url('/dashboard')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="currentColor"><rect x="1" y="1" width="7" height="7" rx="1.6"/><rect x="10" y="1" width="7" height="7" rx="1.6"/><rect x="1" y="10" width="7" height="7" rx="1.6"/><rect x="10" y="10" width="7" height="7" rx="1.6"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="nav-item<?= $nav('review') ?>" href="<?= e(url('/review')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="1.5" y="3" width="9" height="12" rx="2"/><rect x="7.5" y="3" width="9" height="12" rx="2"/></svg>
        <span style="flex:1;">Review queue</span>
        <?php if ($queueCount > 0): ?><span class="nav-item__badge"><?= e($queueCount) ?></span><?php endif; ?>
      </a>
      <a class="nav-item<?= $nav('people') ?>" href="<?= e(url('/people')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="currentColor"><rect x="2" y="3" width="14" height="2.4" rx="1.2"/><rect x="2" y="8" width="14" height="2.4" rx="1.2"/><rect x="2" y="13" width="14" height="2.4" rx="1.2"/></svg>
        <span>People</span>
      </a>
<?php if (!empty($canEdit)): ?>
      <a class="nav-item<?= $nav('add') ?>" href="<?= e(url('/add')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="7" cy="6" r="3.3"/><path d="M1.8 15.5c.4-2.7 2.4-4.3 5.2-4.3" stroke-linecap="round"/><path d="M13.5 9.2v5M11 11.7h5" stroke-linecap="round"/></svg>
        <span>Add person</span>
      </a>
<?php endif; ?>

      <div class="nav__section">Configuration</div>
      <a class="nav-item<?= $nav('ref') ?>" href="<?= e(url('/reference')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="1.5" y="2" width="15" height="14" rx="2"/><path d="M1.5 6.7h15M7 6.7V16"/></svg>
        <span>Reference data</span>
      </a>
      <a class="nav-item<?= $nav('import') ?>" href="<?= e(url('/import')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v8M5.5 7L9 10.5 12.5 7"/><path d="M2.5 12.5v2a1 1 0 001 1h11a1 1 0 001-1v-2"/></svg>
        <span>Import / feeds</span>
      </a>
<?php if (!empty($canAdmin)): ?>
      <a class="nav-item<?= $nav('users') ?>" href="<?= e(url('/users')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="6.5" cy="6" r="2.6"/><circle cx="12.5" cy="7" r="2.1"/><path d="M2 15c.4-2.6 2.2-4 4.5-4s4.1 1.4 4.5 4M11 15c.3-1.8 1.4-2.8 3-2.8" stroke-linecap="round"/></svg>
        <span>Users</span>
      </a>
      <a class="nav-item<?= $nav('audit') ?>" href="<?= e(url('/audit')) ?>">
        <span class="nav-item__bar"></span>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="2" width="12" height="14" rx="2"/><path d="M6 6h6M6 9h6M6 12h4" stroke-linecap="round"/></svg>
        <span>Audit log</span>
      </a>
<?php endif; ?>
    </nav>

    <div class="sidebar__status">
      <span class="dot dot--ok"></span>
      <span>OneSync interface</span>
      <span class="sidebar__ver">M2</span>
    </div>
  </aside>

  <div class="content">
    <header class="topbar">
      <div class="topbar__crumb"><?= e($crumb) ?></div>
      <div class="topbar__spacer"></div>
      <form class="search" method="get" action="<?= e(url('/people')) ?>">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="#9AAAB5" stroke-width="1.7"><circle cx="7" cy="7" r="5"/><path d="M11 11l3.5 3.5" stroke-linecap="round"/></svg>
        <input class="search__input" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Search people, IDs, emails…" autocomplete="off">
      </form>
      <div class="env-badge">
        <span class="dot"></span>
        <span><?= e($env) ?></span>
      </div>
      <div class="user">
        <div class="user__avatar"><?= e($currentUser['initials']) ?></div>
        <div>
          <div class="user__name"><?= e($currentUser['name']) ?></div>
          <div class="user__role"><?= e($currentUser['role']) ?></div>
        </div>
        <a class="user__logout" href="<?= e(url('/logout')) ?>" title="Sign out" aria-label="Sign out">
          <svg width="16" height="16" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 15H3.5a1 1 0 01-1-1V4a1 1 0 011-1H7"/><path d="M11.5 12.5L15 9l-3.5-3.5M15 9H6.5"/></svg>
        </a>
      </div>
    </header>

    <main class="main">
<?php if (!empty($flash)): ?>
      <div class="toast" role="status">
        <span class="dot dot--ok"></span>
        <span><?= e($flash) ?></span>
      </div>
<?php endif; ?>
<?= $content ?>
    </main>
  </div>
</div>
</body>
</html>
