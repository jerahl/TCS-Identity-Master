<?php
/** @var array $groups @var string $csrf @var bool $canAdmin */
?>
<div class="page-head">
  <div>
    <h1>Configuration</h1>
    <p>Operational settings for AD provisioning — the Adaxes write knobs, OU/username placement, and group names.
      Changes take effect for the app and the CLI reconciler, layering over <span class="mono">.env</span> (a real
      environment variable still wins). Every change is audited.</p>
  </div>
</div>

<div class="tabs" style="margin-bottom:16px;">
  <a class="tab is-on" href="<?= e(url('/settings/config')) ?>">Settings</a>
  <a class="tab" href="<?= e(url('/settings/email-templates')) ?>">Email templates</a>
</div>

<div class="panel" style="margin-bottom:16px;">
  <p class="panel__note" style="margin:0;">
    <strong>Secrets are not editable here.</strong> Adaxes tokens, service-account passwords, and database / SAML /
    Google credentials stay in <span class="mono">.env</span> or the environment by design. A field marked
    <em>set by environment</em> is pinned in the environment and can't be overridden from this page.
  </p>
</div>

<form method="post" action="<?= e(url('/settings/config/save')) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <?php foreach ($groups as $group): ?>
  <div class="card" style="margin-bottom:16px;">
    <div style="padding:14px 16px; border-bottom:1px solid #E4EBF0;">
      <h2 class="panel__title" style="margin:0 0 2px;"><?= e($group['title']) ?></h2>
      <p class="panel__note" style="margin:0;"><?= e($group['help']) ?></p>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th style="width:34%;">Setting</th><th style="width:30%;">Value</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($group['fields'] as $f):
            $key = (string) $f['key'];
            $locked = !empty($f['envLocked']); ?>
          <tr>
            <td class="cell-name">
              <?= e($f['label']) ?>
              <div class="mono muted" style="font-size:11px;"><?= e($key) ?></div>
            </td>
            <td>
              <?php if ($f['type'] === 'bool'): ?>
                <label class="switch-label" style="display:inline-flex; align-items:center; gap:8px;">
                  <input type="checkbox" name="<?= e($key) ?>" value="true"
                    <?= in_array(strtolower((string) $f['value']), ['1','true','yes','on'], true) ? 'checked' : '' ?>
                    <?= $locked ? 'disabled' : '' ?>>
                  <span class="muted mono"><?= e($f['value'] !== '' ? $f['value'] : 'false') ?></span>
                </label>
              <?php else: ?>
                <input class="input mono" type="text" name="<?= e($key) ?>"
                  value="<?= e((string) $f['value']) ?>"
                  <?= $locked ? 'disabled' : '' ?>
                  style="width:100%; box-sizing:border-box;"
                  autocomplete="off" spellcheck="false">
              <?php endif; ?>
              <?php if ($locked): ?>
                <div class="muted" style="font-size:11px; margin-top:3px;">set by environment</div>
              <?php elseif (($f['stored'] ?? null) !== null): ?>
                <div class="muted" style="font-size:11px; margin-top:3px;">overridden here · blank to revert</div>
              <?php endif; ?>
            </td>
            <td class="muted" style="font-size:12px;"><?= e((string) ($f['help'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="display:flex; gap:10px; align-items:center; margin:16px 0 40px;">
    <button type="submit" class="btn btn--primary">Save configuration</button>
    <span class="muted" style="font-size:12px;">Applies to future runs. Dry-run the reconciler after changing write settings.</span>
  </div>
</form>
