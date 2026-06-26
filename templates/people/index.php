<?php
/** @var array $people @var int $shown @var int $total @var array $filters @var array $schoolOptions */
use App\View\Present;

// Current filters as a query base, with helpers to toggle individual values.
$base = [
    'status'  => $filters['status'] !== 'all' ? $filters['status'] : null,
    'type'    => $filters['type'] !== 'all' ? $filters['type'] : null,
    'school'  => $filters['school'] !== 'all' ? $filters['school'] : null,
    'q'       => $filters['q'] !== '' ? $filters['q'] : null,
    'missing' => $filters['missing'] ? 1 : null,
    'pending' => $filters['pending'] ? 1 : null,
];
$toggle = static function (string $key) use ($base): string {
    $q = $base;
    $q[$key] = empty($base[$key]) ? 1 : null;
    return url('/people', array_filter($q, static fn($v) => $v !== null && $v !== ''));
};

// Sortable column header: link that preserves filters and toggles direction.
$curSort = $filters['sort'] ?? 'name';
$curDir = $filters['dir'] ?? 'asc';
$sortHead = static function (string $key, string $label) use ($base, $curSort, $curDir): string {
    $active = $curSort === $key;
    $nextDir = ($active && $curDir === 'asc') ? 'desc' : 'asc';
    $q = array_filter($base + ['sort' => $key, 'dir' => $nextDir], static fn($v) => $v !== null && $v !== '');
    $arrow = $active ? ($curDir === 'asc' ? ' ▲' : ' ▼') : '';
    $cls = 'th-sort' . ($active ? ' is-sorted' : '');
    return '<a class="' . $cls . '" href="' . e(url('/people', $q)) . '">' . e($label) . $arrow . '</a>';
};
$statusOpts = ['all' => 'All statuses', 'active' => 'Active', 'pending' => 'Pending', 'disabled' => 'Disabled', 'terminated' => 'Terminated'];
$typeOpts = ['all' => 'All types', 'faculty' => 'Faculty', 'staff' => 'Staff', 'contractor' => 'Contractor', 'sub' => 'Substitute', 'intern' => 'Intern'];
?>
<div class="page-head">
  <div>
    <h1>People</h1>
    <p><?= e($shown) ?> of <?= e($total) ?> records</p>
  </div>
  <?php if (!empty($canEdit)): ?>
  <a class="btn btn--primary" href="<?= e(url('/add')) ?>">
    <svg width="15" height="15" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M9 4v10M4 9h10"/></svg>
    Add person
  </a>
  <?php endif; ?>
</div>

<form class="filters" method="get" action="<?= e(url('/people')) ?>">
  <?php if ($filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>
  <?php if ($filters['missing']): ?><input type="hidden" name="missing" value="1"><?php endif; ?>
  <?php if ($filters['pending']): ?><input type="hidden" name="pending" value="1"><?php endif; ?>

  <select class="select" name="status" onchange="this.form.submit()">
    <?php foreach ($statusOpts as $val => $label): ?>
      <option value="<?= e($val) ?>"<?= $filters['status'] === $val ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="select" name="type" onchange="this.form.submit()">
    <?php foreach ($typeOpts as $val => $label): ?>
      <option value="<?= e($val) ?>"<?= $filters['type'] === $val ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="select" name="school" onchange="this.form.submit()" style="max-width:220px;">
    <option value="all"<?= $filters['school'] === 'all' ? ' selected' : '' ?>>All schools</option>
    <?php foreach ($schoolOptions as $s): ?>
      <option value="<?= e($s['school_id']) ?>"<?= (string) $filters['school'] === (string) $s['school_id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn btn--ghost" type="submit" style="height:36px;">Apply</button></noscript>

  <div class="filters__spacer"></div>
  <a class="chip<?= $filters['missing'] ? ' is-on' : '' ?>" href="<?= e($toggle('missing')) ?>"><span class="dot"></span> Missing username</a>
  <a class="chip chip--pending<?= $filters['pending'] ? ' is-on' : '' ?>" href="<?= e($toggle('pending')) ?>"><span class="dot"></span> Pending</a>
</form>

<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th><?= $sortHead('name', 'Name') ?></th><th>Type</th><th>Status</th><th>Primary school</th>
        <th>Username</th><th>Email</th><th><?= $sortHead('employee_id', 'Employee ID') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($people as $p):
          $st = Present::status($p['status']);
          $full = trim($p['first_name'] . ($p['middle_name'] ? ' ' . substr($p['middle_name'], 0, 1) . '.' : '') . ' ' . $p['last_name']);
          $href = url('/people/' . $p['person_id']);
      ?>
      <tr class="is-clickable" onclick="window.location='<?= e($href) ?>'">
        <td>
          <div class="cell-name"><a href="<?= e($href) ?>"><?= e($full) ?></a></div>
          <div class="cell-sub"><?= e(substr($p['person_uuid'], 0, 8)) ?></div>
        </td>
        <td><?= e(Present::type($p['person_type'])) ?></td>
        <td><span class="badge badge--<?= e($st['mod']) ?>"><span class="dot"></span><?= e($st['label']) ?></span></td>
        <td style="color:#3D5462;"><?= e($p['primary_school'] ?? '—') ?></td>
        <td>
          <?php if ($p['username']): ?><span class="mono"><?= e($p['username']) ?></span>
          <?php else: ?><span class="value-missing">— not set —</span><?php endif; ?>
        </td>
        <td class="mono" style="font-size:12px;"><?= e($p['email'] ?: '—') ?></td>
        <td class="mono"><?= e($p['employee_id'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($people === []): ?>
    <div class="empty">No people match these filters.</div>
  <?php endif; ?>
</div>
