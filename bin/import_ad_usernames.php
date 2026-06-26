<?php

declare(strict_types=1);

/**
 * ONE-TIME: link existing AD usernames to the golden record.
 *
 *   php bin/import_ad_usernames.php --file=/var/idm/onesync/ad_export.csv --dry-run
 *   php bin/import_ad_usernames.php --file=/var/idm/onesync/ad_export.csv
 *
 * Matches each AD row by the PowerSchool id embedded in uniqueId (leading "T"
 * stripped: T14774 -> 14774), falling back to the Employee ID column, then sets
 * + LOCKS the person's sAMAccountName as their username (and mail as email).
 * Idempotent; never overwrites a username already locked to a different value.
 *
 * Expected headers (tab or comma; auto-detected):
 *   uniqueId  mail  surname  givenName  sAMAccountName  Employee ID  department  title  ADTitle
 */

use App\Import\AdUsernameImporter;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$file = $opts['file'] ?? null;

if ($file === null) {
    fwrite(STDERR, "Usage: php bin/import_ad_usernames.php --file=<ad_export.csv> [--dry-run]\n");
    exit(2);
}

try {
    $result = (new AdUsernameImporter())->run($file, $dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, 'AD username import failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'AD username link' . ($dryRun ? " (DRY RUN)\n" : "\n");
foreach ($result['outcomes'] as $o) {
    printf("  [%-9s] %-22s %s\n", strtoupper($o['outcome']), $o['username'] !== '' ? $o['username'] : $o['uniqueId'], $o['detail']);
}
$c = $result['counts'];
echo "\n  rows {$c['total']}  ·  applied {$c['applied']}  ·  noop {$c['noop']}  ·  conflict {$c['conflict']}"
    . "  ·  skipped {$c['skipped']}  ·  no-person {$c['no_person']}  ·  errors {$c['errors']}\n";

exit($c['errors'] > 0 ? 1 : 0);
