<?php
/** @var array $keys @var ?array $allKeys @var ?array $newKey @var string $csrf @var bool $canAdmin */
?>
<div class="page-head">
  <div>
    <h1>API keys</h1>
    <p>Personal keys for programmatic access, including the <strong>Claude MCP</strong> connector. A key acts as
       <strong>you</strong> — it can only do what your role (<em><?= e(ucfirst((string) ($currentUser['role'] ?? ''))) ?></em>) allows.</p>
  </div>
</div>

<?php if (!empty($newKey)): ?>
<div class="card card--pad" style="margin-bottom:18px; border:1px solid var(--ok, #2f9e6f);">
  <div class="form-section" style="margin-top:0;">New key created — copy it now</div>
  <p class="muted" style="margin:0 0 10px;">This is the only time the full key is shown. Store it in your MCP client config, then it can't be retrieved again (only revoked).</p>
  <div class="mono" style="user-select:all; word-break:break-all; padding:12px 14px; background:rgba(0,0,0,.05); border-radius:8px; font-size:13px;"><?= e($newKey['plaintext']) ?></div>
  <p class="muted" style="font-size:11.5px; margin:10px 0 0;">Label: <?= e($newKey['label']) ?></p>
</div>
<?php endif; ?>

<div class="card card--pad" style="margin-bottom:18px;">
  <div class="form-section" style="margin-top:0;">Create a key</div>
  <form method="post" action="<?= e(url('/settings/api-keys/create')) ?>" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div style="flex:1; min-width:260px;">
      <label class="field-label">Label (what is this key for?)</label>
      <input class="field" type="text" name="label" required maxlength="120" placeholder="Claude Desktop — my laptop">
    </div>
    <button class="btn btn--primary" type="submit" style="height:38px;">Create key</button>
  </form>
</div>

<div class="card table-wrap">
  <table class="table">
    <thead><tr><th>Label</th><th>Prefix</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($keys as $k): ?>
      <tr>
        <td class="cell-name"><?= e($k['label']) ?></td>
        <td class="mono" style="font-size:12px;"><?= e($k['token_prefix']) ?>…</td>
        <td class="mono" style="font-size:12px;"><?= e($k['created_at']) ?></td>
        <td class="mono" style="font-size:12px;"><?= e($k['last_used_at'] ?? 'never') ?></td>
        <td>
          <?= $k['revoked_at'] === null
            ? '<span class="badge badge--active"><span class="dot"></span>Active</span>'
            : '<span class="badge badge--disabled"><span class="dot"></span>Revoked</span>' ?>
        </td>
        <td style="text-align:right;">
          <?php if ($k['revoked_at'] === null): ?>
          <form method="post" action="<?= e(url('/settings/api-keys/revoke')) ?>" style="margin:0;" onsubmit="return confirm('Revoke this key? Any client using it will stop working immediately.');">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="key_id" value="<?= e($k['id']) ?>">
            <button class="btn btn--ghost" type="submit" style="height:34px; padding:0 12px;">Revoke</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($keys === []): ?><tr><td colspan="6" class="empty">No keys yet. Create one above to connect Claude.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($allKeys !== null): ?>
<div class="page-head" style="margin-top:26px;">
  <div>
    <h1 style="font-size:18px;">All keys (admin)</h1>
    <p>Every user's keys. Revoke any that shouldn't be active.</p>
  </div>
</div>
<div class="card table-wrap">
  <table class="table">
    <thead><tr><th>Owner</th><th>Role</th><th>Label</th><th>Prefix</th><th>Last used</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($allKeys as $k): ?>
      <tr>
        <td class="cell-name"><?= e($k['owner_email']) ?></td>
        <td><?= e($k['owner_role']) ?></td>
        <td><?= e($k['label']) ?></td>
        <td class="mono" style="font-size:12px;"><?= e($k['token_prefix']) ?>…</td>
        <td class="mono" style="font-size:12px;"><?= e($k['last_used_at'] ?? 'never') ?></td>
        <td>
          <?= $k['revoked_at'] === null
            ? '<span class="badge badge--active"><span class="dot"></span>Active</span>'
            : '<span class="badge badge--disabled"><span class="dot"></span>Revoked</span>' ?>
        </td>
        <td style="text-align:right;">
          <?php if ($k['revoked_at'] === null): ?>
          <form method="post" action="<?= e(url('/settings/api-keys/revoke')) ?>" style="margin:0;" onsubmit="return confirm('Revoke this key?');">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="key_id" value="<?= e($k['id']) ?>">
            <button class="btn btn--ghost" type="submit" style="height:34px; padding:0 12px;">Revoke</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($allKeys === []): ?><tr><td colspan="7" class="empty">No API keys have been created yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
