<?php
/**
 * Inner content of the "Active Directory (live)" panel.
 *
 * Rendered two ways: server-side into the page when Adaxes isn't configured
 * (nothing to look up, so no round trip), and on demand by GET /people/{id}/adaxes
 * — the AJAX endpoint that person-adaxes.js swaps into the loading placeholder so
 * the detail page renders immediately instead of blocking on the AD call.
 *
 * @var array $adaxes  the AdaxesService::verify() envelope
 * @var array $verdict optional state→[color,bg,label] map (falls back below)
 * @var array $p       optional person row (drives the "accept as golden" action)
 * @var bool  $canEdit optional — whether the operator may write the golden record
 * @var string $csrf   optional CSRF token for the accept form
 */
use App\View\Present;

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

$adConfigured = !empty($adaxes['configured']);
$adComparison = $adaxes['comparison'] ?? [];
$adDiffers = \App\Service\AdaxesService::diffCount($adComparison);
$adBy = $adaxes['by'] ?? null;
$adId = $adaxes['identifier'] ?? null;
?>
<?php if (!$adConfigured): ?>
  <div class="identity-note" style="margin-bottom:0;">
    Live AD verification is <strong>off</strong>. Set <span class="mono">ADAXES_BASE_URL</span> plus a <span class="mono">ADAXES_TOKEN</span> (or <span class="mono">ADAXES_USERNAME</span> + <span class="mono">ADAXES_PASSWORD</span> for a read-only service account) to compare each account against Active Directory here.
  </div>
<?php elseif (empty($adaxes['ok'])): ?>
  <div class="identity-note" style="margin-bottom:0; color:#B42318;">
    Could not reach Active Directory: <?= e((string) ($adaxes['error'] ?? 'unknown error')) ?>
  </div>
<?php elseif (empty($adaxes['found'])): ?>
  <div class="identity-note" style="margin-bottom:0; color:#B45309;">
    <?php if ($adBy === null): ?>
      No AD identifier on file and no username/email/employee&nbsp;ID to search on, so there is no account to verify.
    <?php elseif ($adBy === 'objectGUID'): ?>
      No Active Directory account matched this person (looked up by <span class="mono">objectGUID</span> = <span class="mono"><?= e((string) $adId) ?></span>).
    <?php else: ?>
      No Active Directory account matched this person (searched <span class="mono"><?= e((string) $adId) ?></span>).
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

  <?php
    // "Accept AD as the golden record" — offered to an editor for a pending or
    // active person, whenever AD holds a Username/UPN/Email that DIFFERS from the
    // golden record (a blank golden value counts as differing). Accepting fills a
    // blank and overwrites a differing value so the record matches AD; the
    // username is locked, and a pending person is activated. Not offered on
    // disabled/terminated records.
    $adAttrs = $adaxes['attributes'] ?? [];
    $isPending = (string) ($p['status'] ?? '') === 'pending';
    $canAdopt = in_array((string) ($p['status'] ?? ''), ['pending', 'active'], true);
    $adoptable = [];
    foreach (['Username' => ['samaccountname', 'username'], 'UPN' => ['userprincipalname', 'upn'], 'Email' => ['mail', 'email']] as $lbl => $keys) {
        $adVal = trim((string) ($adAttrs[$keys[0]] ?? ''));
        $goldVal = trim((string) ($p[$keys[1]] ?? ''));
        if ($adVal !== '' && mb_strtolower($adVal) !== mb_strtolower($goldVal)) {
            $adoptable[] = $lbl;
        }
    }
  ?>
  <?php if ($canEdit && $canAdopt && $adoptable !== []): ?>
    <div class="identity-note" style="margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <form method="post" action="<?= e(url('/people/' . ($p['person_id'] ?? '') . '/adaxes/accept')) ?>" style="display:inline; margin:0;">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" title="Write the AD Username, UPN and Email to the golden record, overwriting any differing value<?= $isPending ? ' and activating this pending person' : '' ?>"
          style="cursor:pointer; font-size:11.5px; font-weight:600; color:#1F7A3D; background:#E7F4EC; border:1px solid #B7E0C6; border-radius:9px; padding:3px 12px;">
          Accept AD as golden record
        </button>
      </form>
      <span style="color:#52677A;">Adopts the AD <?= e(implode(', ', $adoptable)) ?> for this <?= $isPending ? 'pending ' : '' ?>person, overwriting any differing value — the username is locked<?= $isPending ? ' and the record is activated' : '' ?>.</span>
    </div>
  <?php endif; ?>

  <p class="panel__note" style="margin:0 0 12px;">
    <?php if ($adBy === 'objectGUID'): ?>
      Matched by <span class="mono">objectGUID</span> = <span class="mono"><?= e((string) $adId) ?></span>.
    <?php else: ?>
      Matched by directory search (<span class="mono"><?= e((string) $adId) ?></span>).
    <?php endif; ?>
  </p>

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
