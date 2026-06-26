<?php

declare(strict_types=1);

/**
 * Import PowerSchool from the THREE exports (USERS + TEACHERS + SCHOOLSTAFF),
 * joined into one record per person (multi-school assignments, every TEACHERS.ID
 * linked to the crosswalk).
 *
 *   php bin/import_powerschool.php --dir=/var/idm/feeds/powerschool [--dry-run]
 *   php bin/import_powerschool.php --users=Users_export.csv --teachers=TeachersID.csv \
 *        --schoolstaff=SchoolStaff_export.csv [--dry-run]
 *
 * With --dir, the three files are auto-detected by their headers (filename
 * doesn't matter). With no --dir/--users, defaults to FEED_POWERSCHOOL_DIR.
 */

use App\Config;
use App\Import\Csv;
use App\Import\PowerSchoolBundle;
use App\Import\PowerSchoolImporter;

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
    $users = $opts['users'] ?? null;
    $teachers = $opts['teachers'] ?? null;
    $staff = $opts['schoolstaff'] ?? null;

    if ($users === null || $teachers === null || $staff === null) {
        $dir = $opts['dir'] ?? (string) Config::get('FEED_POWERSCHOOL_DIR', '');
        if ($dir === '' || !is_dir($dir)) {
            fwrite(STDERR, "Provide --dir=<folder> (or --users/--teachers/--schoolstaff), or set FEED_POWERSCHOOL_DIR.\n");
            exit(2);
        }
        // Auto-detect by header, preferring canonical filenames (so an extra
        // MultipleID.csv doesn't get picked over TeachersID.csv).
        $picked = PowerSchoolBundle::selectFiles(glob(rtrim($dir, '/') . '/*.csv') ?: []);
        $users ??= $picked['users'];
        $teachers ??= $picked['teachers'];
        $staff ??= $picked['schoolstaff'];
    }

    $missing = array_keys(array_filter(['users' => $users, 'teachers' => $teachers, 'schoolstaff' => $staff], static fn($v) => $v === null));
    if ($missing !== []) {
        fwrite(STDERR, 'Could not find PowerSchool file(s): ' . implode(', ', $missing) . ". Check the folder has all three exports.\n");
        exit(2);
    }

    echo "PowerSchool import" . ($dryRun ? " (DRY RUN)\n" : "\n");
    echo "  users:       {$users}\n  teachers:    {$teachers}\n  schoolstaff: {$staff}\n\n";

    $result = (new PowerSchoolImporter())->run($users, $teachers, $staff, $dryRun);
    $c = $result['counts'];
    echo "rows {$c['total']}  ·  auto {$c['auto_match']}  ·  new {$c['new']}  ·  review {$c['needs_review']}"
        . "  ·  skipped {$c['skipped']}  ·  assignments {$c['assignments']}  ·  unmapped-school {$c['unmapped_school']}  ·  errors {$c['errors']}\n";
    exit($c['errors'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'PowerSchool import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
