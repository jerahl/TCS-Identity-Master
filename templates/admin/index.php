<?php
/**
 * @var array $services @var array $feeds @var array $studentSync
 * @var ?array $onesyncLast @var array $recentRuns @var string $csrf
 * @var bool $canRunFeeds @var bool $canRunStudents @var bool $canRunOnesync
 * @var bool $canAdmin
 */
use App\Service\ServiceRunLog;
use App\View\Present;

// state -> badge tone + left-border accent for the service cards.
$badgeFor = ['ok' => 'active', 'warn' => 'pending', 'down' => 'terminated', 'disabled' => 'disabled'];
$borderFor = ['ok' => '#34D399', 'warn' => '#F5B301', 'down' => '#E5484D', 'disabled' => '#CBD5E1'];
$tone = static fn(string $s): string => $badgeFor[$s] ?? 'disabled';

$fmtTs = static fn(?string $ts): string => $ts === null || $ts === '' ? '—' : str_replace('T', ' ', (string) $ts);

// A "Run now" button (admin only) or a muted "why it's unavailable" note.
$runForm = static function (string $action, string $label, bool $enabled, string $confirm, string $disabledNote) use ($csrf, $canAdmin): string {
    if (empty($canAdmin)) {
        return '';
    }
    if (!$enabled) {
        return '<span class="muted" style="font-size:11.5px;">' . e($disabledNote) . '</span>';
    }
    return '<form method="post" action="' . e(url($action)) . '" style="flex:0 0 auto;"'
        . ' onsubmit="return confirm(' . e(json_encode($confirm)) . ');">'
        . '<input type="hidden" name="_csrf" value="' . e($csrf) . '">'
        . '<button type="submit" class="btn btn--ghost" title="' . e($label) . '">'
        . '<svg width="14" height="14" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M15.5 3.5v4h-4"/><path d="M15 7.5A6.5 6.5 0 1 0 16 11"/></svg> '
        . e($label) . '</button></form>';
};
?>
<div class="page-head">
  <div>
    <h1>Services</h1>
    <p>Health of every moving part behind Identity Master, when each background job last ran, and — for admins — a way to run them on demand.</p>
  </div>
</div>

<h2 class="panel__title" style="margin-bottom:10px;">Service status</h2>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px; margin-bottom:24px;">
  <?php foreach ($services as $svc): ?>
  <div class="card card--pad" style="border-left:4px solid <?= e($borderFor[$svc['state']] ?? '#CBD5E1') ?>;">
    <div style="display:flex; align-items:center; gap:8px;">
      <strong style="font-size:13.5px; flex:1;"><?= e($svc['label']) ?></strong>
      <span class="badge badge--<?= e($tone($svc['state'])) ?>"><?= e(strtoupper($svc['state'])) ?></span>
    </div>
    <div class="muted" style="font-size:12.5px; margin-top:4px;"><?= e($svc['detail']) ?></div>
    <?php if (!empty($svc['facts'])): ?>
    <dl style="margin:10px 0 0; display:grid; grid-template-columns:auto 1fr; gap:2px 12px; font-size:12px;">
      <?php foreach ($svc['facts'] as [$k, $v]): ?>
        <dt class="muted"><?= e($k) ?></dt><dd class="mono" style="margin:0; word-break:break-all;"><?= e($v) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<h2 class="panel__title" style="margin-bottom:10px;">Jobs &amp; last run</h2>

<!-- Feed imports -->
<div class="panel" style="margin-bottom:16px;">
  <div class="panel__head" style="justify-content:space-between; align-items:flex-start;">
    <div>
      <h2 class="panel__title" style="margin-bottom:4px;">Feed imports</h2>
      <p class="panel__note">NextGen &amp; other SFTP feeds, plus PowerSchool over Oracle ODBC. Most recent import per source.</p>
    </div>
    <?= $runForm('/admin/run/feeds', 'Run feed pull',
        $canRunFeeds,
        "Pull all configured feeds now?\n\nThis downloads new files over SFTP and imports PowerSchool from Oracle, synchronously — the page may take a moment.",
        'No feeds configured') ?>
  </div>
  <?php if ($feeds === []): ?>
    <p class="muted" style="font-size:12.5px;">No imports recorded yet.</p>
  <?php else: ?>
    <?php foreach ($feeds as $f): $mod = Present::importMod($f['status']); ?>
    <div class="feed">
      <div class="feed__head">
        <span style="font-weight:600; font-size:13px;"><?= e($f['label'] ?? ucfirst($f['system'])) ?></span>
        <span>
          <?php if (($f['fresh_state'] ?? '') === 'stale'): ?><span class="sync-badge sync-badge--fail" style="margin-right:4px;">stale</span><?php endif; ?>
          <span class="badge badge--<?= e($mod === 'ok' ? 'active' : ($mod === 'fail' ? 'terminated' : 'pending')) ?>"><?= e($f['status']) ?></span>
        </span>
      </div>
      <div class="feed__meta mono">
        <?= e($f['fresh_label'] ?? $f['started_at']) ?> · <?= e((int) $f['row_count']) ?> rows<?php if ((int) ($f['review_count'] ?? 0) > 0): ?> · <a href="<?= e(url('/review')) ?>" style="color:#B45309;"><?= e((int) $f['review_count']) ?> to review</a><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <a class="act__more" href="<?= e(url('/import')) ?>">Open import history →</a>
</div>

