<?php
/** @var array $schools @var array $old @var string $error @var string $csrf */
$v = static fn(string $k): string => e((string) ($old[$k] ?? ''));
$sel = static fn(string $k, string $opt, string $default = ''): string => (string) ($old[$k] ?? $default) === $opt ? ' selected' : '';
$types = ['faculty' => 'Faculty', 'staff' => 'Staff', 'contractor' => 'Contractor', 'sub' => 'Substitute', 'intern' => 'Intern', 'other' => 'Other'];
?>
<div class="detail" style="max-width:840px;">
  <div class="page-head">
    <div>
      <h1>Add person (manual)</h1>
      <p>For people not in HR — long-term subs, contractors, interns. The record starts <strong>Pending</strong>.</p>
    </div>
  </div>

  <div class="notice notice--info" style="margin-bottom:18px;">
    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" stroke="#0B6075" stroke-width="1.7" style="flex:0 0 17px; margin-top:1px;"><circle cx="9" cy="9" r="7.5"/><path d="M9 8v4.5M9 5.6v.2" stroke-linecap="round"/></svg>
    <div>Username and email are <strong>not set here</strong>. OneSync mints them after this record is activated, then locks them.</div>
  </div>

  <form method="post" action="<?= e(url('/add')) ?>" class="card card--pad">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="form-section" style="margin-top:0;">Classification</div>
    <div class="form-grid form-grid--3">
      <div><label class="field-label">Type *</label>
        <select class="field" name="person_type">
          <?php foreach ($types as $val => $label): ?><option value="<?= e($val) ?>"<?= $sel('person_type', $val, 'sub') ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="field-label">Primary school</label>
        <select class="field" name="school_id">
          <option value="">—</option>
          <?php foreach ($schools as $s): ?><option value="<?= e($s['school_id']) ?>"<?= $sel('school_id', (string) $s['school_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-section">Demographics <span class="pii-tag">SENSITIVE PII</span></div>
    <div class="form-grid form-grid--3">
      <div><label class="field-label">First name *</label><input class="field" name="first_name" value="<?= $v('first_name') ?>" required></div>
      <div><label class="field-label">Middle</label><input class="field" name="middle_name" value="<?= $v('middle_name') ?>"></div>
      <div><label class="field-label">Last name *</label><input class="field" name="last_name" value="<?= $v('last_name') ?>" required></div>
      <div><label class="field-label">Preferred name</label><input class="field" name="preferred_name" value="<?= $v('preferred_name') ?>"></div>
      <div><label class="field-label">Date of birth</label><input class="field mono" name="dob" value="<?= $v('dob') ?>" placeholder="YYYY-MM-DD"></div>
      <div><label class="field-label">Gender</label><input class="field" name="gender" value="<?= $v('gender') ?>"></div>
      <div><label class="field-label">Ethnicity (raw value)</label><input class="field" name="ethnicity_source" value="<?= $v('ethnicity_source') ?>" placeholder="e.g. White"></div>
      <div><label class="field-label">ALSDE ID</label><input class="field mono" name="alsde_id" value="<?= $v('alsde_id') ?>"></div>
      <div><label class="field-label">Employee ID</label><input class="field mono" name="employee_id" value="<?= $v('employee_id') ?>"></div>
    </div>
    <p class="muted" style="font-size:11.5px; margin:6px 0 0;">ALSDE ethnicity code is resolved from the raw value via the ethnicity map on save.</p>

    <div class="form-section">Primary assignment</div>
    <div class="form-grid" style="grid-template-columns:1fr 80px;">
      <div><label class="field-label">Title</label><input class="field" name="title" value="<?= $v('title') ?>"></div>
      <div><label class="field-label">FTE</label><input class="field mono" name="fte" value="<?= $v('fte') ?>" placeholder="1.0"></div>
    </div>

    <div class="form-section">Board approval</div>
    <div class="form-grid form-grid--3">
      <div><label class="field-label">Approval date</label><input class="field mono" name="board_approval_date" value="<?= $v('board_approval_date') ?>" placeholder="YYYY-MM-DD"></div>
      <div style="grid-column:span 2;"><label class="field-label">Note</label><input class="field" name="board_approval_note" value="<?= $v('board_approval_note') ?>" placeholder="e.g. Agenda item 4.2, or &ldquo;pending&rdquo;"></div>
    </div>
    <p class="muted" style="font-size:11.5px; margin:6px 0 0;">Recorded from the board agenda — no feed provides this. Included in the Logins export.</p>

    <div class="form-section">Notes</div>
    <textarea class="field" name="notes" style="min-height:80px; padding:10px 12px; height:auto;"><?= $v('notes') ?></textarea>

    <div style="display:flex; align-items:center; gap:12px; margin-top:18px; padding-top:18px; border-top:1px solid #EDF1F3;">
      <button class="btn btn--primary" type="submit" style="height:42px;">Create pending record</button>
      <a class="btn btn--ghost" href="<?= e(url('/people')) ?>" style="height:42px;">Cancel</a>
      <?php if ($error !== ''): ?><span style="color:#C0392B; font-size:12.5px;"><?= e($error) ?></span><?php endif; ?>
    </div>
  </form>
</div>
