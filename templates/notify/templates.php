<?php
/** @var array $docs @var string $csrf */
$labels = ['new_teacher' => 'New Teacher', 'non_instructional' => 'Non-Instructional Employee'];
?>
<div class="detail" style="max-width:900px;">
  <a class="back-link" href="<?= e(url('/logins')) ?>">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 3L5 8l5 5"/></svg> Back to Logins export
  </a>

  <div class="page-head">
    <div>
      <h1>Orientation checklist content</h1>
      <p>Edit the text and links used when generating each checklist. Account details are always filled in live.</p>
    </div>
  </div>

  <div class="notice notice--info" style="margin-bottom:18px;">
    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" stroke="#0B6075" stroke-width="1.7" style="flex:0 0 17px; margin-top:1px;"><circle cx="9" cy="6.5" r="3.4"/><rect x="3.5" y="7.5" width="11" height="8" rx="2"/></svg>
    <div>
      <strong>Formatting:</strong> start a section with <code>## Section name</code>, and each step with
      <code>- step text</code>. Add links as <code>[label](https://example.com)</code> (only <code>http</code>/<code>https</code>
      links become clickable). Placeholders <code>{name}</code>, <code>{username}</code>, <code>{email}</code>,
      <code>{employeeid}</code>, <code>{school}</code>, <code>{position}</code>, <code>{start_date}</code>,
      <code>{temp_password}</code> are replaced per person. The temporary password OneSync delivered also
      appears automatically in the &ldquo;Your account&rdquo; box; <code>{temp_password}</code> is blank when
      none has been delivered yet.
    </div>
  </div>

  <?php foreach ($docs as $d): ?>
  <form method="post" action="<?= e(url('/notify/templates/save')) ?>" class="card card--pad" style="margin-bottom:18px;">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="doc" value="<?= e($d['doc']) ?>">

    <div class="form-section" style="margin-top:0; display:flex; align-items:center; gap:10px;">
      <?= e($labels[$d['doc']] ?? $d['doc']) ?>
      <?php if (!empty($d['is_default'])): ?>
        <span class="type-pill">built-in default</span>
      <?php else: ?>
        <span class="muted" style="font-weight:400; font-size:11.5px;">edited<?= $d['updated_by'] ? ' by ' . e($d['updated_by']) : '' ?><?= $d['updated_at'] ? ' · ' . e($d['updated_at']) : '' ?></span>
      <?php endif; ?>
    </div>

    <div style="margin-bottom:12px;">
      <label class="field-label">Heading *</label>
      <input class="field" name="heading" value="<?= e($d['heading']) ?>" required>
    </div>
    <div style="margin-bottom:12px;">
      <label class="field-label">Intro paragraph</label>
      <textarea class="field" name="intro" style="min-height:64px; padding:10px 12px; height:auto;"><?= e($d['intro']) ?></textarea>
    </div>
    <div>
      <label class="field-label">Body (sections &amp; steps)</label>
      <textarea class="field mono" name="body" style="min-height:260px; padding:10px 12px; height:auto; font-size:12.5px;"><?= e($d['body']) ?></textarea>
    </div>

    <div style="display:flex; align-items:center; gap:12px; margin-top:16px; padding-top:16px; border-top:1px solid #EDF1F3;">
      <button class="btn btn--primary" type="submit" name="action" value="save" style="height:40px;">Save changes</button>
      <button class="btn btn--ghost" type="submit" name="action" value="reset" style="height:40px;">Reset to default</button>
    </div>
  </form>
  <?php endforeach; ?>
</div>
