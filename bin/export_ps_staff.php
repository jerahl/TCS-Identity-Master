<?php

declare(strict_types=1);

/**
 * Generate (and optionally upload) the fixed-name staff files the PowerSchool
 * AutoComm/DIM jobs consume nightly — the IDM-replaces-OneSync provisioning
 * feed for staff records. Three deliverables (docs/powerschool-staff-autocomm.md):
 *
 *   ps_staff_create.txt  AutoComm full sync (create/update staff) — 16
 *                        positional columns, no header row
 *   ps_staff_sso.txt     AutoComm LoginID/e-mail alignment for SSO — only
 *                        people who already exist in PowerSchool
 *   ps_staff_race.txt    DIM TeacherRace child-table file (header row)
 *
 *   php bin/export_ps_staff.php --mode=create|sso|race   one file
 *   php bin/export_ps_staff.php --all                    all three files
 *   php bin/export_ps_staff.php --all --upload           nightly cron entry
 *   php bin/export_ps_staff.php --all --dry-run          report only, write nothing
 *   php bin/export_ps_staff.php --all --out=DIR          override EXPORT_POWERSCHOOL_DIR
 *
 * Value mappings (StaffStatus, FedEthnicity, FedRaceDecline coupling rule,
 * the PS race-code map) live in App\Export\PowerSchoolAutoCommExporter — see
 * its header. Passwords are NEVER exported: AD is the authenticator and the
 * PS local password fields are intentionally unmanaged.
 *
 * Safety rails:
 *   - hard failures (missing TeacherNumber, unmapped school, unmapped race
 *     code) skip the record, exit non-zero, and gate the upload — a failed
 *     mode uploads NOTHING, leaving yesterday's known-good file on the server
 *     (create + race are coupled: a failure in either gates both);
 *   - empty-file guard: if a file's row count collapses below
 *     PS_STAFF_MIN_ROW_RATIO of the previous run's, the mode fails and the
 *     local fixed file is not even overwritten;
 *   - uploads go up under a temporary name and are renamed into place, then
 *     verified (remote size == local size), so AutoComm never reads a
 *     half-written file;
 *   - every run writes a timestamped audit copy to <out>/archive/ (swept
 *     after PS_STAFF_ARCHIVE_DAYS, default 30).
 *
 * Runs are recorded in service_run (job 'ps_staff_export') so the Outputs page
 * surfaces a failed night, same as the other background jobs.
 */

use App\Config;
use App\Export\PowerSchoolAutoCommExporter as Exporter;
use App\Service\ServiceRunLog;
use App\Sync\Sftp\SystemSftpUploader;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

$dryRun = isset($opts['dry-run']);
$doUpload = isset($opts['upload']);
$modes = isset($opts['all'])
    ? ['create', 'sso', 'race']
    : (isset($opts['mode']) ? [(string) $opts['mode']] : []);
foreach ($modes as $m) {
    if (!in_array($m, ['create', 'sso', 'race'], true)) {
        fwrite(STDERR, "Unknown --mode={$m} (expected create, sso or race)\n");
        exit(2);
    }
}
if ($modes === []) {
    fwrite(STDERR, "Usage: php bin/export_ps_staff.php --mode=create|sso|race [--dry-run] [--upload] [--out=DIR]\n"
        . "       php bin/export_ps_staff.php --all [--dry-run] [--upload]\n");
    exit(2);
}
$outDir = isset($opts['out']) && $opts['out'] !== '1'
    ? (string) $opts['out']
    : trim((string) Config::get('EXPORT_POWERSCHOOL_DIR', ''));
if ($outDir === '') {
    fwrite(STDERR, "EXPORT_POWERSCHOOL_DIR is not set (or pass --out=DIR).\n");
    exit(2);
}
$minRatio = (float) (Config::get('PS_STAFF_MIN_ROW_RATIO', '0.5') ?? '0.5');
$archiveDays = Config::int('PS_STAFF_ARCHIVE_DAYS', 30);

$exporter = new Exporter();
$people = $exporter->people();
$built = $exporter->buildAll($people);
$report = $built['report'];

/** Per-mode file spec: fixed name, rows, header row (DIM race file only). */
$spec = [
    'create' => ['file' => Exporter::FILE_CREATE, 'rows' => $built['create'], 'headers' => null],
    'sso'    => ['file' => Exporter::FILE_SSO,    'rows' => $built['sso'],    'headers' => null],
    'race'   => ['file' => Exporter::FILE_RACE,   'rows' => $built['race'],   'headers' => Exporter::RACE_HEADERS],
];

