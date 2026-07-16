<?php

declare(strict_types=1);

/**
 * Export staff changes for PowerSchool as ONE tab-delimited file for the
 * AutoComm import into the Teachers view (the only import path exposed in
 * the district's PowerSchool build) and upload it to the district SFTP
 * server. Only NEW users (not in PowerSchool yet) and CHANGED users (name or
 * username/email moved since the latest PowerSchool import snapshot) are
 * exported, one row per person per school assignment — and only with an
 * ALSDE ID on the golden record (anyone without one is held back and
 * logged). Files are always written under the SAME
 * names (each run overwrites the last, so AutoComm can point at a constant
 * file name):
 *
 *   ps_staff_teachers.txt         the AutoComm import file (uploaded)
 *   ps_staff_teachers_sample.txt  3-row sample for a manual test import (local)
 *   ps_staff_exceptions.txt       held-back / rejected rows, truncations (local)
 *
 *   php bin/export_powerschool.php             write files + upload to SFTP
 *   php bin/export_powerschool.php --dry-run   print summary + exceptions only
 *   php bin/export_powerschool.php --no-upload write the files, skip SFTP
 *   php bin/export_powerschool.php --out=DIR   override EXPORT_POWERSCHOOL_DIR
 *
 * Local files land in EXPORT_POWERSCHOOL_DIR; the upload goes to
 * SFTP_PS_EXPORT_DIR on the same SFTP server the feed pull uses (SFTP_HOST /
 * SFTP_USER / key). See docs/powerschool-staff-export.md for the column
 * contract and the PowerSchool-side AutoComm setup.
 */

use App\Config;
use App\Export\PowerSchoolStaffExporter;
use App\Service\RunLogRecorder;
use App\Service\ServiceRunLog;
use App\Sync\FeedSync;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$doUpload = !isset($opts['no-upload']);
$outDir = isset($opts['out']) && $opts['out'] !== '1'
    ? $opts['out']
    : trim((string) Config::get('EXPORT_POWERSCHOOL_DIR', ''));

// Record the run (job 'ps_export') so the Outputs page shows when the staff
// export last ran, with each new/changed/held-back person as a detailed log
// entry. Dry runs change nothing, so they aren't recorded.
$runLog = $dryRun ? null : new ServiceRunLog();
$runId  = $runLog?->start('ps_export', 'cron', 'system:export_powerschool');
$recorder = new RunLogRecorder($runLog, $runId);

$exporter = new PowerSchoolStaffExporter();
try {
    $result = $exporter->export();
} catch (\Throwable $e) {
    $runLog?->finish($runId, 'failed', [], $e->getMessage());
    fwrite(STDERR, 'PowerSchool staff export failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$summary = $result['summary'];
$counts = $summary + ['uploaded' => 0];

foreach ($result['new'] as $line) {
    $recorder->add(['phase' => 'export', 'person_id' => null, 'subject' => '',
        'outcome' => 'new', 'level' => 'change', 'detail' => $line]);
}
foreach ($result['changed'] as $line) {
    $recorder->add(['phase' => 'export', 'person_id' => null, 'subject' => '',
        'outcome' => 'changed', 'level' => 'change', 'detail' => $line]);
}
foreach ($result['exceptions'] as $line) {
    $recorder->add(['phase' => 'export', 'person_id' => null, 'subject' => '',
        'outcome' => 'exception', 'level' => 'attention', 'detail' => $line]);
}

// One-line rollup for the run row (finished at each exit path below).
$rollup = sprintf('new %d · changed %d · rows %d · exceptions %d',
    $summary['new'], $summary['changed'], $summary['rows'], $summary['exceptions']);

printf("PowerSchool staff export%s\n", $dryRun ? '  (DRY RUN)' : '');
printf("  new users:  %d (not in PowerSchool yet, ALSDE ID set)\n", $summary['new']);
printf("  changed:    %d (name or username/email moved since last snapshot)\n", $summary['changed']);
printf("  rows:       %d across %d school(s)\n", $summary['rows'], $summary['schools']);
printf("  exceptions: %d (held back / rejected / truncated / unmapped)\n", $summary['exceptions']);
foreach ($result['new'] as $line) {
    echo "  + {$line}\n";
}
foreach ($result['changed'] as $line) {
    echo "  ~ {$line}\n";
}
foreach ($result['exceptions'] as $line) {
    echo "  ! {$line}\n";
}
echo "\n";

if ($dryRun) {
    exit(0);
}
if ($outDir === '') {
    $runLog?->finish($runId, 'failed', $counts, 'EXPORT_POWERSCHOOL_DIR is not set (or pass --out=DIR)');
    fwrite(STDERR, "EXPORT_POWERSCHOOL_DIR is not set (or pass --out=DIR).\n");
    exit(1);
}
if ($summary['rows'] === 0) {
    // Still write + upload the (empty) file: the name is fixed, so this
    // clears yesterday's changes out of the AutoComm drop directory.
    echo "  nothing to export — writing an empty file to clear the drop\n";
}

try {
    $file = PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::render($result['rows']),
        $outDir, PowerSchoolStaffExporter::EXPORT_FILE);
    printf("  wrote %s (%d bytes, %d row(s))\n", $file['path'], $file['bytes'], $summary['rows']);
    PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::sample($result['rows']),
        $outDir, PowerSchoolStaffExporter::SAMPLE_FILE);
    $exceptions = PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::exceptionsFile($result['exceptions']),
        $outDir, PowerSchoolStaffExporter::EXCEPTIONS_FILE);
    printf("  wrote %s (%d exception(s))\n", $exceptions['path'], $summary['exceptions']);
} catch (\Throwable $e) {
    $runLog?->finish($runId, 'failed', $counts, 'could not write export files: ' . $e->getMessage());
    fwrite(STDERR, '  could not write export files: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$doUpload) {
    echo "  upload skipped (--no-upload)\n";
    $runLog?->finish($runId, 'complete', $counts, $rollup . ' · upload skipped (--no-upload)');
    exit(0);
}

$remoteDir = trim((string) Config::get('SFTP_PS_EXPORT_DIR', ''));
if ($remoteDir === '') {
    $runLog?->finish($runId, 'failed', $counts, $rollup . ' · SFTP_PS_EXPORT_DIR not set — file written but NOT uploaded');
    fwrite(STDERR, "  SFTP_PS_EXPORT_DIR is not set — file written but NOT uploaded.\n");
    exit(1);
}
try {
    $client = FeedSync::clientFromConfig();
    $remotePath = PowerSchoolStaffExporter::uploadFile($client, $file['path'], $remoteDir);
    printf("  uploaded to sftp://%s%s\n", (string) Config::get('SFTP_HOST', ''), $remotePath);
} catch (\Throwable $e) {
    $runLog?->finish($runId, 'failed', $counts, $rollup . ' · SFTP upload failed: ' . $e->getMessage());
    fwrite(STDERR, '  SFTP upload failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$counts['uploaded'] = 1;
$runLog?->finish($runId, 'complete', $counts, $rollup . ' · uploaded');
exit(0);
