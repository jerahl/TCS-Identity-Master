<?php /** @var string $message */ ?>
<div class="page-head"><div><h1>Not found</h1><p><?= e($message ?? 'That page does not exist.') ?></p></div></div>
<div class="card"><div class="placeholder">
  <h2>404</h2>
  <p style="margin-top:14px;"><a class="btn btn--primary" href="<?= e(url('/people')) ?>">Go to People</a></p>
</div></div>
