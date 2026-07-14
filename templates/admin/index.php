<?php
/**
 * @var array $services @var array $feeds @var array $studentSync
 * @var ?array $onesyncLast @var array $recentRuns @var string $csrf
 * @var bool $canRunFeeds @var bool $canRunStudents @var bool $canRunOnesync
 * @var bool $onesyncEnabled @var bool $onesyncEnvLocked @var bool $canAdmin
 * @var ?array $adaxesSummary @var bool $canRunAdaxes @var bool $adaxesRunning
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
    <div style="display:flex; gap:8px; align-items:center; flex:0 0 auto;">
      <?php if (!empty($canAdmin)): ?>
        <?php if (!empty($onesyncEnvLocked)): ?>
          <span class="muted" style="font-size:11.5px;">Locked in .env (ONESYNC_DB_SYNC_ENABLED)</span>
        <?php else: ?>
          <form method="post" action="<?= e(url('/admin/onesync-sync')) ?>" style="margin:0;">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="enable" value="<?= !empty($onesyncEnabled) ? '0' : '1' ?>">
            <button type="submit" class="btn btn--sm <?= !empty($onesyncEnabled) ? 'btn--danger' : 'btn--ghost' ?>" title="<?= !empty($onesyncEnabled) ? 'Turn the OneSync DB sync off for cutover' : 'Turn the OneSync DB sync back on' ?>">
              <?= !empty($onesyncEnabled) ? 'Disable (cutover)' : 'Re-enable sync' ?>
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <?= $runForm('/admin/run/onesync-db', 'Run OneSync DB sync',
          $canRunOnesync && !empty($onesyncEnabled),
          "Pull OneSync provisioning results now?\n\nReads OneSync's database and updates per-account sync status, synchronously — the page may take a moment.",
          empty($onesyncEnabled) ? 'Sync disabled (cutover)' : 'OneSync DB not configured') ?>
    </div>
  </div>
  <?php if (empty($onesyncEnabled)): ?>
    <div class="card--pad" style="border-left:4px solid #CBD5E1; background:#F8FAFC; font-size:12.5px; margin-bottom:10px;">
      <strong>Cutover mode.</strong> The OneSync DB sync is turned <strong>off</strong> — IDM is authoritative for AD/Google and provisioning results are no longer pulled from OneSync. Re-enable it above to fall back.
    </div>
  <?php endif; ?>
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

<!-- Active Directory sync (Adaxes) summary -->
<div class="panel">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
    <h2 class="panel__title" style="margin:0; flex:1;">Active Directory sync (Adaxes)</h2>
    <?= $runForm('/admin/run/adaxes', 'Run AD sync now',
        !empty($canRunAdaxes) && empty($adaxesRunning),
        "Start the Active Directory sync now?\n\nIt runs in the background (create / edit / disable / groups). Writes are gated by ADAXES_WRITE_ENABLED; otherwise it only reports.",
        !empty($adaxesRunning) ? 'A sync is already running' : 'On-demand run off (ADAXES_RUN_ENABLED)') ?>
  </div>
  <p class="panel__note" style="margin-bottom:14px;">The last reconciler run — created / correlated / edited / expired accounts and group changes. Runs nightly via <span class="mono">bin/adaxes_sync.php</span>, or on demand with the button.</p>

  <?php if (!empty($adaxesRunning)): ?>
    <div class="card--pad" style="border-left:4px solid #F5B301; background:#FFFBEB; font-size:12.5px; margin-bottom:12px;">A sync is <strong>running</strong> now — refresh in a minute or two for the results.</div>
  <?php endif; ?>

  <?php if ($adaxesSummary === null): ?>
    <p class="muted" style="font-size:12.5px;">No AD sync has been recorded yet.</p>
  <?php else:
      $st = (string) $adaxesSummary['status'];
      $stTone = $st === 'complete' ? 'active' : ($st === 'failed' ? 'terminated' : 'pending');
  ?>
    <!-- Highlights -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:16px;">
      <div class="card card--pad" style="border-left:4px solid <?= $adaxesSummary['attention'] > 0 ? '#E5484D' : '#34D399' ?>;">
        <div style="font-size:22px; font-weight:700;"><?= e((int) $adaxesSummary['attention']) ?></div>
        <div class="muted" style="font-size:12px;">require attention (<?= e((int) $adaxesSummary['errors']) ?> errors + review)</div>
      </div>
      <div class="card card--pad" style="border-left:4px solid #3D6478;">
        <div style="font-size:22px; font-weight:700;"><?= e((int) $adaxesSummary['actions']) ?></div>
        <div class="muted" style="font-size:12px;">changes applied this run</div>
      </div>
      <div class="card card--pad" style="border-left:4px solid <?= !empty($adaxesSummary['writeEnabled']) ? '#34D399' : '#CBD5E1' ?>;">
        <div style="font-size:13px; font-weight:600;">Writes <?= !empty($adaxesSummary['writeEnabled']) ? 'ON' : 'OFF (report only)' ?></div>
        <div class="muted" style="font-size:12px;">last run <?= e((string) $adaxesSummary['when']) ?> · <?= e((string) $adaxesSummary['origin']) ?></div>
        <div style="margin-top:6px;"><span class="badge badge--<?= e($stTone) ?>"><?= e($st) ?></span></div>
      </div>
    </div>

    <?php if ($adaxesSummary['phases'] === []): ?>
      <p class="muted" style="font-size:12.5px;">The run recorded no per-phase counts<?= $adaxesSummary['message'] !== '' ? ' — ' . e((string) $adaxesSummary['message']) : '' ?>.</p>
    <?php else: ?>
    <!-- Per-phase breakdown -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
      <?php foreach ($adaxesSummary['phases'] as $ph): ?>
      <div class="card card--pad" style="border-left:4px solid <?= $ph['errors'] > 0 || !empty($ph['blocked']) ? '#E5484D' : '#E2E8F0' ?>;">
        <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px;">
          <strong style="font-size:13px; flex:1;"><?= e((string) $ph['label']) ?></strong>
          <?php if (!empty($ph['blocked'])): ?><span class="badge badge--terminated">BLOCKED</span><?php endif; ?>
          <?php if ($ph['errors'] > 0): ?><span class="badge badge--terminated"><?= e((int) $ph['errors']) ?> err</span><?php endif; ?>
        </div>
        <?php if ($ph['cells'] === []): ?>
          <div class="muted" style="font-size:12px;">no changes</div>
        <?php else: ?>
        <dl style="margin:0; display:grid; grid-template-columns:1fr auto; gap:3px 12px; font-size:12.5px;">
          <?php foreach ($ph['cells'] as $cell): ?>
            <dt class="muted"<?= $cell['key'] === 'errors' ? ' style="color:#94413A;"' : '' ?>><?= e((string) $cell['label']) ?></dt>
            <dd class="mono" style="margin:0; font-weight:600;"><?= e((int) $cell['value']) ?></dd>
          <?php endforeach; ?>
        </dl>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
