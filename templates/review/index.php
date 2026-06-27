<?php
/** @var array $cases @var int $selected @var ?array $detail @var string $csrf */
$basisLabel = static fn(string $b): string => match ($b) {
    'name+dob' => 'name + DOB', 'name_only' => 'name only',
    'employee_id' => 'employee ID', 'source_id' => 'source ID', default => $b,
};
$strength = static function (float $s): array {
    if ($s >= 85) return ['Strong', 'strong'];
    if ($s >= 70) return ['Moderate', 'mod'];
    return ['Weak', 'weak'];
};
?>
<div class="page-head">
  <div>
    <h1>Review queue</h1>
    <p>Incoming records that may match an existing person. A human decides — the system never auto-merges.</p>
  </div>
</div>

<?php if ($cases === []): ?>
  <div class="card"><div class="placeholder">
    <h2>Queue is clear 🎉</h2>
    <p>No pending matches to review. New ambiguous matches from the importers will appear here.</p>
    <p style="margin-top:14px;"><a class="btn btn--primary" href="<?= e(url('/people')) ?>">Go to People</a></p>
  </div></div>
<?php else: ?>
<div class="review">
  <!-- queue list -->
  <div class="card queue">
    <div class="queue__head">
      <span>Pending</span>
      <span class="muted" style="font-size:11px;">oldest first · <?= e(count($cases)) ?></span>
    </div>
    <?php foreach ($cases as $c): [$slabel, $smod] = $strength((float) $c['top_score']); ?>
      <a class="queue__item<?= (int) $c['staging_id'] === $selected ? ' is-active' : '' ?>" href="<?= e(url('/review', ['case' => $c['staging_id']])) ?>">
        <div class="queue__row">
          <span class="queue__name"><?= e($c['name']) ?></span>
          <span class="conf-pill conf--<?= e($smod) ?>"><?= e(round((float) $c['top_score'])) ?>%</span>
        </div>
        <div class="queue__meta"><?= e(ucfirst($c['system'])) ?> → <?= e($basisLabel($c['top_basis'])) ?></div>
        <?php if ($c['candidates'] > 1): ?><div class="queue__meta muted"><?= e($c['candidates']) ?> possible matches</div><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- comparison card -->
  <div class="card compare">
    <?php if ($detail === null): ?>
      <div class="placeholder"><p>Select a case from the queue.</p></div>
    <?php else: [$slabel, $smod] = $strength((float) $detail['score']); ?>
      <div class="compare__head">
        <div>
          <div class="compare__eyebrow">Potential match</div>
          <div class="compare__name"><?= e($detail['incoming']['name']) ?></div>
        </div>
        <div class="conf-box conf--<?= e($smod) ?>">
          <div class="conf-box__score"><?= e(round((float) $detail['score'])) ?>%</div>
          <div>
            <div class="conf-box__cap">confidence</div>
            <div class="conf-box__strength"><?= e($slabel) ?></div>
          </div>
        </div>
        <div>
          <div class="compare__eyebrow">match basis</div>
          <span class="basis-pill"><?= e($basisLabel($detail['basis'])) ?></span>
        </div>
      </div>

      <?php if ($detail['weak']): ?>
      <div class="warn-banner">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="#C0392B" stroke-width="1.7"><path d="M9 1.5L17 15.5H1L9 1.5z" stroke-linejoin="round"/><path d="M9 7v3.5" stroke-linecap="round"/><circle cx="9" cy="12.7" r=".6" fill="#C0392B" stroke="none"/></svg>
        <div>
          <div class="warn-banner__title">Weak match — name only.</div>
          <div class="warn-banner__body">Names matching is not enough to confirm identity. Verify DOB, employee ID, or another source before linking. Linking the wrong people merges two humans into one account.</div>
        </div>
      </div>
      <?php endif; ?>

      <div class="cmp-headers">
        <div></div>
        <div class="cmp-col"><span class="swatch swatch--in"></span> Incoming record <span class="muted"><?= e($detail['incoming']['source']) ?></span></div>
        <div class="cmp-col"><span class="swatch swatch--ex"></span> Existing person
          <a class="muted mono" href="<?= e(url('/people/' . $detail['candidate']['person_id'])) ?>" target="_blank" rel="noopener" title="Open person record"><?= e(substr($detail['candidate']['uuid'], 0, 8)) ?> ↗</a>
        </div>
      </div>
      <div class="cmp-rows">
        <?php foreach ($detail['rows'] as $r): ?>
        <div class="cmp-row cmp-row--<?= e($r['match']) ?>">
          <div class="cmp-label"><?= e($r['label']) ?></div>
          <div class="cmp-val mono"><?= e($r['a']) ?></div>
          <div class="cmp-val mono">
            <span><?= e($r['b']) ?></span>
            <span class="cmp-tag cmp-tag--<?= e($r['match']) ?>"><?= e($r['match'] === 'match' ? 'match' : ($r['match'] === 'diff' ? 'differs' : 'info')) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="compare__actions">
        <?php if (empty($canEdit)): ?>
          <div class="notice notice--info" style="width:100%;">You have read-only access. Confirming or rejecting a match requires an editor role.</div>
        <?php else: ?>
        <form method="post" action="<?= e(url('/review/confirm')) ?>">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="staging_id" value="<?= e($detail['staging_id']) ?>">
          <input type="hidden" name="candidate_person_id" value="<?= e($detail['candidate']['person_id']) ?>">
          <button type="submit" class="btn btn--link<?= $detail['weak'] ? ' btn--link-weak' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 11l4-4M6 5.5L4 7.5a3.2 3.2 0 004.5 4.5l2-2M12 12.5l2-2a3.2 3.2 0 00-4.5-4.5l-2 2"/></svg>
            Same person — link &amp; reuse account
          </button>
        </form>
        <form method="post" action="<?= e(url('/review/reject')) ?>">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="staging_id" value="<?= e($detail['staging_id']) ?>">
          <button type="submit" class="btn btn--ghost">
            <svg width="16" height="16" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M9 4v10M4 9h10"/></svg>
            Different people — create new
          </button>
        </form>
        <div class="compare__hint">Linking reuses the existing person's account — no duplicate is created in AD or Google.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
