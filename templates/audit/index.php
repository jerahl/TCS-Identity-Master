<?php
/** @var array $rows @var int $total @var int $page @var int $perPage @var int $pages @var array $filters */

$entityOpts = ['all' => 'All entities', 'person' => 'Person', 'assignment' => 'Assignment', 'source_id' => 'Source ID', 'match' => 'Match', 'school' => 'School', 'config' => 'Config', 'user' => 'User'];
$actionOpts = ['all' => 'All actions', 'insert' => 'Insert', 'update' => 'Update', 'delete' => 'Delete', 'merge' => 'Merge', 'login' => 'Login', 'logout' => 'Logout'];
$actionMod = static fn(string $a): string => match ($a) {
    'insert', 'login', 'merge' => 'active',
    'update' => 'pending',
    'delete' => 'terminated',
    'logout' => 'disabled',
    default => 'disabled',
};
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);
    return $d === null ? (string) $json : (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
// Query base for pagination links (drop defaults).
$base = array_filter([
    'entity' => $filters['entity'] !== 'all' ? $filters['entity'] : null,
    'action' => $filters['action'] !== 'all' ? $filters['action'] : null,
    'actor'  => $filters['actor'] !== '' ? $filters['actor'] : null,
], static fn($v) => $v !== null);
$from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);
?>
<div class="page-head">
  <div>
    <h1>Audit log</h1>
    <p>Every mutation and every login/logout — actor, before/after, and when. <?= e($total) ?> entries.</p>
  </div>
</div>

<form class="filters" method="get" action="<?= e(url('/audit')) ?>">
  <select class="select" name="entity" onchange="this.form.submit()">
    <?php foreach ($entityOpts as $val => $label): ?><option value="<?= e($val) ?>"<?= $filters['entity'] === $val ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <select class="select" name="action" onchange="this.form.submit()">
    <?php foreach ($actionOpts as $val => $label): ?><option value="<?= e($val) ?>"<?= $filters['action'] === $val ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select>
  <input class="select" type="text" name="actor" value="<?= e($filters['actor']) ?>" placeholder="Actor (email / system:…)" style="min-width:220px;">
  <button class="btn btn--ghost" type="submit" style="height:36px;">Filter</button>
</form>

<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr><th>When</th><th>Actor</th><th>Entity</th><th>Action</th><th>Change</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
          $before = $pretty($r['before_json']);
          $after = $pretty($r['after_json']);
          $isPerson = $r['entity'] === 'person' && $r['entity_id'];
      ?>
      <tr>
        <td class="mono" style="white-space:nowrap; font-size:12px;"><?= e($r['at']) ?></td>
        <td><?= e($r['actor'] ?? 'system') ?></td>
        <td>
          <?php if ($isPerson): ?>
            <a href="<?= e(url('/people/' . $r['entity_id'])) ?>"><?= e($r['entity']) ?> #<?= e($r['entity_id']) ?></a>
          <?php else: ?>
            <?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . e($r['entity_id']) : '' ?>
          <?php endif; ?>
        </td>
        <td><span class="badge badge--<?= e($actionMod($r['action'])) ?>"><?= e($r['action']) ?></span></td>
        <td>
          <?php if ($before === '' && $after === ''): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <details>
              <summary style="cursor:pointer; color:#0E7490; font-size:12.5px;">view</summary>
              <div style="display:flex; gap:12px; margin-top:8px; flex-wrap:wrap;">
                <?php if ($before !== ''): ?><div style="flex:1; min-width:200px;"><div class="kv__label">before</div><pre class="mono audit-json"><?= e($before) ?></pre></div><?php endif; ?>
                <?php if ($after !== ''): ?><div style="flex:1; min-width:200px;"><div class="kv__label">after</div><pre class="mono audit-json"><?= e($after) ?></pre></div><?php endif; ?>
              </div>
            </details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="5" class="empty">No audit entries match these filters.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div style="display:flex; align-items:center; justify-content:space-between; margin-top:14px; font-size:12.5px; color:#6B7E8C;">
  <span>Showing <?= e($from) ?>–<?= e($to) ?> of <?= e($total) ?></span>
  <span style="display:flex; gap:8px;">
    <?php if ($page > 1): ?><a class="btn btn--ghost" href="<?= e(url('/audit', $base + ['page' => $page - 1])) ?>" style="height:34px;">← Prev</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn btn--ghost" href="<?= e(url('/audit', $base + ['page' => $page + 1])) ?>" style="height:34px;">Next →</a><?php endif; ?>
  </span>
</div>
