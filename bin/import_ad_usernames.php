<?php

declare(strict_types=1);

/**
 * ONE-TIME: link existing AD accounts to the golden record.
 *
 *   php bin/import_ad_usernames.php --file=/var/idm/feeds/powerschool/TeachersID.csv --dry-run
 *   php bin/import_ad_usernames.php --file=/var/idm/onesync/ad_export.csv
 *   php bin/import_ad_usernames.php --file=/var/idm/ad/Employee_List.csv --dry-run
 *
 * Accepts any of these files (auto-detected from the headers):
 *  - PowerSchool TEACHERS export: TEACHERS.ID (PS key), TEACHERS.TeacherLoginID
 *    (username), TEACHERS.Email_Addr (email), TEACHERS.TeacherNumber (NextGen #).
 *  - AD directory export: uniqueId ("T"+TEACHERS.ID), sAMAccountName, mail,
 *    Employee ID.
 *    For both of the above: matches on the PS id (TEACHERS.ID, or AD uniqueId
 *    with the leading "T" stripped), falling back to the Employee ID, then sets
 *    + LOCKS the username (and email).
 *  - Adaxes "Employee List" export: First name, Last name, Email, Logon Name
 *    (UPN), Logon Name (pre-Windows 2000) (= sAMAccountName), Employee ID,
 *    Object GUID, Department, Parent, Name. Matches on Employee ID, then Email,
 *    then username, and sets + LOCKS the sAMAccountName as the username
 *    (refreshing email + UPN) and records the real objectGUID in the crosswalk.
 *
 * Idempotent; never overwrites a locked username. Writes nothing on --dry-run.
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

echo 'AD account link [' . ($result['format'] ?? 'ad') . ']' . ($dryRun ? " (DRY RUN)\n" : "\n");
foreach ($result['outcomes'] as $o) {
    printf("  [%-9s] %-22s %s\n", strtoupper($o['outcome']), $o['username'] !== '' ? $o['username'] : $o['uniqueId'], $o['detail']);
}
$c = $result['counts'];
echo "\n  rows {$c['total']}  ·  applied {$c['applied']}  ·  noop {$c['noop']}  ·  conflict {$c['conflict']}"
    . "  ·  skipped {$c['skipped']}  ·  no-person {$c['no_person']}  ·  errors {$c['errors']}\n";

exit($c['errors'] > 0 ? 1 : 0);
