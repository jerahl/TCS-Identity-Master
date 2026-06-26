<?php
/** @var array $batches @var ?array $batch @var array $staged */
use App\View\Present;

$badge = static fn(string $mod): string => match ($mod) {
    'ok' => 'active', 'warn' => 'pending', 'info' => 'disabled', 'fail' => 'terminated', default => 'disabled',
};
?>
<?php if ($batch === null): ?>
  <div class="page-head">
    <div>
      <h1>Import / feed status</h1>
      <p>Batches staged from each source system. Drill in to see staged rows and how each matched.</p>
    </div>
  </div>

  <?php if (!empty($canEdit)): ?>
  <div class="card card--pad" style="margin-bottom:18px;">
    <div class="form-section" style="margin-top:0;">Upload &amp; import a feed</div>
    <form method="post" action="<?= e(url('/import/upload')) ?>" enctype="multipart/form-data"
          style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
      <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
      <div>
        <label class="field-label">Source system</label>
        <select class="field" name="system" style="min-width:180px;">
          <?php foreach (($sources ?? []) as $s): ?>
            <option value="<?= e($s->key) ?>"><?= e($s->label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1; min-width:240px;">
        <label class="field-label">CSV file</label>
        <input class="field" type="file" name="file" accept=".csv,text/csv" required>
      </div>
      <label style="display:flex; align-items:center; gap:7px; height:38px; font-size:13px; color:#3D5462;">
        <input type="checkbox" name="dry_run" value="1"> Dry run
      </label>
      <button class="btn btn--primary" type="submit" style="height:38px;">Import</button>
    </form>
    <p class="muted" style="font-size:11.5px; margin:10px 0 0;">Columns must match the source's expected headers (see <code>src/Import/ColumnMap.php</code>). Re-uploads are idempotent — existing people re-match by source id.</p>
  </div>
  <?php endif; ?>

  <div class="card table-wrap">
    <table class="table">
      <thead><tr><th>System</th><th>File</th><th>Time</th><th style="text-align:right;">Rows</th><th style="text-align:right;">Matched</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($batches as $b): ?>
        <tr class="is-clickable" onclick="window.location='<?= e(url('/import', ['batch' => $b['batch_id']])) ?>'">
          <td class="cell-name"><?= e(ucfirst($b['system'])) ?></td>
          <td class="mono" style="font-size:12px;"><?= e($b['file_name'] ?? '—') ?></td>
          <td class="mono" style="font-size:12px;"><?= e($b['started_at']) ?></td>
          <td class="mono" style="text-align:right;"><?= e((int) $b['row_count']) ?></td>
          <td class="mono" style="text-align:right;"><?= e((int) $b['matched']) ?></td>
          <td><span class="badge badge--<?= e($badge(Present::importMod($b['status']))) ?>"><?= e($b['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($batches === []): ?><tr><td colspan="6" class="empty">No import batches yet. Run an importer.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <a class="back-link" href="<?= e(url('/import')) ?>">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 3L5 8l5 5"/></svg> All batches
  </a>
  <div class="card card--pad" style="margin-bottom:16px; display:flex; gap:30px; flex-wrap:wrap; align-items:center;">
    <div><div class="kv__label">System / file</div><div style="font-size:14px; font-weight:600;"><?= e(ucfirst($batch['system'])) ?> <span class="mono muted" style="font-weight:400; font-size:12.5px;"><?= e($batch['file_name'] ?? '') ?></span></div></div>
    <div><div class="kv__label">Imported</div><div class="mono" style="font-size:13px;"><?= e($batch['started_at']) ?></div></div>
    <div><div class="kv__label">Rows</div><div class="mono" style="font-size:13px;"><?= e((int) $batch['row_count']) ?></div></div>
    <div><div class="kv__label">Status</div><div><span class="badge badge--<?= e($badge(Present::importMod($batch['status']))) ?>"><?= e($batch['status']) ?></span></div></div>
    <?php if ($batch['message']): ?><div style="flex:1; min-width:200px;"><div class="kv__label">Summary</div><div class="mono" style="font-size:12px; color:#52677A;"><?= e($batch['message']) ?></div></div><?php endif; ?>
  </div>
  <div class="card table-wrap">
    <div style="padding:13px 16px; border-bottom:1px solid #EDF1F3; font-size:13px; font-weight:600;">Staged rows</div>
    <table class="table">
      <thead><tr><th>Incoming name</th><th>Source ID</th><th>Employee ID</th><th>Match outcome</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($staged as $r): [$label, $mod] = Present::matchOutcome($r['match_status']); ?>
        <tr<?= $r['matched_person_id'] ? ' class="is-clickable" onclick="window.location=\'' . e(url('/people/' . $r['matched_person_id'])) . '\'"' : '' ?>>
          <td class="cell-name"><?= e(trim($r['n_first'] . ' ' . $r['n_last'])) ?: '—' ?></td>
          <td class="mono"><?= e($r['n_source_key'] ?? '—') ?></td>
          <td class="mono"><?= e($r['n_employee_id'] ?? '—') ?></td>
          <td><span class="badge badge--<?= e($badge($mod)) ?>"><?= e($label) ?></span></td>
          <td class="muted" style="font-size:12px;"><?= e($r['reason'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($staged === []): ?><tr><td colspan="5" class="empty">No staged rows.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
