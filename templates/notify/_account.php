<?php
/**
 * The "Your account" box.
 * @var array $person @var string $tempPassword @var string $tempPasswordFallback
 */
$dash = static fn($v): string => trim((string) $v) === '' ? '—' : (string) $v;
$username = trim((string) ($person['username'] ?? ''));
$googleEmail = $username !== '' ? $username . '@tuscaloosacityschools.com' : '';
?>
<div class="acct">
  <h2>Your account</h2>
  <dl class="kv">
    <dt>Username</dt><dd class="mono"><?= e($dash($username)) ?></dd>
    <dt>Outlook Email</dt><dd class="mono"><?= e($dash($person['email'] ?? '')) ?></dd>
    <dt>Google Email</dt><dd class="mono"><?= e($dash($googleEmail)) ?></dd>
    <dt>Temporary password</dt>
    <?php if (($tempPassword ?? '') !== ''): ?>
    <dd class="mono"><?= e($tempPassword) ?></dd>
    <?php else: ?>
    <dd><em><?= e($tempPasswordFallback ?? 'provided by your school') ?></em></dd>
    <?php endif; ?>
    <?php if (trim((string) ($person['upn'] ?? '')) !== ''): ?>
    <dt>Sign-in (UPN)</dt><dd class="mono"><?= e($person['upn']) ?></dd>
    <?php endif; ?>
    <dt>Employee ID</dt><dd class="mono"><?= e($dash($person['employee_id'] ?? '')) ?></dd>
    <?php if (($startDate ?? '') !== ''): ?>
    <dt>Start date</dt><dd class="mono"><?= e($startDate) ?></dd>
    <?php endif; ?>
  </dl>
</div>
