<?php
/** @var array $person @var string $fullName @var string $school @var string $position @var string $startDate @var string $heading */
$dash = static fn($v): string => trim((string) $v) === '' ? '—' : (string) $v;
?>
<h1><?= e($heading) ?></h1>
<p class="sub"><?= e($fullName) ?><?php if ($school !== ''): ?> · <?= e($school) ?><?php endif; ?><?php if ($position !== ''): ?> · <?= e($position) ?><?php endif; ?></p>

<div class="acct">
  <h2>Your account</h2>
  <dl class="kv">
    <dt>Username</dt><dd class="mono"><?= e($dash($person['username'] ?? '')) ?></dd>
    <dt>Email</dt><dd class="mono"><?= e($dash($person['email'] ?? '')) ?></dd>
    <?php if (trim((string) ($person['upn'] ?? '')) !== ''): ?>
    <dt>Sign-in (UPN)</dt><dd class="mono"><?= e($person['upn']) ?></dd>
    <?php endif; ?>
    <dt>Employee ID</dt><dd class="mono"><?= e($dash($person['employee_id'] ?? '')) ?></dd>
    <?php if ($startDate !== ''): ?>
    <dt>Start date</dt><dd class="mono"><?= e($startDate) ?></dd>
    <?php endif; ?>
  </dl>
</div>
