<?php
/** @var array $p @var array $sourceIds @var array $assignments @var array $syncStatus @var array $timeline */
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
      <button class="btn btn--ghost" disabled title="Editing arrives in a later milestone" style="opacity:.55; cursor:not-allowed;">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 2.5l2 2L6 12l-2.7.7L4 10z"/></svg>
        Edit record
      </button>
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
          <thead><tr><th>School</th><th>Title</th><th>FTE</th><th>Dates</th></tr></thead>
          <tbody>
            <?php foreach ($assignments as $a): ?>
            <tr>
              <td>
                <span style="color:#22343F; font-weight:500;"><?= e($a['school_name']) ?></span>
                <?php if ((int) $a['is_primary'] === 1): ?> <span class="pill-primary">PRIMARY</span><?php endif; ?>
              </td>
              <td><?= e($dash($a['title'])) ?></td>
              <td class="mono"><?= e($dash($a['fte'])) ?></td>
              <td class="mono" style="font-size:11.5px; color:#7B8E9B;"><?= e($dash($a['effective_date'])) ?> → <?= e($a['end_date'] ?: 'present') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($assignments === []): ?><tr><td colspan="4" class="muted" style="padding:12px 0;">No assignments on record.</td></tr><?php endif; ?>
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

      <!-- Lifecycle & audit -->
      <div class="panel">
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
      <div class="panel">
        <h2 class="panel__title" style="margin-bottom:12px;">Notes</h2>
        <p style="margin:0; font-size:13px; color:#3D5462; line-height:1.5;"><?= $p['notes'] ? e($p['notes']) : '<span class="muted">No notes.</span>' ?></p>
      </div>
    </div>
  </div>
</div>
