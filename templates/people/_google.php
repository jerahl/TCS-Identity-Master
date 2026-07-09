<?php
/**
 * Inner content of the "Google Workspace (live · direct)" panel.
 *
 * Rendered two ways: server-side into the page when direct provisioning isn't
 * configured (nothing to look up, so no round trip), and on demand by
 * GET /people/{id}/google — the AJAX endpoint that person-live-panels.js swaps
 * into the loading placeholder so the detail page renders immediately instead of
 * blocking on the live Google correlation (GAM / Directory API).
 *
 * @var array  $google  the GoogleWorkspaceService::correlate() envelope
 * @var array  $verdict optional state→[color,bg,label] map (falls back below)
 * @var array  $p       person row (drives the write actions)
 * @var bool   $canEdit optional — whether the operator may run write actions
 * @var string $csrf    optional CSRF token for the action forms
 */
$p = $p ?? [];
$canEdit = !empty($canEdit);
$csrf = $csrf ?? '';

// Same state vocabulary the reconciliation panel uses; self-contained so the
// partial renders correctly whether or not the caller passed $verdict.
$verdict = $verdict ?? [
    'match'   => ['#1F7A3D', '#E7F4EC', '✓ match'],
    'differ'  => ['#B42318', '#FDECEA', '✗ differs'],
    'missing' => ['#B45309', '#FCF3E6', 'missing'],
    'ng_only' => ['#7B8E9B', '#F4F7F9', 'NextGen only'],
    'ps_only' => ['#3D6478', '#EAF1F5', 'PowerSchool only'],
    'info'    => ['#7B8E9B', '#F4F7F9', 'info'],
];

$gConfigured = !empty($google['configured']);
$gFound = !empty($google['found']);
$gComparison = $google['comparison'] ?? [];
$gDiffers = \App\Service\GoogleWorkspaceService::diffCount($gComparison);
$gBy = $google['by'] ?? null;
$gId = $google['identifier'] ?? null;
$gEmail = $google['primaryEmail'] ?? null;
$gSuspended = $google['suspended'] ?? null;
$gNameOnly = $gBy === 'name';                 // a suggestion, never auto-linked
$gLinked = $gBy === 'id';                      // already in the crosswalk
$gByLabel = ['id' => 'crosswalk id', 'email' => 'primary email', 'externalId' => 'employee id', 'name' => 'name'][$gBy] ?? (string) $gBy;
$gAction = static function (string $action, string $label, string $style) use ($p, $csrf): string {
    return '<form method="post" action="' . e(url('/people/' . $p['person_id'] . '/google/' . $action)) . '" style="display:inline;">'
         . '<input type="hidden" name="_csrf" value="' . e($csrf) . '">'
         . '<button type="submit" class="btn ' . $style . '" style="margin:0 6px 0 0;">' . e($label) . '</button></form>';
};
?>
<?php if (!$gConfigured): ?>
  <div class="identity-note" style="margin-bottom:0;">
    Direct Google provisioning is <strong>off</strong>. Set <span class="mono">GOOGLE_DIRECT_ENABLED=true</span> plus a service account (<span class="mono">GOOGLE_SA_KEY_FILE</span>) with domain-wide delegation and <span class="mono">GOOGLE_ADMIN_SUBJECT</span> to correlate and create / suspend Google accounts here.
  </div>
<?php elseif (empty($google['ok'])): ?>
  <div class="identity-note" style="margin-bottom:0; color:#B42318;">
    Could not reach Google: <?= e((string) ($google['error'] ?? 'unknown error')) ?>
  </div>
<?php elseif (!$gFound): ?>
  <div class="identity-note" style="margin-bottom:<?= !empty($canEdit) ? '14px' : '0' ?>; color:#B45309;">
    No Google account is linked or matched for this person.
    <?php if (trim((string) ($p['email'] ?? '')) === ''): ?>
      There is no golden email on file yet, so an account can't be created here (set the primary email first).
    <?php endif; ?>
  </div>
  <?php if (!empty($canEdit) && trim((string) ($p['email'] ?? '')) !== ''): ?>
    <?= $gAction('create', 'Create in Google', 'btn--primary') ?>
    <span class="panel__note">— creates <span class="mono"><?= e((string) $p['email']) ?></span> (active, password reset on first login).</span>
  <?php endif; ?>
<?php else: ?>
  <?php if ($gNameOnly && !$gLinked): ?>
    <div class="identity-note" style="margin-bottom:14px; color:#B45309;">
      A Google account matched by <strong>name only</strong> (<span class="mono"><?= e((string) $gId) ?></span>) — this is a suggestion, not an automatic link. Confirm it's the same person before linking.
    </div>
  <?php elseif ($gDiffers > 0): ?>
    <div class="identity-note" style="margin-bottom:14px; color:#B42318;">
      <strong><?= e((string) $gDiffers) ?></strong> field<?= $gDiffers === 1 ? '' : 's' ?> differ between the golden record and Google.
    </div>
  <?php else: ?>
    <div class="identity-note" style="margin-bottom:14px; color:#1F7A3D;">
      The golden record and Google agree on every comparable field.
    </div>
  <?php endif; ?>
  <p class="panel__note" style="margin:0 0 12px;">
    Matched by <span class="mono"><?= e($gByLabel) ?></span><?php if ($gEmail): ?> · <span class="mono"><?= e((string) $gEmail) ?></span><?php endif; ?>
    <?php if ($gSuspended !== null): ?> · <strong style="color:<?= $gSuspended ? '#B42318' : '#1F7A3D' ?>;"><?= $gSuspended ? 'suspended' : 'active' ?></strong><?php endif; ?>
  </p>

  <table class="assign-table">
    <thead><tr><th>Field</th><th>Golden record</th><th>Google Workspace</th><th>Verify</th></tr></thead>
    <tbody>
      <?php foreach ($gComparison as $f):
          $isDiff = in_array($f['state'], ['differ', 'missing'], true);
          $v = $verdict[$f['state']] ?? null; ?>
      <tr<?= $isDiff ? ' style="background:#FFF8F7;"' : '' ?>>
        <td><span style="color:#22343F; font-weight:500;"><?= e($f['label']) ?></span></td>
        <td><?= $f['golden'] === '' ? '<span class="value-missing">—</span>' : e($f['golden']) ?></td>
        <td><?= $f['google'] === '' ? '<span class="value-missing">—</span>' : e($f['google']) ?></td>
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

  <?php if (!empty($canEdit)): ?>
  <div style="margin-top:14px;">
    <?php if (!$gLinked): ?><?= $gAction('link', $gNameOnly ? 'Confirm & link this account' : 'Link account', 'btn--ghost') ?><?php endif; ?>
    <?php if (!$gNameOnly || $gLinked): ?>
      <?php if ($gDiffers > 0): ?><?= $gAction('push', 'Push changes to Google', 'btn--primary') ?><?php endif; ?>
      <?php if ($gSuspended === true): ?>
        <?= $gAction('restore', 'Restore (un-suspend)', 'btn--ghost') ?>
      <?php else: ?>
        <?= $gAction('suspend', 'Suspend account', 'btn--ghost') ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
