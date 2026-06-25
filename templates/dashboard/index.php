<?php
/** @var array $kpis @var array $activity @var array $feeds @var array $failedSyncs */
use App\View\Present;

$k = $kpis;
$cards = [
    ['label' => 'Pending review', 'value' => $k['pendingReview'], 'sub' => 'awaiting a decision', 'tone' => $k['pendingReview'] > 0 ? 'warn' : 'ok', 'href' => url('/review')],
    ['label' => 'Pending activation', 'value' => $k['pendingActivation'], 'sub' => 'not yet provisioned', 'tone' => 'amber', 'href' => url('/people', ['status' => 'pending'])],
    ['label' => 'Missing username', 'value' => $k['missingUsername'], 'sub' => 'no account yet', 'tone' => $k['missingUsername'] > 0 ? 'warn' : 'ok', 'href' => url('/people', ['missing' => 1])],
    ['label' => 'Unmapped values', 'value' => $k['unmapped'], 'sub' => 'school + ethnicity', 'tone' => $k['unmapped'] > 0 ? 'warn' : 'ok', 'href' => url('/reference')],
    ['label' => 'Failed syncs', 'value' => $k['failedSync'], 'sub' => 'last sync failed', 'tone' => $k['failedSync'] > 0 ? 'alert' : 'ok', 'href' => url('/dashboard') . '#failed'],
    ['label' => 'Last feed run', 'value' => $k['lastFeed'] ? ucfirst($k['lastFeed']['system']) : '—', 'sub' => $k['lastFeed'] ? ($k['lastFeed']['status'] . ' · ' . $k['lastFeed']['started_at']) : 'no imports yet', 'tone' => 'ok', 'href' => url('/import')],
];
?>
<div class="page-head">
  <div>
    <h1>System health</h1>
    <p>One golden record per person across NextGen, PowerSchool &amp; manual entry — synced to AD and Google by OneSync.</p>
  </div>
</div>

<div class="kpi-grid">
  <?php foreach ($cards as $c): ?>
  <a class="kpi" href="<?= e($c['href']) ?>">
    <div class="kpi__top">
      <span class="kpi__label"><?= e($c['label']) ?></span>
      <span class="kpi__dot kpi__dot--<?= e($c['tone']) ?>"></span>
    </div>
    <div class="kpi__value"><?= e($c['value']) ?></div>
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
    <h2 class="panel__title" style="margin-bottom:14px;">Feeds</h2>
    <?php if ($feeds === []): ?>
      <p class="muted" style="font-size:12.5px;">No imports recorded yet.</p>
    <?php else: ?>
      <?php foreach ($feeds as $f): ?>
      <div class="feed">
        <div class="feed__head">
          <span style="font-weight:600; font-size:13px;"><?= e(ucfirst($f['system'])) ?></span>
          <span class="badge badge--<?= e(Present::importMod($f['status']) === 'ok' ? 'active' : (Present::importMod($f['status']) === 'fail' ? 'terminated' : 'pending')) ?>"><?= e($f['status']) ?></span>
        </div>
        <div class="feed__meta mono"><?= e($f['started_at']) ?> · <?= e((int) $f['row_count']) ?> rows</div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <a class="act__more" href="<?= e(url('/import')) ?>">Open import history →</a>
  </div>
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
