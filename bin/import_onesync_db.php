<?php

declare(strict_types=1);

/**
 * Pull OneSync provisioning results from OneSync's MariaDB into
 * account_sync_status (per-destination state + failure messages).
 *
 *   php bin/import_onesync_db.php --dry-run
 *   php bin/import_onesync_db.php
 *
 * Reads OneSync read-only (ONESYNC_DB_*), writes as the write-back role. Joins
 * os_users.userId = our person_uuid, reading both IDM feeds
 * (sourceId in ONESYNC_DB_SOURCE_ID_STUDENTS / ONESYNC_DB_SOURCE_ID_FACULTY).
 */

use App\Import\OneSyncResultImporter;
use App\Service\ServiceRunLog;

require __DIR__ . '/../src/bootstrap.php';

$dryRun = in_array('--dry-run', array_slice($_SERVER['argv'] ?? [], 1), true);

// Cutover switch: once IDM is authoritative for AD/Google, an admin turns the
// OneSync DB sync OFF (ONESYNC_DB_SYNC_ENABLED=false). Skip cleanly (exit 0) so a
// cron that's still scheduled doesn't error or record a run.
if (!OneSyncResultImporter::syncEnabled()) {
    echo "OneSync DB sync is disabled (cutover) — skipping. Re-enable ONESYNC_DB_SYNC_ENABLED to resume.\n";
    exit(0);
}

// Record the run in service_run so the admin "Services" page can show when the
// OneSync DB sync last ran and with what outcome. Dry runs aren't recorded (they
// change nothing). Logging never blocks the actual import — start() returns null
// on any bookkeeping failure and finish() is then a no-op.
$runLog = $dryRun ? null : new ServiceRunLog();
$runId = $runLog?->start('onesync_db', 'cron', 'system:import_onesync_db');

try {
    $result = (new OneSyncResultImporter())->run($dryRun);
} catch (\Throwable $e) {
    $runLog?->finish($runId, 'failed', [], $e->getMessage());
    fwrite(STDERR, 'OneSync DB import failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Check ONESYNC_DB_* in .env and that the user has SELECT.\n");
    exit(1);
}

echo 'OneSync DB result import' . ($dryRun ? " (DRY RUN)\n" : "\n");
if (isset($result['note'])) {
    echo '  ' . $result['note'] . "\n";
}
$c = $result['counts'];
echo "  users {$c['users']}  ·  rows {$c['rows']}  ·  upserted {$c['upserted']}"
    . "  ·  activated {$c['activated']}  ·  failed {$c['failed']}"
    . "  ·  no-person {$c['no_person']}  ·  errors {$c['errors']}\n";

$summary = $result['note'] ?? sprintf(
    'users %d · rows %d · upserted %d · activated %d · failed %d · no-person %d · errors %d',
    $c['users'], $c['rows'], $c['upserted'], $c['activated'], $c['failed'], $c['no_person'], $c['errors']
);
$runLog?->finish($runId, $c['errors'] > 0 ? 'failed' : 'complete', $c, $summary);

exit($c['errors'] > 0 ? 1 : 0);
