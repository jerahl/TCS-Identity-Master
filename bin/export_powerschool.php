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

$exporter = new PowerSchoolStaffExporter();
$result = $exporter->export();
$summary = $result['summary'];

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
    fwrite(STDERR, "EXPORT_POWERSCHOOL_DIR is not set (or pass --out=DIR).\n");
    exit(1);
}
if ($summary['rows'] === 0) {
    // Still write + upload the (header-only) file: the name is fixed, so this
    // clears yesterday's changes out of the AutoComm drop directory.
    echo "  nothing to export — writing an empty file to clear the drop\n";
}

$file = PowerSchoolStaffExporter::writeFile(
    PowerSchoolStaffExporter::render(PowerSchoolStaffExporter::HEADERS, $result['rows']),
    $outDir, PowerSchoolStaffExporter::EXPORT_FILE);
printf("  wrote %s (%d bytes, %d row(s))\n", $file['path'], $file['bytes'], $summary['rows']);
PowerSchoolStaffExporter::writeFile(
    PowerSchoolStaffExporter::sample(PowerSchoolStaffExporter::HEADERS, $result['rows']),
    $outDir, PowerSchoolStaffExporter::SAMPLE_FILE);
$exceptions = PowerSchoolStaffExporter::writeFile(
    PowerSchoolStaffExporter::exceptionsFile($result['exceptions']),
    $outDir, PowerSchoolStaffExporter::EXCEPTIONS_FILE);
printf("  wrote %s (%d exception(s))\n", $exceptions['path'], $summary['exceptions']);

if (!$doUpload) {
    echo "  upload skipped (--no-upload)\n";
    exit(0);
}

$remoteDir = trim((string) Config::get('SFTP_PS_EXPORT_DIR', ''));
if ($remoteDir === '') {
    fwrite(STDERR, "  SFTP_PS_EXPORT_DIR is not set — file written but NOT uploaded.\n");
    exit(1);
}
try {
    $client = FeedSync::clientFromConfig();
    $remotePath = PowerSchoolStaffExporter::uploadFile($client, $file['path'], $remoteDir);
    printf("  uploaded to sftp://%s%s\n", (string) Config::get('SFTP_HOST', ''), $remotePath);
} catch (\Throwable $e) {
    fwrite(STDERR, '  SFTP upload failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
