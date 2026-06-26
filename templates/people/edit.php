<?php
/** @var array $p @var array $values @var array $schools @var string $error @var string $csrf */
use App\View\Present;

$v = static fn(string $k): string => e((string) ($values[$k] ?? ''));
$sel = static fn(string $k, string $opt): string => (string) ($values[$k] ?? '') === $opt ? ' selected' : '';
$types = ['faculty' => 'Faculty', 'staff' => 'Staff', 'contractor' => 'Contractor', 'sub' => 'Substitute', 'intern' => 'Intern', 'other' => 'Other'];
$statuses = ['pending' => 'Pending', 'active' => 'Active', 'disabled' => 'Disabled', 'terminated' => 'Terminated'];
$st = Present::status($p['status']);
?>
<div class="detail" style="max-width:840px;">
  <a class="back-link" href="<?= e(url('/people/' . $p['person_id'])) ?>">
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M10 3L5 8l5 5"/></svg> Back to record
  </a>

  <div class="page-head">
    <div>
      <h1>Edit record</h1>
      <p><?= e(trim($p['first_name'] . ' ' . $p['last_name'])) ?> · <span class="mono"><?= e($p['person_uuid']) ?></span></p>
    </div>
  </div>

  <div class="notice notice--info" style="margin-bottom:18px;">
    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" stroke="#0B6075" stroke-width="1.7" style="flex:0 0 17px; margin-top:1px;"><circle cx="9" cy="6.5" r="3.4"/><rect x="3.5" y="7.5" width="11" height="8" rx="2"/></svg>
    <div>Username and email are managed by OneSync and can't be edited here<?= $p['username'] ? ' (current: <strong>' . e($p['username']) . '</strong>)' : '' ?>. Every change here is written to the audit log.</div>
  </div>

  <form method="post" action="<?= e(url('/people/' . $p['person_id'] . '/edit')) ?>" class="card card--pad">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="form-section" style="margin-top:0;">Classification</div>
    <div class="form-grid form-grid--3">
      <div><label class="field-label">Type *</label>
        <select class="field" name="person_type">
          <?php foreach ($types as $val => $label): ?><option value="<?= e($val) ?>"<?= $sel('person_type', $val) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="field-label">Status *</label>
        <select class="field" name="status">
          <?php foreach ($statuses as $val => $label): ?><option value="<?= e($val) ?>"<?= $sel('status', $val) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="field-label">Primary school</label>
        <select class="field" name="primary_school_id">
          <option value="">—</option>
          <?php foreach ($schools as $s): ?><option value="<?= e($s['school_id']) ?>"<?= $sel('primary_school_id', (string) $s['school_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
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

    <div class="form-section">Notes</div>
    <textarea class="field" name="notes" style="min-height:80px; padding:10px 12px; height:auto;"><?= $v('notes') ?></textarea>

    <div style="display:flex; align-items:center; gap:12px; margin-top:18px; padding-top:18px; border-top:1px solid #EDF1F3;">
      <button class="btn btn--primary" type="submit" style="height:42px;">Save changes</button>
      <a class="btn btn--ghost" href="<?= e(url('/people/' . $p['person_id'])) ?>" style="height:42px;">Cancel</a>
      <?php if ($error !== ''): ?><span style="color:#C0392B; font-size:12.5px;"><?= e($error) ?></span><?php endif; ?>
    </div>
  </form>
</div>
