<?php

declare(strict_types=1);

/**
 * Export NEW staff (ALSDE ID set, not yet in PowerSchool) as a PowerSchool
 * staff-import CSV and upload it to the district SFTP server.
 *
 *   php bin/export_powerschool.php              write CSV + upload to SFTP
 *   php bin/export_powerschool.php --dry-run    list who would be exported
 *   php bin/export_powerschool.php --no-upload  write the CSV, skip SFTP
 *   php bin/export_powerschool.php --out=DIR    override EXPORT_POWERSCHOOL_DIR
 *
 * Local files land in EXPORT_POWERSCHOOL_DIR; the upload goes to
 * SFTP_PS_EXPORT_DIR on the same SFTP server the feed pull uses (SFTP_HOST /
 * SFTP_USER / key). Column headers are the data-dictionary table.field names
 * (see docs/powerschool-staff-export.md) so the file maps 1:1 in PowerSchool's
 * Data Import Manager. People missing an ALSDE ID are listed and held back.
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
$candidates = $exporter->candidates();
$held = $exporter->missingAlsdeId();

printf("PowerSchool new-staff export%s\n", $dryRun ? '  (DRY RUN)' : '');
printf("  %d ready (ALSDE ID set, not in PowerSchool), %d held back (no ALSDE ID)\n\n", count($candidates), count($held));

foreach ($candidates as $p) {
    printf("  + %-30s emp %-10s ALSDE %s\n",
        $p['last_name'] . ', ' . $p['first_name'], (string) $p['employee_id'], (string) $p['alsde_id']);
}
foreach ($held as $p) {
    printf("  ! %-30s emp %-10s — no ALSDE ID, not exported\n",
        $p['last_name'] . ', ' . $p['first_name'], (string) $p['employee_id']);
}

if ($dryRun) {
    exit(0);
}
if ($candidates === []) {
    echo "\nNothing to export.\n";
    exit(0);
}
if ($outDir === '') {
    fwrite(STDERR, "\nEXPORT_POWERSCHOOL_DIR is not set (or pass --out=DIR).\n");
    exit(1);
}

$file = PowerSchoolStaffExporter::writeFile($candidates, $outDir);
printf("\n  wrote %s (%d bytes, %d row(s))\n", $file['path'], $file['bytes'], count($candidates));

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
    $remotePath = PowerSchoolStaffExporter::uploadFile(FeedSync::clientFromConfig(), $file['path'], $remoteDir);
    printf("  uploaded to sftp://%s%s\n", (string) Config::get('SFTP_HOST', ''), $remotePath);
} catch (\Throwable $e) {
    fwrite(STDERR, '  SFTP upload failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
