<?php
/** @var string $tab @var array $schools @var array $ethnicity @var array $positions @var array $unmappedEth @var array $unmappedSchool @var array $unmappedJobs @var array $fieldMap @var array $fieldGroups */
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
  <a class="tab<?= $tab === 'positions' ? ' is-on' : '' ?>" href="<?= e(url('/reference', ['tab' => 'positions'])) ?>">Positions map</a>
  <a class="tab<?= $tab === 'mapping' ? ' is-on' : '' ?>" href="<?= e(url('/reference', ['tab' => 'mapping'])) ?>">Field mapping</a>
  <a class="tab" href="<?= e(url('/reference/data-flow')) ?>" title="Interactive chart of the full pipeline: sources → IDM → OneSync → destinations">Data flow chart ↗</a>
</div>

<?php if ($tab === 'mapping'): ?>
  <div class="panel" style="margin-bottom:16px;">
    <h2 class="panel__title" style="margin-bottom:4px;">NextGen → PowerSchool field crosswalk</h2>
    <p class="panel__note" style="margin:0;">How each NextGen HR field corresponds to PowerSchool, and where it lands on the golden record. Date of birth and the Alabama State ID (ALSID) have no NextGen column — they are pulled from PowerSchool.</p>
  </div>
  <div class="card table-wrap">
    <table class="table">
      <thead><tr><th>Field</th><th>NextGen</th><th>PowerSchool</th><th>Golden record</th></tr></thead>
      <tbody>
        <?php foreach ($fieldGroups as $gkey => $glabel):
            $groupFields = array_values(array_filter($fieldMap, static fn($f) => $f['group'] === $gkey));
            if ($groupFields === []) { continue; } ?>
          <tr class="row--group"><td colspan="4" style="font-weight:600; color:#22343F; background:#F4F7F9;"><?= e($glabel) ?></td></tr>
          <?php foreach ($groupFields as $f): ?>
          <tr>
            <td class="cell-name">
              <?= e($f['label']) ?>
              <?php if ($f['pii']): ?><span class="pii-tag" style="margin-left:6px;">PII</span><?php endif; ?>
            </td>
            <td class="mono"<?= $f['nextgen'] === null ? ' style="color:#7B8E9B;"' : '' ?>><?= e($f['nextgen'] ?? '—') ?></td>
            <td class="mono"<?= $f['powerschool'] === null ? ' style="color:#7B8E9B;"' : '' ?>>
              <?= e($f['powerschool'] ?? '—') ?>
              <?php if ($f['origin'] === 'powerschool'): ?> <span class="src-tag">source</span><?php endif; ?>
            </td>
            <td class="mono muted"><?= e($f['golden'] ?? '— OneSync —') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'schools'): ?>
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

<?php elseif ($tab === 'positions'): ?>
  <div class="panel" style="margin-bottom:16px; max-width:640px;">
    <h2 class="panel__title" style="margin-bottom:4px;">Job code → person type</h2>
    <p class="panel__note" style="margin:0;">Classifies employees from the NextGen HR feed by their job code. A code mapped to <strong>Faculty</strong> imports as faculty; codes not in the map default to <strong>Staff</strong>, so it's enough to list the faculty codes.</p>
  </div>
  <div class="card table-wrap" style="max-width:640px;">
    <table class="table">
      <thead><tr><th>Job code</th><th>Person type</th><th>Description</th></tr></thead>
      <tbody>
        <?php if ($positions === []): ?>
        <tr><td colspan="3" class="muted">No position mappings yet — everyone imports as Staff. Seed db/seeds/position_type_map.csv and run bin/seed.php.</td></tr>
        <?php endif; ?>
        <?php foreach ($positions as $p): ?>
        <tr>
          <td class="mono"><?= e($p['job_code']) ?></td>
          <td><span class="badge badge--<?= $p['person_type'] === 'faculty' ? 'active' : 'disabled' ?>"><?= e(ucfirst($p['person_type'])) ?></span></td>
          <td class="muted"><?= e($p['description'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($unmappedJobs !== []): ?>
  <div class="panel" style="margin-top:18px; max-width:640px;">
    <h2 class="panel__title" style="margin-bottom:4px;">Job codes with no mapping</h2>
    <p class="panel__note" style="margin-bottom:12px;">Seen on assignments but not in the map — these people import as Staff. Fine if they are staff; add a mapping if any should be Faculty.</p>
    <div class="chips">
      <?php foreach ($unmappedJobs as $u): ?>
        <span class="warn-chip"><strong><?= e($u['code']) ?></strong><?= ($u['title'] ?? '') !== '' ? ' ' . e($u['title']) : '' ?> · <?= e($u['n']) ?>×</span>
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
