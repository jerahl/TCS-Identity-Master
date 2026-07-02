<?php
/** @var array $rows @var array $columns @var array $filters @var array $schoolOptions */

// Current filters as a query base (drop empties/defaults) for the CSV link.
$base = array_filter([
    'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
    'school' => $filters['school'] !== 'all' ? $filters['school'] : null,
    'from'   => $filters['from'] !== '' ? $filters['from'] : null,
    'to'     => $filters['to'] !== '' ? $filters['to'] : null,
    'q'      => $filters['q'] !== '' ? $filters['q'] : null,
], static fn($v) => $v !== null && $v !== '');

$statusOpts = ['all' => 'All statuses', 'pending' => 'Pending', 'active' => 'Active', 'disabled' => 'Disabled', 'terminated' => 'Terminated'];

// Cells we highlight when blank: the two that still need a human touch.
$flagBlank = ['board_approval' => true, 'alsde_id' => true];
$dash = static fn($v): string => trim((string) $v) === '' ? '—' : (string) $v;
?>
<div class="page-head">
  <div>
    <h1>Logins export</h1>
    <p><?= e(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?> — the golden record in the Logins spreadsheet layout</p>
  </div>
  <a class="btn btn--primary" href="<?= e(url('/logins.csv', $base)) ?>">
    <svg width="15" height="15" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v9M5.5 7.5L9 11l3.5-3.5"/><path d="M2.5 13v2a1 1 0 001 1h11a1 1 0 001-1v-2"/></svg>
    Download CSV
  </a>
</div>

<form class="filters" method="get" action="<?= e(url('/logins')) ?>">
  <select class="select" name="status" onchange="this.form.submit()">
    <?php foreach ($statusOpts as $val => $label): ?>
      <option value="<?= e($val) ?>"<?= $filters['status'] === $val ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="select" name="school" onchange="this.form.submit()" style="max-width:220px;">
    <option value="all"<?= $filters['school'] === 'all' ? ' selected' : '' ?>>All schools</option>
    <?php foreach ($schoolOptions as $s): ?>
      <option value="<?= e($s['school_id']) ?>"<?= (string) $filters['school'] === (string) $s['school_id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="field-label" style="align-self:center; margin:0 0 0 6px;">Effective</label>
  <input class="field mono" type="date" name="from" value="<?= e($filters['from']) ?>" style="max-width:160px;" title="Effective date from">
  <span style="align-self:center; color:#7B8E9B;">→</span>
  <input class="field mono" type="date" name="to" value="<?= e($filters['to']) ?>" style="max-width:160px;" title="Effective date to">
  <?php if ($filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>
  <button class="btn btn--ghost" type="submit" style="height:36px;">Apply</button>
</form>

<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row):
          $href = url('/people/' . $row['person_id']);
      ?>
      <tr class="is-clickable" data-href="<?= e($href) ?>">
        <?php foreach (array_keys($columns) as $key):
            $val = (string) ($row[$key] ?? '');
            $blank = trim($val) === '';
        ?>
        <td<?= in_array($key, ['effective_date','end_date','dob','employee_id','alsde_id'], true) ? ' class="mono"' : '' ?>>
          <?php if ($blank && !empty($flagBlank[$key])): ?><span class="value-missing">— <?= e(strtolower($columns[$key])) ?> —</span>
          <?php else: ?><?= e($dash($val)) ?><?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($rows === []): ?>
    <div class="empty">No people match these filters.</div>
  <?php endif; ?>
</div>
