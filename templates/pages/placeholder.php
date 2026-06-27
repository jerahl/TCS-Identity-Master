<?php /** @var string $heading @var string $feature @var int $milestone */ ?>
<div class="page-head">
  <div>
    <h1><?= e($heading) ?></h1>
    <p>This screen is part of a later milestone.</p>
  </div>
</div>
<div class="card">
  <div class="placeholder">
    <h2><?= e($feature) ?> — coming in Milestone <?= e($milestone) ?></h2>
    <p>Milestone 2 ships the People list and Person detail (read-only). This area is designed but not yet wired up.</p>
    <p style="margin-top:14px;"><a class="btn btn--primary" href="<?= e(url('/people')) ?>">Go to People</a></p>
  </div>
</div>
