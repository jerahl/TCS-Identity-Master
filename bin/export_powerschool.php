<?php

declare(strict_types=1);

/**
 * Export the staff roster for PowerSchool as two tab-delimited files and
 * upload them to the district SFTP server. Files are always written under the
 * SAME names (each run overwrites the last, so the PowerSchool scheduled
 * imports can point at constant file names):
 *
 *   ps_staff_demographics.txt  Data Import Manager -> USERSCOREFIELDS, one row
 *                              per staff member, matched on USERS.TeacherNumber
 *   ps_staff_assignments.txt   AutoComm/Quick Import -> Teachers view, one row
 *                              per staff member per school assignment
 *
 * Also written locally (never uploaded):
 *   ps_staff_demographics_sample.txt / ps_staff_assignments_sample.txt
 *                              3-row samples for a manual test import
 *   ps_staff_exceptions.txt    rejected rows, truncations, unmapped types
 *
 *   php bin/export_powerschool.php                     write files + upload
 *   php bin/export_powerschool.php --dry-run           print summary + exceptions only
 *   php bin/export_powerschool.php --no-upload         write the files, skip SFTP
 *   php bin/export_powerschool.php --demographics-only only file 1
 *   php bin/export_powerschool.php --assignments-only  only file 2
 *   php bin/export_powerschool.php --out=DIR           override EXPORT_POWERSCHOOL_DIR
 *
 * Local files land in EXPORT_POWERSCHOOL_DIR; uploads go to SFTP_PS_EXPORT_DIR
 * on the same SFTP server the feed pull uses (SFTP_HOST / SFTP_USER / key).
 * See docs/powerschool-staff-export.md for the column contract and the
 * PowerSchool-side import setup.
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
$wantDemographics = !isset($opts['assignments-only']);
$wantAssignments = !isset($opts['demographics-only']);
$outDir = isset($opts['out']) && $opts['out'] !== '1'
    ? $opts['out']
    : trim((string) Config::get('EXPORT_POWERSCHOOL_DIR', ''));

$exporter = new PowerSchoolStaffExporter();
$result = $exporter->export();
$summary = $result['summary'];

printf("PowerSchool staff export%s\n", $dryRun ? '  (DRY RUN)' : '');
printf("  demographics: %d row(s)\n", $summary['demographics']);
printf("  assignments:  %d row(s) across %d school(s)\n", $summary['assignments'], $summary['schools']);
printf("  exceptions:   %d (rejected / truncated / unmapped — see below)\n", $summary['exceptions']);
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

$uploads = [];
if ($wantDemographics) {
    $content = PowerSchoolStaffExporter::render(
        PowerSchoolStaffExporter::DEMOGRAPHIC_HEADERS, $result['demographics']);
    $f = PowerSchoolStaffExporter::writeFile($content, $outDir, PowerSchoolStaffExporter::DEMOGRAPHICS_FILE);
    $uploads[] = $f;
    printf("  wrote %s (%d bytes, %d row(s))\n", $f['path'], $f['bytes'], $summary['demographics']);
    PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::sample(PowerSchoolStaffExporter::DEMOGRAPHIC_HEADERS, $result['demographics']),
        $outDir, PowerSchoolStaffExporter::DEMOGRAPHICS_SAMPLE_FILE);
}
if ($wantAssignments) {
    $content = PowerSchoolStaffExporter::render(
        PowerSchoolStaffExporter::ASSIGNMENT_HEADERS, $result['assignments']);
    $f = PowerSchoolStaffExporter::writeFile($content, $outDir, PowerSchoolStaffExporter::ASSIGNMENTS_FILE);
    $uploads[] = $f;
    printf("  wrote %s (%d bytes, %d row(s))\n", $f['path'], $f['bytes'], $summary['assignments']);
    PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::sample(PowerSchoolStaffExporter::ASSIGNMENT_HEADERS, $result['assignments']),
        $outDir, PowerSchoolStaffExporter::ASSIGNMENTS_SAMPLE_FILE);
}
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
    fwrite(STDERR, "  SFTP_PS_EXPORT_DIR is not set — file(s) written but NOT uploaded.\n");
    exit(1);
}
try {
    $client = FeedSync::clientFromConfig();
    foreach ($uploads as $f) {
        $remotePath = PowerSchoolStaffExporter::uploadFile($client, $f['path'], $remoteDir);
        printf("  uploaded to sftp://%s%s\n", (string) Config::get('SFTP_HOST', ''), $remotePath);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '  SFTP upload failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
