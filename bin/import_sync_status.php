<?php

declare(strict_types=1);

/**
 * Import OneSync's export/status log into per-account provisioning status.
 *   php bin/import_sync_status.php --file=/var/idm/onesync/export_log.csv [--dry-run]
 * With no --file, uses ONESYNC_EXPORT_LOG. Upserts one row per (person,
 * destination); appends to the capped account_sync_event history.
 */

use App\Import\SyncStatusImporter;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);

try {
    $result = (new SyncStatusImporter())->run($opts['file'] ?? null, $dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Sync-status import failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$c = $result['counts'];
echo 'Account-status write-back' . ($dryRun ? " (DRY RUN)\n" : "\n");
echo "  rows {$c['total']}  ·  upserted {$c['upserted']}  ·  events {$c['events']}"
    . "  ·  no-person {$c['no_person']}  ·  skipped {$c['skipped']}  ·  errors {$c['errors']}\n";

exit($c['errors'] > 0 ? 1 : 0);
