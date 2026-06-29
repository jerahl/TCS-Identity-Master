<?php

declare(strict_types=1);

/**
 * Import PowerSchool staff (USERS + TEACHERS + SCHOOLSTAFF), joined into one
 * record per person (multi-school assignments, every TEACHERS.ID linked to the
 * crosswalk).
 *
 * The live source is PowerSchool's Oracle DB read directly over ODBC:
 *
 *   php bin/import_powerschool.php [--dry-run]          # ODBC (default; needs PS_ODBC_*)
 *
 * CSV files remain a manual/offline fallback (auto-detected by header, so the
 * filenames don't matter):
 *
 *   php bin/import_powerschool.php --dir=/var/idm/feeds/powerschool [--dry-run]
 *   php bin/import_powerschool.php --users=Users_export.csv --teachers=TeachersID.csv \
 *        --schoolstaff=SchoolStaff_export.csv [--dry-run]
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

/** Classify a CSV by a marker column unique to each export. */
$classify = static function (string $file): ?string {
    $rows = Csv::read($file);
    return $rows === [] ? null : PowerSchoolBundle::classify($rows[0]);
};

// CSV mode only when files/dir are given explicitly; otherwise pull from Oracle.
$useCsv = isset($opts['users']) || isset($opts['teachers']) || isset($opts['schoolstaff']) || isset($opts['dir']);

try {
    if (!$useCsv) {
        if (trim((string) Config::get('PS_ODBC_DSN', '')) === '') {
            fwrite(STDERR, "PS_ODBC_DSN is not set. Configure the PowerSchool Oracle ODBC connection (PS_ODBC_*),\n"
                . "or pass CSV files explicitly: --dir=<folder> or --users/--teachers/--schoolstaff.\n");
            exit(2);
        }
        echo 'PowerSchool import — Oracle ODBC' . ($dryRun ? " (DRY RUN)\n\n" : "\n\n");
        $result = (new PowerSchoolImporter())->runFromOdbc($dryRun);
    } else {
        $users = $opts['users'] ?? null;
        $teachers = $opts['teachers'] ?? null;
        $staff = $opts['schoolstaff'] ?? null;

        if ($users === null || $teachers === null || $staff === null) {
            $dir = $opts['dir'] ?? (string) Config::get('FEED_POWERSCHOOL_DIR', '');
            if ($dir === '' || !is_dir($dir)) {
                fwrite(STDERR, "Provide --dir=<folder> (or --users/--teachers/--schoolstaff), or set FEED_POWERSCHOOL_DIR.\n");
                exit(2);
            }
            foreach (glob(rtrim($dir, '/') . '/*.csv') ?: [] as $f) {
                $kind = $classify($f);
                if ($kind === 'users' && $users === null) {
                    $users = $f;
                } elseif ($kind === 'teachers' && $teachers === null) {
                    $teachers = $f;
                } elseif ($kind === 'schoolstaff' && $staff === null) {
                    $staff = $f;
                }
            }
        }

        $missing = array_keys(array_filter(['users' => $users, 'teachers' => $teachers, 'schoolstaff' => $staff], static fn($v) => $v === null));
        if ($missing !== []) {
            fwrite(STDERR, 'Could not find PowerSchool file(s): ' . implode(', ', $missing) . ". Check the folder has all three exports.\n");
            exit(2);
        }

        echo 'PowerSchool import — CSV' . ($dryRun ? " (DRY RUN)\n" : "\n");
        echo "  users:       {$users}\n  teachers:    {$teachers}\n  schoolstaff: {$staff}\n\n";
        $result = (new PowerSchoolImporter())->run($users, $teachers, $staff, $dryRun);
    }

    $c = $result['counts'];
    echo "rows {$c['total']}  ·  auto {$c['auto_match']}  ·  new {$c['new']}  ·  review {$c['needs_review']}"
        . "  ·  skipped {$c['skipped']}  ·  assignments {$c['assignments']}  ·  unmapped-school {$c['unmapped_school']}  ·  errors {$c['errors']}\n";
    exit($c['errors'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'PowerSchool import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
