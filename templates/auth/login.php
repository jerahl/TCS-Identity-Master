<?php
/** @var bool $samlConfigured @var bool $devAllowed @var string $csrf @var ?string $flash */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Sign in — TCS Identity Master</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="login">
  <div class="login__card card">
    <div class="login__brand">
      <div class="brand__logo">
        <svg width="20" height="20" viewBox="0 0 20 20"><circle cx="7.5" cy="10" r="4.2" fill="none" stroke="#fff" stroke-width="1.7"/><circle cx="12.5" cy="10" r="4.2" fill="none" stroke="rgba(255,255,255,.62)" stroke-width="1.7"/></svg>
      </div>
      <div>
        <div style="font-size:16px; font-weight:600;">Identity Master</div>
        <div style="font-size:11px; letter-spacing:.6px; color:#6E93A2; text-transform:uppercase; font-weight:500;">Tuscaloosa City Schools</div>
      </div>
    </div>

    <?php if (!empty($flash)): ?>
      <div class="notice notice--warn" style="margin-bottom:16px;"><?= e($flash) ?></div>
    <?php endif; ?>

    <p class="muted" style="font-size:13px; margin:0 0 18px;">Sign in to manage staff identity. Access is logged.</p>

    <?php if ($samlConfigured): ?>
      <a class="btn btn--primary" href="<?= e(url('/saml/login')) ?>" style="width:100%; justify-content:center; height:44px;">
        Sign in with ClassLink
      </a>
    <?php elseif (!$devAllowed): ?>
      <div class="notice notice--warn">Single sign-on is not configured. Set the SAML_* values in <code>.env</code>.</div>
    <?php endif; ?>

    <?php if ($devAllowed): ?>
      <div style="margin-top:<?= $samlConfigured ? '20px' : '4px' ?>; padding-top:<?= $samlConfigured ? '20px' : '0' ?>; <?= $samlConfigured ? 'border-top:1px solid #EDF1F3;' : '' ?>">
        <div class="notice notice--info" style="margin-bottom:14px;">
          Dev login (non-production, no SAML). Pick a role to exercise RBAC. Disabled automatically once SAML is configured or APP_ENV=production.
        </div>
        <form method="post" action="<?= e(url('/dev-login')) ?>">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <label class="field-label">Email</label>
          <input class="field" name="email" type="email" required placeholder="you@tuscaloosacityschools.com">
          <label class="field-label">Display name</label>
          <input class="field" name="name" type="text" placeholder="A. Reyes">
          <label class="field-label">Role</label>
          <select class="field" name="role">
            <option value="admin">admin</option>
            <option value="editor">editor</option>
            <option value="readonly">readonly</option>
          </select>
          <button class="btn btn--ghost" type="submit" style="width:100%; justify-content:center; height:42px; margin-top:14px;">Dev sign in</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