// ---------------------------------------------------------------- guard check
// Compare against the previous run's fixed file BEFORE anything is overwritten:
// a collapsed row count means a broken query/feed, and yesterday's known-good
// file must survive both locally and on the SFTP server.
$guardTripped = [];
foreach ($modes as $m) {
    $prev = Exporter::countDataRows(Exporter::fixedPath($outDir, $spec[$m]['file']), $spec[$m]['headers'] !== null);
    $new = count($spec[$m]['rows']);
    if (Exporter::guardTrips($prev, $new, $minRatio)) {
        $guardTripped[$m] = [$prev, $new];
        $report['errors'][$m][] = sprintf(
            'EMPTY-FILE GUARD: %s dropped from %d to %d data row(s) (threshold ratio %.2f) — mode failed, nothing written or uploaded for it',
            $spec[$m]['file'], $prev, $new, $minRatio
        );
    }
}

// A mode fails on its own hard errors — plus, create and race are coupled
// (the create file's FedRaceDecline=0 promises rows in the race file), so a
// failure in either fails both. Errors in modes that were neither requested
// nor coupled to a requested one don't affect this run.
$errorModes = $modes;
if (in_array('create', $modes, true) || in_array('race', $modes, true)) {
    $errorModes = array_values(array_unique(array_merge($errorModes, ['create', 'race'])));
}
$coupledDirty = $report['errors']['create'] !== [] || $report['errors']['race'] !== [];
$dirty = [];
foreach ($modes as $m) {
    $dirty[$m] = $report['errors'][$m] !== []
        || (in_array($m, ['create', 'race'], true) && $coupledDirty);
}

// ------------------------------------------------------------------- run report
$hardErrors = array_merge(...array_map(fn (string $m) => $report['errors'][$m], $errorModes));
printf("PowerSchool staff AutoComm export%s\n", $dryRun ? '  (DRY RUN)' : '');
printf("  scope: %d active/pending people\n\n", count($people));
foreach ($modes as $m) {
    printf("  %-6s %-20s  %d data row(s)\n", $m, $spec[$m]['file'], count($spec[$m]['rows']));
}

$skippedShown = array_values(array_filter($report['skipped'], fn ($s) => in_array($s['mode'], $modes, true)));
if ($skippedShown !== []) {
    printf("\n  Skipped (%d):\n", count($skippedShown));
    foreach ($skippedShown as $s) {
        printf("    ! [%s] %s — %s\n", $s['mode'], $s['who'], $s['reason']);
    }
}
if ($report['coupling_flags'] !== []) {
    printf("\n  FedRaceDecline left empty — no race rows (%d):\n", count($report['coupling_flags']));
    foreach ($report['coupling_flags'] as $w) {
        printf("    ~ %s\n", $w);
    }
}
if ($report['warnings'] !== []) {
    printf("\n  Warnings (%d):\n", count($report['warnings']));
    foreach ($report['warnings'] as $w) {
        printf("    ~ %s\n", $w);
    }
}
if ($report['sanitized'] !== []) {
    printf("\n  Sanitized field values (%d):\n", count($report['sanitized']));
    foreach ($report['sanitized'] as $s) {
        printf("    ~ %s\n", $s);
    }
}
if ($hardErrors !== []) {
    printf("\n  HARD FAILURES (%d) — exit non-zero, failed modes upload NOTHING:\n", count($hardErrors));
    foreach ($errorModes as $m) {
        foreach ($report['errors'][$m] as $e) {
            printf("    !! [%s] %s\n", $m, $e);
        }
    }
}

if ($dryRun) {
    foreach ($modes as $m) {
        printf("\n  Sample — %s (first 5 of %d):\n", $spec[$m]['file'], count($spec[$m]['rows']));
        if ($spec[$m]['headers'] !== null) {
            printf("    %s\n", implode(' | ', $spec[$m]['headers']));
        }
        foreach (array_slice($spec[$m]['rows'], 0, 5) as $row) {
            printf("    %s\n", implode(' | ', $row));
        }
    }
    echo "\n  Dry run — nothing written, nothing uploaded.\n";
    exit($hardErrors === [] ? 0 : 1);
}

