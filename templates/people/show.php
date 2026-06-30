<?php
/** @var array $p @var array $sourceIds @var array $assignments @var array $syncStatus @var array $timeline @var array $fieldMap @var array $fieldGroups @var bool $hasNextGen @var bool $hasPowerSchool @var bool $psStale @var bool $idmOnly */
use App\View\Present;

$st = Present::status($p['status']);
$full = trim($p['first_name'] . ($p['middle_name'] ? ' ' . $p['middle_name'] : '') . ' ' . $p['last_name']);
$locked = (int) $p['username_locked'] === 1;
$dash = static fn($v): string => ($v === null || $v === '') ? '—' : (string) $v;

$lockSvg = '<svg class="lock" width="14" height="14" viewBox="0 0 18 18"><circle cx="9" cy="6.5" r="3.4" fill="none" stroke="currentColor" stroke-width="1.7"/><rect x="3.5" y="7.5" width="11" height="8" rx="2" fill="currentColor"/></svg>';

$eventTitle = [
    'create' => 'Record created', 'update' => 'Record updated', 'disable' => 'Account disabled',
    'enable' => 'Account enabled', 'terminate' => 'Record terminated', 'convert' => 'Record converted',
    'merge' => 'Records merged', 'username_assigned' => 'Username assigned by OneSync',
];
?>
<div class="detail">
  <a class="back-link" href="<?= e(url('/people')) ?>">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 3L5 8l5 5"/></svg> All people
  </a>

  <div class="card card--pad" style="margin-bottom:18px;">
    <div class="detail-head">
      <div class="avatar-lg"><?= e(Present::initials($p['first_name'], $p['last_name'])) ?></div>
      <div style="flex:1; min-width:0;">
        <div class="detail-head__row">
          <h1><?= e($full) ?></h1>
          <span class="badge badge--<?= e($st['mod']) ?>"><span class="dot"></span><?= e($st['label']) ?></span>
          <span class="type-pill"><?= e(Present::type($p['person_type'])) ?></span>
        </div>
        <div class="uuid-line">
          <span><?= e($p['person_uuid']) ?></span>
          <span class="sep">·</span><span>created <?= e($dash($p['created_at'])) ?></span>
          <span class="sep">·</span><span>updated <?= e($dash($p['updated_at'])) ?></span>
        </div>
      </div>
      <?php if (!empty($canEdit)): ?>
      <a class="btn btn--ghost" href="<?= e(url('/people/' . $p['person_id'] . '/edit')) ?>">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 2.5l2 2L6 12l-2.7.7L4 10z"/></svg>
        Edit record
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid-2">
    <div class="col-stack">
      <!-- Identity -->
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Identity</h2>
          <span class="panel__note">— minted by OneSync, read-only here</span>
        </div>
        <div class="kv-grid kv-grid--2">
          <div>
            <div class="kv__label" style="text-transform:uppercase; letter-spacing:.4px;">Username</div>
            <div class="id-field">
              <?php if ($p['username']): ?><span class="mono"><?= e($p['username']) ?></span>
              <?php else: ?><span class="mono value-missing">— not set —</span><?php endif; ?>
              <?php if ($locked): ?><?= $lockSvg ?><?php endif; ?>
            </div>
          </div>
          <div>
            <div class="kv__label" style="text-transform:uppercase; letter-spacing:.4px;">Email</div>
            <div class="id-field">
              <?php if ($p['email']): ?><span class="mono"><?= e($p['email']) ?></span>
              <?php else: ?><span class="mono value-missing">— not set —</span><?php endif; ?>
              <?php if ($locked): ?><?= $lockSvg ?><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="identity-note">
          <?= $lockSvg ?>
          <?php if ($p['username']): ?>
            Locked — managed by OneSync. Changes here are ignored by sync.
          <?php else: ?>
            Not yet assigned. OneSync will mint these once the record is activated.
          <?php endif; ?>
        </div>
      </div>

      <!-- Demographics -->
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Demographics</h2>
          <span class="pii-tag">SENSITIVE PII</span>
        </div>
        <div class="kv-grid">
          <div><div class="kv__label">Legal name</div><div class="kv__value"><?= e(trim($p['first_name'] . ' ' . ($p['middle_name'] ?? '') . ' ' . $p['last_name'])) ?></div></div>
          <div><div class="kv__label">Preferred name</div><div class="kv__value"><?= e($dash($p['preferred_name'])) ?></div></div>
          <div><div class="kv__label">Date of birth</div><div class="kv__value mono"><?= e($dash($p['dob'])) ?></div></div>
          <div><div class="kv__label">Gender</div><div class="kv__value"><?= e($dash($p['gender'])) ?></div></div>
          <div><div class="kv__label">Ethnicity (raw → ALSDE)</div><div class="kv__value"><?= e($dash($p['ethnicity_source'])) ?> <span class="muted">→</span> <span class="mono"><?= e($dash($p['ethnicity_code'])) ?></span></div></div>
          <div><div class="kv__label">ALSDE ID</div><div class="kv__value mono"><?= e($dash($p['alsde_id'])) ?></div></div>
          <div><div class="kv__label">Employee ID</div><div class="kv__value mono"><?= e($dash($p['employee_id'])) ?></div></div>
          <div><div class="kv__label">Hire date</div><div class="kv__value mono"><?= e($dash($p['hire_date'])) ?></div></div>
          <div><div class="kv__label">End date</div><div class="kv__value mono"><?= e($dash($p['end_date'])) ?></div></div>
        </div>
      </div>

      <!-- Assignments -->
      <div class="panel">
        <div class="panel__head">
          <h2 class="panel__title">Assignments</h2>
          <span class="panel__note">— multi-location</span>
        </div>
        <table class="assign-table">
          <thead><tr><th>School</th><th>Title</th><th>Source</th><th>FTE</th><th>Dates</th></tr></thead>
          <tbody>
            <?php foreach ($assignments as $a): ?>
            <tr>
              <td>
                <span style="color:#22343F; font-weight:500;"><?= e($a['school_name']) ?></span>
                <?php if ((int) $a['is_primary'] === 1): ?> <span class="pill-primary">PRIMARY</span><?php endif; ?>
              </td>
              <td><?= e($dash($a['title'])) ?></td>
              <td><span class="src-tag"><?= e(\App\View\Present::sourceSystem((string) ($a['source'] ?? ''))) ?></span></td>
              <td class="mono"><?= e($dash($a['fte'])) ?></td>
              <td class="mono" style="font-size:11.5px; color:#7B8E9B;"><?= e($dash($a['effective_date'])) ?> → <?= e($a['end_date'] ?: 'present') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($assignments === []): ?><tr><td colspan="5" class="muted" style="padding:12px 0;">No assignments on record.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col-stack">
      <!-- Source ID crosswalk -->
      <div class="panel">
        <h2 class="panel__title">Source ID crosswalk</h2>
        <p class="panel__note" style="margin:4px 0 14px;">Every system that knows this person, keyed to one golden record.</p>
        <div class="crosswalk">
          <?php foreach ($sourceIds as $s): ?>
          <div class="xwalk-row">
            <span class="xwalk__sys"><?= e(Present::sourceSystem($s['system'])) ?></span>
            <span class="xwalk__id"><?= e($s['source_key']) ?></span>
            <?php if ((int) $s['is_active'] !== 1): ?><span class="xwalk__inactive">inactive</span><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php if ($sourceIds === []): ?><div class="muted" style="font-size:12.5px;">No source IDs linked yet.</div><?php endif; ?>
        </div>
      </div>

      <!-- Provisioning status (one per destination) -->
      <div class="panel">
        <h2 class="panel__title">Provisioning status</h2>
        <p class="panel__note" style="margin:4px 0 14px;">Per-destination state from OneSync (AD · Google · Raptor · PowerSchool).</p>
        <div class="sync-grid">
          <?php foreach ($syncStatus as $sy):
              $reported = !empty($sy['reported']);
              $mod = $reported ? Present::syncMod($sy['last_status']) : 'muted';
          ?>
          <div class="sync-card<?= $mod === 'fail' ? ' sync-card--fail' : '' ?>">
            <div class="sync-card__head">
              <span class="sync-card__dest"><?= e($sy['label'] ?? $sy['destination']) ?></span>
              <span class="sync-badge sync-badge--<?= e($mod) ?>"><?= e($reported ? $dash($sy['last_status']) : 'Not synced') ?></span>
            </div>
            <?php if ($reported): $stale = ($sy['fresh_state'] ?? '') === 'stale'; ?>
              <div class="sync-card__meta">
                <?= e($dash($sy['last_action'])) ?> · <?= e($sy['fresh_label'] ?? $dash($sy['last_sync_at'])) ?>
                <?php if ($stale): ?><span class="sync-badge sync-badge--fail" style="margin-left:6px;">stale</span><?php endif; ?>
              </div>
              <?php if ($mod === 'fail' && $sy['message']): ?><div class="sync-card__msg"><?= e($sy['message']) ?></div><?php endif; ?>
            <?php else: ?>
              <div class="sync-card__meta">awaiting OneSync</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>

  <!-- Source field reconciliation: NextGen value vs PowerSchool value -->
  <?php
    // Reconciliation verdict styling + summary counts.
    $verdict = [
        'match'   => ['#1F7A3D', '#E7F4EC', '✓ match'],
        'differ'  => ['#B42318', '#FDECEA', '✗ differs'],
        'missing' => ['#B45309', '#FCF3E6', 'missing'],
        'ng_only' => ['#7B8E9B', '#F4F7F9', 'NextGen only'],
        'ps_only' => ['#3D6478', '#EAF1F5', 'PowerSchool only'],
        'info'    => ['#7B8E9B', '#F4F7F9', 'info'],
    ];
    $differs = count(array_filter($fieldMap, static fn($f) => in_array($f['state'], ['differ', 'missing'], true)));
    $ngLabel = $idmOnly ? 'IDM (current)' : 'NextGen';
  ?>
  <div class="panel" style="margin-top:18px;">
    <div class="panel__head">
      <h2 class="panel__title">Source field reconciliation</h2>
      <span class="panel__note">— <?= e($ngLabel) ?> vs PowerSchool · <a href="<?= e(url('/reference', ['tab' => 'mapping'])) ?>">field crosswalk</a></span>
    </div>

    <?php if ($idmOnly): ?>
      <div class="identity-note" style="margin-bottom:14px;">
        Maintained in <strong>IDM only</strong> (intern / contractor) — there is no NextGen or PowerSchool source to compare against. The NextGen column shows the current record values.
      </div>
    <?php elseif ($psStale): ?>
      <div class="identity-note" style="margin-bottom:14px; color:#B45309;">
        A PowerSchool record is linked, but its field values weren't captured at import time (an older or failed pull). Re-run the PowerSchool import (<span class="mono">php bin/import_powerschool.php</span>) to compare field by field.
      </div>
    <?php elseif (!$hasPowerSchool): ?>
      <div class="identity-note" style="margin-bottom:14px;">
        No PowerSchool record is linked yet, so values can't be verified. NextGen is the source that drives provisioning.
      </div>
    <?php elseif (!$hasNextGen): ?>
      <div class="identity-note" style="margin-bottom:14px;">
        No NextGen record is linked — values shown are from PowerSchool only.
      </div>
    <?php elseif ($differs > 0): ?>
      <div class="identity-note" style="margin-bottom:14px; color:#B42318;">
        <strong><?= e((string) $differs) ?></strong> field<?= $differs === 1 ? '' : 's' ?> differ between NextGen and PowerSchool — review before the next OneSync run.
      </div>
    <?php else: ?>
      <div class="identity-note" style="margin-bottom:14px; color:#1F7A3D;">
        NextGen and PowerSchool agree on every comparable field.
      </div>
    <?php endif; ?>

    <table class="assign-table">
      <thead><tr><th>Field</th><th><?= e($ngLabel) ?></th><th>PowerSchool</th><th>Verify</th></tr></thead>
      <tbody>
        <?php foreach ($fieldGroups as $gkey => $glabel):
            $groupRows = array_values(array_filter($fieldMap, static fn($f) => $f['group'] === $gkey));
            if ($groupRows === []) { continue; } ?>
          <tr><td colspan="4" style="font-weight:600; color:#22343F; background:#F4F7F9; font-size:11.5px; text-transform:uppercase; letter-spacing:.4px;"><?= e($glabel) ?></td></tr>
          <?php foreach ($groupRows as $f):
              $isDiff = in_array($f['state'], ['differ', 'missing'], true);
              $v = $verdict[$f['state']] ?? null; ?>
          <tr<?= $isDiff ? ' style="background:#FFF8F7;"' : '' ?>>
            <td>
              <span style="color:#22343F; font-weight:500;"><?= e($f['label']) ?></span>
              <?php if ($f['pii']): ?> <span class="pii-tag">PII</span><?php endif; ?>
              <div class="mono" style="font-size:10.5px; color:#9AA9B4;"><?= e($f['nextgen'] ?? '—') ?> · <?= e($f['powerschool'] ?? '—') ?></div>
            </td>
            <td><?= $f['ngValue'] === '' ? '<span class="value-missing">—</span>' : e($f['ngValue']) ?></td>
            <td><?= $f['psValue'] === '' ? '<span class="value-missing">—</span>' : e($f['psValue']) ?></td>
            <td>
              <?php if ($v !== null): ?>
                <span style="display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; color:<?= e($v[0]) ?>; background:<?= e($v[1]) ?>;"><?= e($v[2]) ?></span>
              <?php else: ?>
                <span class="value-missing">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Live Active Directory verification (Adaxes REST API) -->
  <?php
    $adConfigured = !empty($adaxes['configured']);
    $adComparison = $adaxes['comparison'] ?? [];
    $adDiffers = \App\Service\AdaxesService::diffCount($adComparison);
    $adBy = $adaxes['by'] ?? null;
    $adId = $adaxes['identifier'] ?? null;
  ?>
  <div class="panel" style="margin-top:18px;">
    <div class="panel__head">
      <h2 class="panel__title">Active Directory <span class="muted" style="font-weight:500;">(live)</span></h2>
      <span class="panel__note">— queried from Adaxes vs the golden record</span>
    </div>

    <?php if (!$adConfigured): ?>
      <div class="identity-note" style="margin-bottom:0;">
        Live AD verification is <strong>off</strong>. Set <span class="mono">ADAXES_BASE_URL</span>, <span class="mono">ADAXES_USERNAME</span> and <span class="mono">ADAXES_PASSWORD</span> (read-only service account) to compare each account against Active Directory here.
      </div>
    <?php elseif (empty($adaxes['ok'])): ?>
      <div class="identity-note" style="margin-bottom:0; color:#B42318;">
        Could not reach Active Directory: <?= e((string) ($adaxes['error'] ?? 'unknown error')) ?>
      </div>
    <?php elseif (empty($adaxes['found'])): ?>
      <div class="identity-note" style="margin-bottom:0; color:#B45309;">
        <?php if ($adBy === null): ?>
          No AD identifier on file and no username has been minted yet, so there is no account to verify.
        <?php else: ?>
          No Active Directory account matched this person (looked up by <span class="mono"><?= e($adBy) ?></span> = <span class="mono"><?= e((string) $adId) ?></span>).
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php if ($adDiffers > 0): ?>
        <div class="identity-note" style="margin-bottom:14px; color:#B42318;">
          <strong><?= e((string) $adDiffers) ?></strong> field<?= $adDiffers === 1 ? '' : 's' ?> differ between the golden record and Active Directory — review before the next OneSync run.
        </div>
      <?php else: ?>
        <div class="identity-note" style="margin-bottom:14px; color:#1F7A3D;">
          The golden record and Active Directory agree on every comparable field.
        </div>
      <?php endif; ?>
      <p class="panel__note" style="margin:0 0 12px;">Matched by <span class="mono"><?= e((string) $adBy) ?></span> = <span class="mono"><?= e((string) $adId) ?></span>.</p>

      <table class="assign-table">
        <thead><tr><th>Field</th><th>Golden record</th><th>Active Directory</th><th>Verify</th></tr></thead>
        <tbody>
          <?php foreach ($adComparison as $f):
              $isDiff = in_array($f['state'], ['differ', 'missing'], true);
              $v = $verdict[$f['state']] ?? null; ?>
          <tr<?= $isDiff ? ' style="background:#FFF8F7;"' : '' ?>>
            <td><span style="color:#22343F; font-weight:500;"><?= e($f['label']) ?></span></td>
            <td><?= $f['golden'] === '' ? '<span class="value-missing">—</span>' : e($f['golden']) ?></td>
            <td><?= $f['ad'] === '' ? '<span class="value-missing">—</span>' : e($f['ad']) ?></td>
            <td>
              <?php if ($v !== null): ?>
                <span style="display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; color:<?= e($v[0]) ?>; background:<?= e($v[1]) ?>;"><?= e($v[2]) ?></span>
              <?php else: ?>
                <span class="value-missing">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Lifecycle & audit -->
  <div class="panel" style="margin-top:18px;">
    <h2 class="panel__title" style="margin-bottom:16px;">Lifecycle &amp; audit</h2>
    <div class="timeline">
      <?php foreach ($timeline as $i => $ev):
          $detail = $ev['detail'] ? json_decode((string) $ev['detail'], true) : null;
          $detailText = is_array($detail) ? ($detail['summary'] ?? $detail['text'] ?? '') : (string) ($ev['detail'] ?? '');
          $last = $i === array_key_last($timeline);
      ?>
      <div class="tl-item">
        <div class="tl-rail">
          <span class="tl-dot tl-dot--<?= e($ev['event_type']) ?>"></span>
          <?php if (!$last): ?><span class="tl-line"></span><?php endif; ?>
        </div>
        <div style="flex:1;">
          <div class="tl-title"><?= e($eventTitle[$ev['event_type']] ?? ucfirst($ev['event_type'])) ?></div>
          <?php if ($detailText !== ''): ?><div class="tl-detail"><?= e($detailText) ?></div><?php endif; ?>
          <div class="tl-meta"><?= e($dash($ev['actor'])) ?> · <?= e($dash($ev['occurred_at'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if ($timeline === []): ?><div class="muted" style="font-size:12.5px;">No lifecycle events recorded.</div><?php endif; ?>
    </div>
  </div>

  <!-- Notes -->
  <div class="panel" style="margin-top:18px;">
    <h2 class="panel__title" style="margin-bottom:12px;">Notes</h2>
    <p style="margin:0; font-size:13px; color:#3D5462; line-height:1.5;"><?= $p['notes'] ? e($p['notes']) : '<span class="muted">No notes.</span>' ?></p>
  </div>
</div>
