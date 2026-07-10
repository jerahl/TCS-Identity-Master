<?php
/** @var list<array<string,mixed>> $templates @var string $csrf */
?>
<div class="page-head">
  <div>
    <h1>Email templates</h1>
    <p>Subject and body of the emails IDM sends for the rename / alias lifecycle. Use <span class="mono">{placeholder}</span>
      tokens (listed under each template) — they're filled in when the email is sent. Blank fields fall back to the
      built-in default; <em>Reset</em> reverts a template to that default. Changes are audited.</p>
  </div>
</div>

<div class="tabs" style="margin-bottom:16px;">
  <a class="tab" href="<?= e(url('/settings/config')) ?>">Settings</a>
  <a class="tab is-on" href="<?= e(url('/settings/email-templates')) ?>">Email templates</a>
</div>

<div class="panel" style="margin-bottom:16px;">
  <p class="panel__note" style="margin:0;">
    These are the <strong>rename workflow</strong> emails: the upcoming-change notice (sent on approval), the
    change-complete confirmation (sent at cutover), and the alias removal reminder / removed notices.
    Recipients are fixed (employee, school principal, IT) — this page controls the wording only.
  </p>
</div>

<?php foreach ($templates as $t): ?>
<form method="post" action="<?= e(url('/settings/email-templates/save')) ?>" class="card" style="margin-bottom:18px; padding:16px;">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="key" value="<?= e((string) $t['key']) ?>">

  <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap;">
    <div>
      <h2 class="panel__title" style="margin:0 0 2px;"><?= e((string) $t['label']) ?></h2>
      <p class="panel__note" style="margin:0;"><?= e((string) $t['description']) ?></p>
    </div>
    <div class="muted" style="font-size:11px; text-align:right;">
      <?php if (!empty($t['is_default'])): ?>
        using built-in default
      <?php else: ?>
        edited<?= $t['updated_by'] ? ' by ' . e((string) $t['updated_by']) : '' ?><?= $t['updated_at'] ? ' · ' . e(substr((string) $t['updated_at'], 0, 16)) : '' ?>
      <?php endif; ?>
    </div>
  </div>

  <label class="kv__label" style="display:block; margin:12px 0 4px; text-transform:uppercase; letter-spacing:.4px;">Subject</label>
  <input class="input" type="text" name="subject" value="<?= e((string) $t['subject']) ?>" style="width:100%; box-sizing:border-box;">

  <label class="kv__label" style="display:block; margin:12px 0 4px; text-transform:uppercase; letter-spacing:.4px;">Body</label>
  <textarea class="input mono" name="body" rows="10" style="width:100%; box-sizing:border-box; font-size:13px;"><?= e((string) $t['body']) ?></textarea>

  <div class="muted" style="font-size:12px; margin-top:6px;">
    Placeholders:
    <?php foreach ((array) ($t['placeholders'] ?? []) as $ph): ?><span class="mono">{<?= e((string) $ph) ?>}</span>&nbsp;<?php endforeach; ?>
  </div>

  <div style="display:flex; gap:10px; margin-top:12px;">
    <button type="submit" class="btn btn--primary btn--sm">Save</button>
    <button type="submit" name="reset" value="1" class="btn btn--sm"
      onclick="return confirm('Revert this template to the built-in default?');">Reset to default</button>
  </div>
</form>
<?php endforeach; ?>
