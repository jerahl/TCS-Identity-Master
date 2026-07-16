<?php
/**
 * Detailed sync log view. One run of one job at a time: job tabs across the
 * top, a picker over the job's recent runs, severity filter buttons, then the
 * run's persisted service_run_log entries. Deep-linked from the Outputs tiles
 * ("requires attention" → ?level=attention, "changes applied" → ?level=change).
 *
 * @var string $job    selected job key
 * @var array  $jobs   job key => label (ServiceRunLog::JOBS)
 * @var string $level  'all' | 'attention' | 'change' | 'info'
 * @var array  $runs   the job's recent service_run rows, newest first
 * @var ?array $run    the selected run row (null = job never ran)
 * @var array  $entries service_run_log rows for the selected run + level
 * @var array  $counts  entry totals: total / attention / change / info
 */

$statusTone = static fn(string $s): string => $s === 'complete' ? 'active' : ($s === 'failed' ? 'terminated' : 'pending');
$levelTone  = ['attention' => 'terminated', 'change' => 'active', 'info' => 'disabled'];
$levelLabel = ['all' => 'All entries', 'attention' => 'Requires attention', 'change' => 'Changes', 'info' => 'Info'];
$runId = $run !== null ? (int) $run['run_id'] : 0;
?>
<div class="page-head">
  <div>
    <a class="back-link" href="<?= e(url('/outputs')) ?>">&larr; Outputs</a>
    <h1>Sync logs</h1>
    <p>The detailed record of each sync run — every account it created, changed, or expired, and everything that errored or needs review. Filter by severity, or pick an earlier run.</p>
  </div>
</div>

<!-- Job tabs -->
<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
  <?php foreach ($jobs as $key => $label): ?>
    <a class="btn <?= $key === $job ? '' : 'btn--ghost' ?>" href="<?= e(url('/outputs/logs', ['job' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($run === null): ?>
<div class="panel">
  <p class="muted" style="font-size:12.5px;">No <?= e($jobs[$job] ?? $job) ?> run has been recorded yet.</p>
</div>
<?php else: $st = (string) $run['status']; ?>

<!-- Selected run summary -->
<div class="panel" style="margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <h2 class="panel__title" style="margin:0; flex:1;"><?= e($jobs[$job] ?? $job) ?> — run #<?= e($runId) ?></h2>
    <span class="badge badge--<?= e($statusTone($st)) ?>"><?= e(strtoupper($st)) ?></span>
  </div>
  <dl style="margin:10px 0 0; display:grid; grid-template-columns:auto 1fr; gap:2px 12px; font-size:12.5px;">
    <dt class="muted">Started</dt><dd class="mono" style="margin:0;"><?= e(str_replace('T', ' ', (string) ($run['started_at'] ?? ''))) ?></dd>
    <dt class="muted">Finished</dt><dd class="mono" style="margin:0;"><?= e(($run['finished_at'] ?? '') !== null && (string) $run['finished_at'] !== '' ? str_replace('T', ' ', (string) $run['finished_at']) : '—') ?></dd>
    <dt class="muted">Origin</dt><dd class="mono" style="margin:0;"><?= e((string) ($run['origin'] ?? '')) ?><?= !empty($run['actor']) ? ' · ' . e((string) $run['actor']) : '' ?></dd>
    <?php if (!empty($run['message'])): ?>
    <dt class="muted">Result</dt><dd style="margin:0;<?= $st === 'failed' ? ' color:#94413A;' : '' ?>"><?= e((string) $run['message']) ?></dd>
    <?php endif; ?>
  </dl>

  <?php if (count($runs) > 1): ?>
  <div style="margin-top:12px;">
    <label class="muted" for="run-picker" style="font-size:12px;">Earlier runs:</label>
    <select id="run-picker" onchange="if (this.value) location.href = this.value;" style="font-size:12.5px; margin-left:6px;">
      <?php foreach ($runs as $r): $rid = (int) $r['run_id']; ?>
        <option value="<?= e(url('/outputs/logs', ['job' => $job, 'run' => $rid] + ($level !== 'all' ? ['level' => $level] : []))) ?>"<?= $rid === $runId ? ' selected' : '' ?>>
          #<?= e($rid) ?> · <?= e(str_replace('T', ' ', (string) ($r['started_at'] ?? ''))) ?> · <?= e((string) $r['status']) ?> · <?= e((string) $r['origin']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</div>

<!-- Log entries -->
<div class="panel">
  <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
    <h2 class="panel__title" style="margin:0; flex:1;">Log entries</h2>
    <?php foreach (['all' => $counts['total'], 'attention' => $counts['attention'], 'change' => $counts['change'], 'info' => $counts['info']] as $lv => $n): ?>
      <a class="btn <?= $lv === $level ? '' : 'btn--ghost' ?>" style="font-size:12px;"
         href="<?= e(url('/outputs/logs', ['job' => $job, 'run' => $runId] + ($lv !== 'all' ? ['level' => $lv] : []))) ?>"><?= e($levelLabel[$lv]) ?> (<?= e($n) ?>)</a>
    <?php endforeach; ?>
  </div>

  <?php if ($entries === []): ?>
    <p class="muted" style="font-size:12.5px;">
      <?php if ($counts['total'] === 0): ?>
        This run recorded no detailed log entries<?= $st === 'running' ? ' yet — it is still running' : '' ?>.
        Runs from before detailed logging was added only carry summary counts; the next run will record a full log.
      <?php else: ?>
        No <?= e(strtolower($levelLabel[$level] ?? $level)) ?> entries in this run — <a href="<?= e(url('/outputs/logs', ['job' => $job, 'run' => $runId])) ?>">show all <?= e($counts['total']) ?></a>.
      <?php endif; ?>
    </p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>Time</th><th>Phase</th><th>Person / account</th><th>Outcome</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $en): ?>
        <tr>
          <td class="mono muted"><?= e((int) $en['seq']) ?></td>
          <td class="mono" style="white-space:nowrap;"><?= e(str_replace('T', ' ', (string) ($en['logged_at'] ?? ''))) ?></td>
          <td class="mono"><?= e((string) ($en['phase'] ?? '')) ?></td>
          <td>
            <?php if (!empty($en['person_id'])): ?>
              <a href="<?= e(url('/people/' . (int) $en['person_id'])) ?>"><?= e((string) $en['subject'] !== '' ? (string) $en['subject'] : '#' . (int) $en['person_id']) ?></a>
            <?php else: ?>
              <?= e((string) $en['subject']) ?>
            <?php endif; ?>
          </td>
          <td><span class="badge badge--<?= e($levelTone[(string) $en['level']] ?? 'disabled') ?>"><?= e((string) $en['outcome']) ?></span></td>
          <td style="font-size:12.5px; word-break:break-word;"><?= e((string) $en['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
