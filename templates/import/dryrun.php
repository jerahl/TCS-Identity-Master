<?php
/** @var \App\Import\ImportSource $source @var string $fileName @var array $counts @var array $outcomes */
use App\View\Present;

$badge = static fn(string $mod): string => match ($mod) {
    'ok' => 'active', 'warn' => 'pending', 'info' => 'disabled', 'fail' => 'terminated', default => 'disabled',
};
$c = $counts;
?>
<a class="back-link" href="<?= e(url('/import')) ?>">
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 3L5 8l5 5"/></svg> All batches
</a>

<div class="page-head">
  <div>
    <h1>Dry run — <?= e($source->label) ?></h1>
    <p>Preview only — <strong>nothing was written</strong>. This is what importing
      <span class="mono"><?= e($fileName) ?></span> would change.</p>
  </div>
  <a class="btn btn--ghost" href="<?= e(url('/import')) ?>" style="height:38px; align-self:center; display:inline-flex; align-items:center;">Back to upload</a>
</div>

<div class="card card--pad" style="margin-bottom:16px; display:flex; gap:26px; flex-wrap:wrap;">
  <div><div class="kv__label">Rows</div><div class="mono" style="font-size:14px;"><?= e((int) $c['total']) ?></div></div>
  <div><div class="kv__label">Would update</div><div class="mono" style="font-size:14px;"><?= e((int) $c['auto_match']) ?></div></div>
  <div><div class="kv__label">Would create</div><div class="mono" style="font-size:14px;"><?= e((int) $c['new']) ?></div></div>
  <div><div class="kv__label">Would review</div><div class="mono" style="font-size:14px;"><?= e((int) $c['needs_review']) ?></div></div>
  <div><div class="kv__label">Skipped</div><div class="mono" style="font-size:14px;"><?= e((int) $c['skipped']) ?></div></div>
  <div><div class="kv__label">Errors</div><div class="mono" style="font-size:14px;"><?= e((int) $c['errors']) ?></div></div>
  <?php if (!empty($c['unmapped'])): ?><div><div class="kv__label">Unmapped values</div><div class="mono" style="font-size:14px; color:#B45309;"><?= e((int) $c['unmapped']) ?></div></div><?php endif; ?>
</div>

<div class="card table-wrap">
  <div style="padding:13px 16px; border-bottom:1px solid #EDF1F3; font-size:13px; font-weight:600;">Per-row preview</div>
  <table class="table">
    <thead><tr><th style="width:22%;">Incoming row</th><th style="width:12%;">Source ID</th><th style="width:18%;">Outcome</th><th>What would change</th></tr></thead>
    <tbody>
      <?php foreach ($outcomes as $o): ?>
        <?php
          [$label, $mod] = Present::dryRunOutcome($o['action']);
          $p = $o['preview'] ?? null;
          $hasChange = $p !== null && (!empty($p['field_changes']) || !empty($p['notes']));
        ?>
        <tr>
          <td class="cell-name">
            <?= e($o['name'] !== '' ? $o['name'] : '—') ?>
            <?php if (!empty($p['person']['name'])): ?>
              <div class="muted" style="font-size:11.5px;">&rarr; <?= e($p['person']['name']) ?><?= !empty($p['person']['id']) ? ' (#' . e((string) $p['person']['id']) . ')' : '' ?></div>
            <?php endif; ?>
          </td>
          <td class="mono"><?= e($o['source_key'] !== '' ? $o['source_key'] : '—') ?></td>
          <td><span class="badge badge--<?= e($badge($mod)) ?>"><?= e($label) ?></span></td>
          <td style="font-size:12.5px;">
            <?php if ($o['action'] === 'error'): ?>
              <span style="color:#B42318;"><?= e($o['reason']) ?></span>
            <?php elseif (!$hasChange): ?>
              <span class="muted"><?= e($o['action'] === 'skipped' && !empty($o['reason']) ? $o['reason'] : 'No change') ?></span>
            <?php else: ?>
              <?php if (!empty($p['notes'])): ?>
                <ul style="margin:0 0 4px; padding-left:16px;">
                  <?php foreach ($p['notes'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if (!empty($p['field_changes'])): ?>
                <table style="border-collapse:collapse; font-size:12px;">
                  <?php foreach ($p['field_changes'] as $fc): ?>
                    <tr>
                      <td style="padding:1px 10px 1px 0; color:#52677A; white-space:nowrap;"><?= e($fc['label']) ?></td>
                      <td style="padding:1px 6px; color:#98A7B3; text-decoration:line-through;"><?= e($fc['from'] ?? '—') ?></td>
                      <td style="padding:1px 6px; color:#98A7B3;">&rarr;</td>
                      <td style="padding:1px 6px; font-weight:600;"><?= e($fc['to'] ?? '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              <?php endif; ?>
            <?php endif; ?>
            <?php foreach (($o['warnings'] ?? []) as $w): ?>
              <div style="color:#B45309; margin-top:2px;">! <?= e($w) ?></div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($outcomes === []): ?><tr><td colspan="4" class="empty">No rows in the feed.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
