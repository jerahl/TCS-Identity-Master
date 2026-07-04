<?php
/**
 * Data-driven checklist body — shared by the HTML preview and the PDF.
 * @var array $person @var string $fullName @var string $school @var string $position
 * @var string $startDate @var array $tmpl @var array $vars
 */
use App\Service\NotifyTemplateService as T;
use App\View\View;

$sections = T::parseBody((string) ($tmpl['body'] ?? ''));
?>
<h1><?= T::renderText((string) $tmpl['heading'], $vars) ?></h1>
<p class="sub"><?= e($fullName) ?><?php if ($school !== ''): ?> · <?= e($school) ?><?php endif; ?><?php if ($position !== ''): ?> · <?= e($position) ?><?php endif; ?></p>

<?= View::partial('notify/_account', ['person' => $person, 'startDate' => $startDate]) ?>

<?php if (trim((string) ($tmpl['intro'] ?? '')) !== ''): ?>
<p class="intro"><?= T::renderText((string) $tmpl['intro'], $vars) ?></p>
<?php endif; ?>

<?php foreach ($sections as $sec): ?>
  <?php if ($sec['heading'] !== ''): ?><h2 class="section"><?= T::renderText($sec['heading'], $vars) ?></h2><?php endif; ?>
  <ul class="steps">
    <?php foreach ($sec['items'] as $item): ?>
    <li><?= T::renderItemHtml($item, $vars) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endforeach; ?>
