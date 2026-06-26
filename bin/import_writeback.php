<?php

declare(strict_types=1);

/**
 * Apply OneSync's username write-back to the golden record.
 *
 *   php bin/import_writeback.php --file=/var/idm/onesync/usernames.csv [--dry-run]
 *   php bin/import_writeback.php --pending [--dry-run]
 *
 * --file ingests a usernames CSV. --pending applies onesync_writeback rows that
 * OneSync wrote DIRECTLY to the DB (applied = 0). With neither, uses
 * ONESYNC_WRITEBACK_FILE. Idempotent; never overwrites a locked username.
 */

use App\Import\WritebackImporter;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$pending = isset($opts['pending']);

try {
    $importer = new WritebackImporter();
    $result = $pending ? $importer->runPending($dryRun) : $importer->run($opts['file'] ?? null, $dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Write-back failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Username write-back' . ($pending ? ' [pending DB rows]' : '') . ($dryRun ? " (DRY RUN)\n" : "\n");
foreach ($result['outcomes'] as $o) {
    printf("  [%-8s] %-36s %s\n", strtoupper($o['outcome']), $o['username'] !== '' ? $o['username'] : $o['uuid'], $o['detail']);
}
$c = $result['counts'];
echo "\n  rows {$c['total']}  ·  applied {$c['applied']}  ·  noop {$c['noop']}  ·  conflict {$c['conflict']}"
    . "  ·  skipped {$c['skipped']}  ·  no-person {$c['no_person']}  ·  errors {$c['errors']}\n";

exit($c['errors'] > 0 ? 1 : 0);
