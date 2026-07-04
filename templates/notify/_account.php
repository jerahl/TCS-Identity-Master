<?php
/** The "Your account" box. @var array $person */
$dash = static fn($v): string => trim((string) $v) === '' ? '—' : (string) $v;
?>
<div class="acct">
  <h2>Your account</h2>
  <dl class="kv">
    <dt>Username</dt><dd class="mono"><?= e($dash($person['username'] ?? '')) ?></dd>
    <dt>Email</dt><dd class="mono"><?= e($dash($person['email'] ?? '')) ?></dd>
    <?php if (trim((string) ($person['upn'] ?? '')) !== ''): ?>
    <dt>Sign-in (UPN)</dt><dd class="mono"><?= e($person['upn']) ?></dd>
    <?php endif; ?>
    <dt>Employee ID</dt><dd class="mono"><?= e($dash($person['employee_id'] ?? '')) ?></dd>
    <?php if (($startDate ?? '') !== ''): ?>
    <dt>Start date</dt><dd class="mono"><?= e($startDate) ?></dd>
    <?php endif; ?>
  </dl>
</div>