<!-- Students sync -->
<div class="panel" style="margin-bottom:16px;">
  <div class="panel__head" style="justify-content:space-between; align-items:flex-start;">
    <div>
      <h2 class="panel__title" style="margin-bottom:4px;">Students sync</h2>
      <p class="panel__note">Passthrough from PowerSchool into <span class="mono">v_onesync_student_source</span>.</p>
    </div>
    <?= $runForm('/admin/run/students', 'Run students sync',
        $canRunStudents,
        "Pull active students from PowerSchool now?\n\nRuns synchronously against the Oracle ODBC connection — the page may take a moment.",
        'PowerSchool ODBC not configured') ?>
  </div>
  <?php $ss = $studentSync; $lr = $ss['lastRun'] ?? null; ?>
  <?php if ($lr === null): ?>
    <p class="muted" style="font-size:12.5px;">No students sync has run yet.</p>
  <?php else: $mod = Present::importMod($lr['status']); ?>
  <div class="feed">
    <div class="feed__head">
      <span style="font-weight:600; font-size:13px;"><?= e((int) ($ss['active'] ?? 0)) ?> active student<?= ((int) ($ss['active'] ?? 0) === 1 ? '' : 's') ?> in OneSync source</span>
      <span>
        <?php if (($ss['state'] ?? '') === 'stale'): ?><span class="sync-badge sync-badge--fail" style="margin-right:4px;">stale</span><?php endif; ?>
        <span class="badge badge--<?= e($mod === 'ok' ? 'active' : ($mod === 'fail' ? 'terminated' : 'pending')) ?>"><?= e($lr['status']) ?></span>
      </span>
    </div>
    <div class="feed__meta mono">
      last run <?= e($ss['label'] ?? $lr['started_at']) ?> · <?= e((int) $lr['row_count']) ?> rows · <?= e((int) $lr['inserted']) ?> new · <?= e((int) $lr['updated']) ?> updated · <?= e((int) $lr['deactivated']) ?> deactivated
    </div>
    <?php if (!empty($lr['message']) && $lr['status'] === 'failed'): ?>
      <div class="feed__meta" style="color:#94413A;"><?= e($lr['message']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- OneSync DB sync -->
<div class="panel" style="margin-bottom:16px;">
  <div class="panel__head" style="justify-content:space-between; align-items:flex-start;">
    <div>
      <h2 class="panel__title" style="margin-bottom:4px;">OneSync DB sync</h2>
      <p class="panel__note">Pulls provisioning results from OneSync's database into <span class="mono">account_sync_status</span> (<span class="mono">bin/import_onesync_db.php</span>).</p>
    </div>
    <?= $runForm('/admin/run/onesync-db', 'Run OneSync DB sync',
        $canRunOnesync,
        "Pull OneSync provisioning results now?\n\nReads OneSync's database and updates per-account sync status, synchronously — the page may take a moment.",
        'OneSync DB not configured') ?>
  </div>
  <?php if ($onesyncLast === null): ?>
    <p class="muted" style="font-size:12.5px;">No OneSync DB sync has been recorded yet. It runs from cron via <span class="mono">bin/import_onesync_db.php</span>, or with the button above.</p>
  <?php else:
      $st = (string) $onesyncLast['status'];
      $c = $onesyncLast['counts_json'] ? json_decode((string) $onesyncLast['counts_json'], true) : null;
  ?>
  <div class="feed">
    <div class="feed__head">
      <span style="font-weight:600; font-size:13px;">
        <?php if (is_array($c)): ?><?= e((int) ($c['upserted'] ?? 0)) ?> accounts updated · <?= e((int) ($c['failed'] ?? 0)) ?> failed<?php else: ?>OneSync DB sync<?php endif; ?>
      </span>
      <span class="badge badge--<?= e($st === 'complete' ? 'active' : ($st === 'failed' ? 'terminated' : 'pending')) ?>"><?= e($st) ?></span>
    </div>
    <div class="feed__meta mono">
      last run <?= e($fmtTs($onesyncLast['finished_at'] ?? $onesyncLast['started_at'])) ?>
      · <?= e((string) ($onesyncLast['origin'] ?? 'manual')) ?><?php if (!empty($onesyncLast['actor'])): ?> · <?= e((string) $onesyncLast['actor']) ?><?php endif; ?>
    </div>
    <?php if (!empty($onesyncLast['message'])): ?>
      <div class="feed__meta"<?= $st === 'failed' ? ' style="color:#94413A;"' : '' ?>><?= e((string) $onesyncLast['message']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Recent manual/CLI runs -->
<div class="panel">
  <h2 class="panel__title" style="margin-bottom:4px;">Recent runs</h2>
  <p class="panel__note" style="margin-bottom:14px;">Job runs recorded in <span class="mono">service_run</span> (manual and cron), newest first.</p>
  <?php if ($recentRuns === []): ?>
    <p class="muted" style="font-size:12.5px;">No runs recorded yet.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Job</th><th>Started</th><th>Origin</th><th>By</th><th>Status</th><th>Result</th></tr></thead>
      <tbody>
        <?php foreach ($recentRuns as $r): $st = (string) $r['status']; ?>
        <tr>
          <td><?= e(ServiceRunLog::label((string) $r['job'])) ?></td>
          <td class="mono" style="font-size:12px;"><?= e($fmtTs($r['started_at'] ?? null)) ?></td>
          <td><?= e((string) $r['origin']) ?></td>
          <td><?= e((string) ($r['actor'] ?? '—')) ?></td>
          <td><span class="badge badge--<?= e($st === 'complete' ? 'active' : ($st === 'failed' ? 'terminated' : 'pending')) ?>"><?= e($st) ?></span></td>
          <td<?= $st === 'failed' ? ' style="color:#94413A;"' : '' ?>><?= e((string) ($r['message'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
