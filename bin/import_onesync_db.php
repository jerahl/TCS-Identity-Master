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
 * os_users.userId = our person_uuid (sourceId = ONESYNC_DB_SOURCE_ID, default 21).
 */

use App\Import\OneSyncResultImporter;

require __DIR__ . '/../src/bootstrap.php';

$dryRun = in_array('--dry-run', array_slice($_SERVER['argv'] ?? [], 1), true);

try {
    $result = (new OneSyncResultImporter())->run($dryRun);
} catch (\Throwable $e) {
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
    . "  ·  failed {$c['failed']}  ·  no-person {$c['no_person']}  ·  errors {$c['errors']}\n";

exit($c['errors'] > 0 ? 1 : 0);
