<?php
/** @var array $kpis @var array $activity @var array $feeds @var array $failedSyncs @var array $disableCandidates @var array $syncHealth @var array $alerts */
use App\View\Present;

$k = $kpis;
$sh = $syncHealth ?? ['state' => 'never', 'label' => 'never', 'staleAccounts' => 0];
$syncTone = ['fresh' => 'ok', 'stale' => 'warn', 'never' => 'alert'][$sh['state']] ?? 'alert';

$ss = $studentSync ?? ['state' => 'never', 'label' => 'never', 'status' => null, 'active' => 0];
$studentTone = ($ss['status'] ?? null) === 'failed'
    ? 'alert'
    : (['fresh' => 'ok', 'stale' => 'warn', 'never' => 'alert'][$ss['state']] ?? 'alert');
$studentSub = $ss['state'] === 'never'
    ? 'never run'
    : (($ss['status'] ?? '') === 'failed' ? 'last run failed' : ($ss['active'] . ' active · synced ' . $ss['label']));
$cards = [
    ['label' => 'Pending review', 'value' => $k['pendingReview'], 'sub' => 'awaiting a decision', 'tone' => $k['pendingReview'] > 0 ? 'warn' : 'ok', 'href' => url('/review')],
    ['label' => 'Pending activation', 'value' => $k['pendingActivation'], 'sub' => 'not yet provisioned', 'tone' => 'amber', 'href' => url('/people', ['status' => 'pending'])],
    ['label' => 'Missing username', 'value' => $k['missingUsername'], 'sub' => 'no account yet', 'tone' => $k['missingUsername'] > 0 ? 'warn' : 'ok', 'href' => url('/people', ['missing' => 1])],
    ['label' => 'Unmapped values', 'value' => $k['unmapped'], 'sub' => 'school + ethnicity', 'tone' => $k['unmapped'] > 0 ? 'warn' : 'ok', 'href' => url('/reference')],
    ['label' => 'Failed syncs', 'value' => $k['failedSync'], 'sub' => 'last sync failed', 'tone' => $k['failedSync'] > 0 ? 'alert' : 'ok', 'href' => url('/dashboard') . '#failed'],
    ['label' => 'To disable', 'value' => $k['disableFlagged'], 'sub' => 'left, still enabled', 'tone' => $k['disableFlagged'] > 0 ? 'warn' : 'ok', 'href' => url('/dashboard') . '#disable'],
    ['label' => 'OneSync write-back', 'value' => $sh['label'], 'sub' => $sh['state'] === 'never' ? 'never run' : ($sh['staleAccounts'] . ' stale account' . ($sh['staleAccounts'] === 1 ? '' : 's')), 'tone' => $syncTone, 'href' => url('/dashboard') . '#failed'],
    ['label' => 'Students → OneSync', 'value' => $ss['active'], 'sub' => $studentSub, 'tone' => $studentTone, 'href' => url('/dashboard') . '#students'],
    ['label' => 'Last feed run', 'value' => $k['lastFeed'] ? ucfirst($k['lastFeed']['system']) : '—', 'sub' => $k['lastFeed'] ? ($k['lastFeed']['status'] . ' · ' . $k['lastFeed']['started_at']) : 'no imports yet', 'tone' => 'ok', 'href' => url('/import')],
];
?>
<div class="page-head">
  <div>
    <h1>System health</h1>
    <p>One golden record per person across NextGen, PowerSchool &amp; manual entry — synced to AD and Google by OneSync.</p>
  </div>
</div>

<?php foreach (($alerts ?? []) as $alert): ?>
  <div class="notice notice--warn" style="margin-bottom:14px;">
    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" stroke="#9A6A12" stroke-width="1.7" style="flex:0 0 17px; margin-top:1px;"><path d="M9 1.5L17 15.5H1L9 1.5z" stroke-linejoin="round"/><path d="M9 7v3.5" stroke-linecap="round"/><circle cx="9" cy="12.7" r=".6" fill="#9A6A12" stroke="none"/></svg>
    <div><?= e($alert) ?></div>
  </div>
<?php endforeach; ?>

<div class="kpi-grid">
  <?php foreach ($cards as $c): ?>
  <a class="kpi" href="<?= e($c['href']) ?>">
    <div class="kpi__top">
      <span class="kpi__label"><?= e($c['label']) ?></span>
      <span class="kpi__dot kpi__dot--<?= e($c['tone']) ?>"></span>
    </div>
    <div class="kpi__value<?= is_numeric($c['value']) ? '' : ' kpi__value--text' ?>" title="<?= e($c['value']) ?>"><?= e($c['value']) ?></div>
    <div class="kpi__sub"><?= e($c['sub']) ?></div>
  </a>
  <?php endforeach; ?>
</div>

