<?php
/**
 * PDF shell for Dompdf — self-contained, no toolbar/JS, no external fonts or
 * stylesheets (Dompdf renders offline). Mirrors the on-screen document styling
 * with a Dompdf-friendly CSS subset.
 * @var string $title @var string $body
 */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?></title>
  <style>
    @page { margin: 54px 48px; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1B2A33; line-height: 1.45; font-size: 12px; }
    h1 { font-size: 19px; margin: 0 0 2px; }
    .sub { color: #5B6E7B; font-size: 11px; margin: 0 0 16px; }
    .intro { font-size: 12px; margin: 0 0 14px; }
    .acct { border: 1px solid #D8E0E5; border-radius: 8px; padding: 12px 14px; margin: 0 0 18px; background: #F8FBFC; }
    .acct h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #0B6075; margin: 0 0 10px; }
    dl.kv { margin: 0; font-size: 12px; }
    dl.kv dt { float: left; clear: left; width: 120px; color: #5B6E7B; }
    dl.kv dd { margin: 0 0 4px 130px; font-weight: bold; }
    .mono { font-family: "DejaVu Sans Mono", monospace; }
    h2.section { font-size: 13px; margin: 18px 0 8px; padding-bottom: 5px; border-bottom: 2px solid #D8E0E5; }
    ul.steps { margin: 0; padding: 0; list-style: none; }
    ul.steps li { padding: 6px 0; font-size: 12px; }
    a { color: #0B6075; text-decoration: none; }
  </style>
</head>
<body>
  <?= $body ?>
</body>
</html>
