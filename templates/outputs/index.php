<?php
/**
 * @var array $outputs @var string $csrf
 * @var ?array $adaxesSummary @var bool $canRunAdaxes @var bool $adaxesRunning
 * @var ?array $googleSummary @var bool $googleReady @var bool $googleRunEnabled @var bool $googleRunning
 * @var bool $canEdit @var bool $canAdmin
 */

// state -> badge tone + left-border accent for the status cards.
$badgeFor = ['ok' => 'active', 'warn' => 'pending', 'down' => 'terminated', 'disabled' => 'disabled'];
$borderFor = ['ok' => '#34D399', 'warn' => '#F5B301', 'down' => '#E5484D', 'disabled' => '#CBD5E1'];
$tone = static fn(string $s): string => $badgeFor[$s] ?? 'disabled';

// A "Run now" button (gated by capability) or a muted "why it's unavailable" note.
$runForm = static function (string $action, string $label, bool $show, bool $enabled, string $confirm, string $disabledNote) use ($csrf): string {
    if (!$show) {
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
    <h1>Outputs</h1>
    <p>Where the golden record is pushed out. Every output sync — Active Directory (Adaxes) and Google Workspace — with its health, last run, what it changed, and (for the right role) a way to run it on demand.</p>
  </div>
</div>

<h2 class="panel__title" style="margin-bottom:10px;">Output status</h2>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px; margin-bottom:24px;">
  <?php foreach ($outputs as $svc): ?>
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

<!-- Active Directory sync (Adaxes) summary -->
<div class="panel" style="margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
    <h2 class="panel__title" style="margin:0; flex:1;">Active Directory sync (Adaxes)</h2>
    <?= $runForm('/outputs/run/adaxes', 'Run AD sync now',
        !empty($canAdmin),
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

<!-- Google Workspace sync summary -->
<div class="panel">
  <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
    <h2 class="panel__title" style="margin:0; flex:1;">Google Workspace sync <span class="muted" style="font-weight:500; font-size:12.5px;">(direct · bypasses OneSync)</span></h2>
    <?= $runForm('/outputs/run/google', 'Run Google sync now',
        !empty($canEdit) && !empty($googleReady),
        !empty($googleRunEnabled) && empty($googleRunning),
        "Reconcile Google Workspace to the golden record now?\n\nIt runs in the background: create missing accounts, push name drift, and suspend disabled/terminated people. Never auto-restores. Guarded against mass-suspend.",
        !empty($googleRunning) ? 'A sync is already running' : 'On-demand run off (GOOGLE_RUN_ENABLED)') ?>
  </div>
  <p class="panel__note" style="margin-bottom:14px;">Reconciles Google to the golden record — create missing accounts (active people with an email), push name drift, and suspend disabled/terminated people. Never auto-restores; guarded against mass-suspend. Runs nightly via <span class="mono">bin/sync_google.php</span>, or on demand with the button. For a dry-run preview, use the CLI: <span class="mono">php bin/sync_google.php --dry-run</span>.</p>

  <?php if (!empty($googleRunning)): ?>
    <div class="card--pad" style="border-left:4px solid #F5B301; background:#FFFBEB; font-size:12.5px; margin-bottom:12px;">A sync is <strong>running</strong> now — refresh in a minute or two for the results.</div>
  <?php endif; ?>

  <?php if (empty($googleReady)): ?>
    <p class="muted" style="font-size:12.5px;">Direct Google provisioning is not configured — set <span class="mono">GOOGLE_DIRECT_ENABLED=true</span> plus the <span class="mono">GOOGLE_SA_*</span> credentials and <span class="mono">GOOGLE_ADMIN_SUBJECT</span> to enable.</p>
  <?php elseif ($googleSummary === null): ?>
    <p class="muted" style="font-size:12.5px;">No Google sync has been recorded yet.</p>
  <?php else:
      $st = (string) $googleSummary['status'];
      $stTone = $st === 'complete' ? 'active' : ($st === 'failed' ? 'terminated' : 'pending');
  ?>
    <!-- Highlights -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:16px;">
      <div class="card card--pad" style="border-left:4px solid <?= $googleSummary['attention'] > 0 ? '#E5484D' : '#34D399' ?>;">
        <div style="font-size:22px; font-weight:700;"><?= e((int) $googleSummary['attention']) ?></div>
        <div class="muted" style="font-size:12px;">require attention (<?= e((int) $googleSummary['errors']) ?> errors + license-blocked)</div>
      </div>
      <div class="card card--pad" style="border-left:4px solid #3D6478;">
        <div style="font-size:22px; font-weight:700;"><?= e((int) $googleSummary['actions']) ?></div>
        <div class="muted" style="font-size:12px;">changes applied this run</div>
      </div>
      <div class="card card--pad" style="border-left:4px solid #94A3B8;">
        <div style="font-size:22px; font-weight:700;"><?= e((int) $googleSummary['eligible']) ?></div>
        <div class="muted" style="font-size:12px;">people scanned · <span class="badge badge--<?= e($stTone) ?>"><?= e($st) ?></span></div>
        <div class="muted" style="font-size:12px; margin-top:4px;">last run <?= e((string) $googleSummary['when']) ?> · <?= e((string) $googleSummary['origin']) ?></div>
      </div>
    </div>

    <?php if ($googleSummary['cells'] === []): ?>
      <p class="muted" style="font-size:12.5px;">The run recorded no counts<?= $googleSummary['message'] !== '' ? ' — ' . e((string) $googleSummary['message']) : '' ?>.</p>
    <?php else: ?>
    <!-- Count breakdown -->
    <div class="card card--pad" style="border-left:4px solid <?= $googleSummary['errors'] > 0 ? '#E5484D' : '#E2E8F0' ?>;">
      <dl style="margin:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:8px 16px; font-size:12.5px;">
        <?php foreach ($googleSummary['cells'] as $cell): ?>
        <div style="display:flex; justify-content:space-between; gap:12px;">
          <dt class="muted"<?= $cell['key'] === 'errors' ? ' style="color:#94413A;"' : '' ?>><?= e((string) $cell['label']) ?></dt>
          <dd class="mono" style="margin:0; font-weight:600;"><?= e((int) $cell['value']) ?></dd>
        </div>
        <?php endforeach; ?>
      </dl>
      <?php if ($googleSummary['message'] !== '' && $st === 'failed'): ?>
        <div style="color:#94413A; font-size:12px; margin-top:10px;"><?= e((string) $googleSummary['message']) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