// ---------------------------------------------------------------- write files
$runLog = new ServiceRunLog();
$runId = $runLog->start('ps_staff_export', 'cron', 'system:export_ps_staff');
$stamp = date('Ymd_His');
$uploaded = 0;
$uploadErrors = [];

echo "\n";
foreach ($modes as $m) {
    $body = Exporter::render($spec[$m]['rows'], $spec[$m]['headers']);
    // Timestamped audit copy always — including for a guard-tripped mode, so
    // the bad output can be inspected/diffed.
    $archive = Exporter::archivePath($outDir, $spec[$m]['file'], $stamp);
    Exporter::writeFileAtomic($archive, $body);
    if (isset($guardTripped[$m])) {
        printf("  %-6s guard tripped — fixed file kept from previous run; suspect output archived at %s\n", $m, $archive);
        continue;
    }
    $fixed = Exporter::fixedPath($outDir, $spec[$m]['file']);
    $bytes = Exporter::writeFileAtomic($fixed, $body);
    printf("  %-6s wrote %s (%d bytes, %d row(s)) + archive copy\n", $m, $fixed, $bytes, count($spec[$m]['rows']));
}

$swept = Exporter::sweepArchive($outDir, $archiveDays);
if ($swept !== []) {
    printf("  archive sweep: removed %d file(s) older than %d days\n", count($swept), $archiveDays);
}

// -------------------------------------------------------------------- upload
if ($doUpload) {
    $remoteDir = trim((string) Config::get('PS_STAFF_SFTP_DIR', ''));
    if ($remoteDir === '') {
        $uploadErrors[] = 'PS_STAFF_SFTP_DIR is not set — files written locally but NOT uploaded.';
    } else {
        $uploader = null;
        try {
            $uploader = SystemSftpUploader::fromConfig();
        } catch (\Throwable $e) {
            $uploadErrors[] = 'SFTP not configured: ' . $e->getMessage();
        }
        foreach ($uploader === null ? [] : $modes as $m) {
            if ($dirty[$m]) {
                printf("  %-6s UPLOAD SKIPPED — run not clean; yesterday's %s stays in place on the server\n", $m, $spec[$m]['file']);
                continue;
            }
            $local = Exporter::fixedPath($outDir, $spec[$m]['file']);
            try {
                $atomic = $uploader->uploadAtomic($local, $remoteDir, $spec[$m]['file']);
                $localSize = (int) filesize($local);
                $remoteSize = $uploader->remoteSize(rtrim($remoteDir, '/') . '/' . $spec[$m]['file']);
                if ($remoteSize !== $localSize) {
                    throw new RuntimeException(sprintf(
                        'post-upload size check failed (local %d bytes, remote %s)',
                        $localSize, $remoteSize === null ? 'missing/unreadable' : "{$remoteSize} bytes"
                    ));
                }
                $uploaded++;
                printf("  %-6s uploaded to sftp://%s%s/%s (%d bytes, size verified%s)\n",
                    $m, (string) Config::get('SFTP_HOST', ''), rtrim($remoteDir, '/'), $spec[$m]['file'],
                    $localSize, $atomic ? ', atomic rename' : ', NON-ATOMIC fallback swap');
            } catch (\Throwable $e) {
                $uploadErrors[] = "{$spec[$m]['file']}: upload failed — " . $e->getMessage();
            }
        }
    }
    foreach ($uploadErrors as $e) {
        fwrite(STDERR, "  !! UPLOAD: {$e}\n");
    }
}

// ------------------------------------------------------------------ run log
$failed = $hardErrors !== [] || $uploadErrors !== [];
$counts = [
    'create'   => count($built['create']),
    'sso'      => count($built['sso']),
    'race'     => count($built['race']),
    'skipped'  => count($report['skipped']),
    'warnings' => count($report['warnings']),
    'errors'   => count($hardErrors) + count($uploadErrors),
    'uploaded' => $uploaded,
];
$message = $failed
    ? implode(' · ', array_slice(array_merge($hardErrors, $uploadErrors), 0, 3))
    : sprintf('create %d · sso %d · race %d · skipped %d%s',
        $counts['create'], $counts['sso'], $counts['race'], $counts['skipped'],
        $doUpload ? " · uploaded {$uploaded}" : '');
$runLog->finish($runId, $failed ? 'failed' : 'complete', $counts, $message);

if ($failed) {
    fwrite(STDERR, "\nRun FAILED — see hard failures above. Failed modes were NOT uploaded.\n");
    exit(1);
}
echo "\nRun complete.\n";
exit(0);
