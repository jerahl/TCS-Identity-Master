<?php
/** @var \Throwable|null $error */
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Error — TCS Identity Master</title>
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<div style="max-width:680px; margin:60px auto; padding:0 20px;">
  <div class="card card--pad">
    <h1 style="margin:0 0 8px; font-size:20px;">Something went wrong</h1>
    <p class="muted">The application hit an unexpected error. It has been logged. If this is a fresh setup, confirm the database is migrated and <code>.env</code> is configured.</p>
    <?php if ($error !== null): ?>
      <pre class="mono" style="margin-top:16px; padding:14px; background:#FEF6F6; border:1px solid #FBD5D5; border-radius:8px; font-size:12px; white-space:pre-wrap; color:#B23227;"><?= e($error->getMessage()) ?>

<?= e($error->getFile() . ':' . $error->getLine()) ?></pre>
    <?php endif; ?>
    <p style="margin-top:16px;"><a class="btn btn--primary" href="<?= e(url('/people')) ?>">Back to People</a></p>
  </div>
</div>
</body></html>
