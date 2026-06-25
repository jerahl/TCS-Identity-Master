<?php
/** @var string $tab @var array $schools @var array $ethnicity @var array $unmappedEth @var array $unmappedSchool */
?>
<div class="page-head">
  <div>
    <h1>Reference data</h1>
    <p>Maps that resolve incoming source codes. Unmapped values block clean provisioning — surface them first.</p>
  </div>
</div>

<div class="tabs">
  <a class="tab<?= $tab === 'schools' ? ' is-on' : '' ?>" href="<?= e(url('/reference', ['tab' => 'schools'])) ?>">Schools map</a>
  <a class="tab<?= $tab === 'ethnicity' ? ' is-on' : '' ?>" href="<?= e(url('/reference', ['tab' => 'ethnicity'])) ?>">Ethnicity map</a>
</div>

<?php if ($tab === 'schools'): ?>
  <div class="card table-wrap">
    <table class="table">
      <thead><tr><th>School</th><th>NextGen</th><th>PowerSchool</th><th>AD OU</th><th>Google OU</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($schools as $s): $bad = !$s['mapped']; ?>
        <tr<?= $bad ? ' class="row--warn"' : '' ?>>
          <td class="cell-name"><?= e($s['name']) ?></td>
          <td class="mono"><?= e($s['nextgen_code'] ?? '—') ?></td>
          <td class="mono"><?= e($s['powerschool_code'] ?? $s['ps_school_id'] ?? '—') ?></td>
          <td class="mono"<?= ($s['ad_ou'] ?? '') === '' ? ' style="color:#B45309;"' : '' ?>><?= e(($s['ad_ou'] ?? '') === '' ? '(unmapped)' : $s['ad_ou']) ?></td>
          <td class="mono"<?= ($s['google_ou'] ?? '') === '' ? ' style="color:#B45309;"' : '' ?>><?= e(($s['google_ou'] ?? '') === '' ? '(unmapped)' : $s['google_ou']) ?></td>
          <td><span class="badge badge--<?= $s['status'] === 'active' ? 'active' : 'disabled' ?>"><?= e(ucfirst($s['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($unmappedSchool !== []): ?>
  <div class="panel" style="margin-top:18px;">
    <h2 class="panel__title" style="margin-bottom:4px;">Unmapped school codes seen in feeds</h2>
    <p class="panel__note" style="margin-bottom:12px;">These appeared in staged rows but have no alias → people land at no school. Add an alias.</p>
    <div class="chips">
      <?php foreach ($unmappedSchool as $u): ?>
        <span class="warn-chip"><?= e(ucfirst($u['system'])) ?> <strong><?= e($u['code']) ?></strong> · <?= e($u['n']) ?>×</span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <div class="card table-wrap" style="max-width:640px;">
    <table class="table">
      <thead><tr><th>Source value</th><th>ALSDE code</th><th>Federal group</th></tr></thead>
      <tbody>
        <?php foreach ($ethnicity as $e): ?>
        <tr>
          <td><?= e($e['source_value']) ?></td>
          <td class="mono"><?= e($e['alsde_code']) ?></td>
          <td class="muted"><?= e($e['federal_group'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($unmappedEth !== []): ?>
  <div class="panel" style="margin-top:18px; max-width:640px;">
    <h2 class="panel__title" style="margin-bottom:4px;">Unmapped ethnicity values on records</h2>
    <p class="panel__note" style="margin-bottom:12px;">Seen on people but not in the map → no ALSDE code sent downstream. Add a mapping.</p>
    <div class="chips">
      <?php foreach ($unmappedEth as $u): ?>
        <span class="warn-chip"><strong><?= e($u['value']) ?></strong> · <?= e($u['n']) ?>×</span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
