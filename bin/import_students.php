<?php

declare(strict_types=1);

/**
 * Pull active students from PowerSchool (Oracle/ODBC) and stage them for OneSync.
 *
 * Students are a straight passthrough — no matching, no golden record. We run one
 * query against PowerSchool's STUDENTS table and upsert the rows into the
 * `student` table, which OneSync reads via v_onesync_student_source. Drop-outs
 * (active before, absent now) are flagged inactive, never deleted.
 *
 *   php bin/import_students.php [--dry-run]    # ODBC (needs PS_ODBC_*)
 *
 * Source query (Enroll_Status 0 = enrolled, 3 = future):
 *   SELECT State_StudentNumber, SchoolID, Grade_Level, First_Name, Last_Name,
 *          ID, DCID, EntryCode, ExitCode, ExitDate
 *   FROM Students WHERE Enroll_Status = 0 OR Enroll_Status = 3
 */

use App\Config;
use App\Import\StudentImporter;

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
    if (trim((string) Config::get('PS_ODBC_DSN', '')) === '') {
        fwrite(STDERR, "PS_ODBC_DSN is not set. Configure the PowerSchool Oracle ODBC connection (PS_ODBC_*).\n");
        exit(2);
    }

    echo 'Students import — Oracle ODBC' . ($dryRun ? " (DRY RUN)\n\n" : "\n\n");
    $result = (new StudentImporter())->runFromOdbc($dryRun);

    $c = $result['counts'];
    echo "rows {$c['total']}  ·  inserted {$c['inserted']}  ·  updated {$c['updated']}"
        . "  ·  deactivated {$c['deactivated']}  ·  skipped {$c['skipped']}\n";

    if ($c['skipped'] > 0) {
        fwrite(STDERR, "\n{$c['skipped']} row(s) skipped — missing DCID (no stable key to stage).\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Students import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
