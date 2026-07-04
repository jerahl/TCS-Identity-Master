<?php
/** @var string $title @var string $body @var string $pdfUrl */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($title) ?></title>
  <style>
    :root { --ink:#1B2A33; --muted:#5B6E7B; --line:#D8E0E5; --accent:#0B6075; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Arial, sans-serif; color: var(--ink); background: #F2F5F7; line-height: 1.5; }
    .sheet { max-width: 800px; margin: 22px auto; background: #fff; padding: 40px 46px; box-shadow: 0 2px 14px rgba(20,40,55,.12); }
    .toolbar { max-width: 800px; margin: 16px auto 0; display: flex; gap: 10px; justify-content: flex-end; }
    .btn { font: inherit; font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: 7px; border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; }
    .btn--primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    h1 { font-size: 21px; margin: 0 0 2px; }
    .sub { color: var(--muted); font-size: 13px; margin: 0 0 20px; }
    .intro { font-size: 14px; margin: 0 0 18px; }
    .acct { border: 1px solid var(--line); border-radius: 9px; padding: 16px 18px; margin: 0 0 22px; background: #F8FBFC; }
    .acct h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: var(--accent); margin: 0 0 12px; }
    .kv { display: grid; grid-template-columns: 150px 1fr; gap: 6px 14px; font-size: 14px; }
    .kv dt { color: var(--muted); }
    .kv dd { margin: 0; font-weight: 600; }
    .mono { font-family: "Consolas", "SF Mono", monospace; }
    h2.section { font-size: 15px; margin: 24px 0 10px; padding-bottom: 6px; border-bottom: 2px solid var(--line); }
    ol.steps, ul.steps { margin: 0; padding-left: 0; list-style: none; }
    ol.steps li, ul.steps li { position: relative; padding: 9px 0 9px 30px; border-bottom: 1px solid #EEF2F4; font-size: 14px; }
    ol.steps li:before, ul.steps li:before { content: ""; position: absolute; left: 0; top: 11px; width: 15px; height: 15px; border: 1.6px solid #9DB0BC; border-radius: 4px; }
    a { color: var(--accent); }
    .note { font-size: 12px; color: var(--muted); margin-top: 18px; padding-top: 12px; border-top: 1px dashed var(--line); }
    .placeholder { background: #FFF7E6; border-radius: 3px; padding: 0 3px; }
    @media print {
      body { background: #fff; }
      .toolbar { display: none; }
      .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
      a { color: var(--ink); }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <?php if (!empty($pdfUrl)): ?><a class="btn btn--primary" href="<?= e($pdfUrl) ?>">Download PDF</a><?php endif; ?>
    <button class="btn" id="print-btn" type="button">Print</button>
  </div>
  <div class="sheet">
    <?= $body ?>
  </div>
  <script src="<?= e(asset('assets/js/notify-print.js')) ?>" defer></script>
</body>
</html>