<div class="dash-cols">
  <div class="panel">
    <div class="panel__head" style="justify-content:space-between;">
      <h2 class="panel__title">Recent activity</h2>
    </div>
    <?php if ($activity === []): ?>
      <p class="muted" style="font-size:12.5px;">No activity yet. Run an importer or work the review queue.</p>
    <?php else: ?>
      <?php foreach ($activity as $a):
          $detail = $a['detail'] ? json_decode((string) $a['detail'], true) : null;
          $summary = is_array($detail) ? ($detail['summary'] ?? '') : '';
          $name = trim($a['first_name'] . ' ' . $a['last_name']);
      ?>
      <div class="act">
        <span class="tl-dot tl-dot--<?= e($a['event_type']) ?>" style="margin-top:5px;"></span>
        <div style="flex:1; min-width:0;">
          <div class="act__text"><a href="<?= e(url('/people/' . $a['person_id'])) ?>"><?= e($name) ?></a> — <?= e($summary !== '' ? $summary : str_replace('_', ' ', $a['event_type'])) ?></div>
          <div class="act__meta"><?= e($a['actor'] ?? 'system') ?> · <?= e($a['occurred_at']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2 class="panel__title" style="margin-bottom:4px;">Feeds by source</h2>
    <p class="panel__note" style="margin-bottom:14px;">Most recent import per source category.</p>
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
          <?= e($f['fresh_label'] ?? $f['started_at']) ?> · <?= e((int) $f['row_count']) ?> rows<?php if ((int) $f['review_count'] > 0): ?> · <a href="<?= e(url('/review')) ?>" style="color:#B45309;"><?= e((int) $f['review_count']) ?> to review</a><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <a class="act__more" href="<?= e(url('/import')) ?>">Open import history →</a>
  </div>
</div>

<div class="panel" id="students" style="margin-top:18px;">
  <h2 class="panel__title" style="margin-bottom:4px;">Students → OneSync</h2>
  <p class="panel__note" style="margin-bottom:14px;">Passthrough from PowerSchool. OneSync reads <span class="mono">v_onesync_student_source</span>; this app only shows the status of the sync.</p>
  <?php $lr = $ss['lastRun'] ?? null; ?>
  <?php if ($lr === null): ?>
    <p class="muted" style="font-size:12.5px;">No students sync has run yet. Run <span class="mono">php bin/import_students.php</span>.</p>
  <?php else:
      $mod = Present::importMod($lr['status']);
  ?>
  <div class="feed">
    <div class="feed__head">
      <span style="font-weight:600; font-size:13px;"><?= e((int) $ss['active']) ?> active student<?= ((int) $ss['active'] === 1 ? '' : 's') ?> in OneSync source</span>
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

<div class="panel" id="disable" style="margin-top:18px;">
  <h2 class="panel__title" style="margin-bottom:4px;">Not in NextGen — past exit date</h2>
  <p class="panel__note" style="margin-bottom:14px;">People with no active NextGen record whose exit date has passed but are still enabled. NextGen never disables off-feed records — review each and disable so OneSync disables (not orphans) the account.</p>
  <?php $dc = $disableCandidates ?? []; ?>
  <?php if ($dc === []): ?>
    <p class="muted" style="font-size:12.5px;">Nothing to disable 🎉</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Person</th><th>Type</th><th>Exit date</th><th>Status</th><th>Source</th></tr></thead>
      <tbody>
        <?php foreach ($dc as $p): ?>
        <tr class="is-clickable" onclick="window.location='<?= e(url('/people/' . $p['person_id'])) ?>'">
          <td class="cell-name"><?= e(trim($p['first_name'] . ' ' . $p['last_name'])) ?></td>
          <td><?= e(ucfirst((string) $p['person_type'])) ?></td>
          <td class="mono" style="font-size:12px;"><?= e($p['end_date'] ?? '—') ?></td>
          <td><span class="badge badge--<?= e($p['status'] === 'active' ? 'active' : 'pending') ?>"><?= e($p['status']) ?></span></td>
          <td><?= e((string) $p['source_of_record']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="panel" id="failed" style="margin-top:18px;">
  <h2 class="panel__title" style="margin-bottom:4px;">Accounts whose last sync failed</h2>
  <p class="panel__note" style="margin-bottom:14px;">From per-destination provisioning status (OneSync export log).</p>
  <?php if ($failedSyncs === []): ?>
    <p class="muted" style="font-size:12.5px;">No failed syncs 🎉</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Person</th><th>Destination</th><th>When</th><th>Last error</th></tr></thead>
      <tbody>
        <?php foreach ($failedSyncs as $s): ?>
        <tr<?= $s['person_id'] ? ' class="is-clickable" onclick="window.location=\'' . e(url('/people/' . $s['person_id'])) . '\'"' : '' ?>>
          <td class="cell-name"><?= e($s['person_id'] ? trim($s['first_name'] . ' ' . $s['last_name']) : '(unresolved)') ?></td>
          <td><?= e($s['destination']) ?></td>
          <td class="mono" style="font-size:12px;"><?= e($s['last_sync_at'] ?? '—') ?></td>
          <td style="color:#94413A;"><?= e($s['message'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
