<?php

declare(strict_types=1);

/**
 * Export staff changes for PowerSchool as CSVs and upload them to the district
 * SFTP server. Two files:
 *   ps_new_staff_*.csv     NEW people (ALSDE ID set, not in PowerSchool yet)
 *   ps_name_updates_*.csv  name changes for people already in PowerSchool,
 *                          keyed by Users.TeacherNumber (= Employee ID)
 *
 *   php bin/export_powerschool.php                write CSVs + upload to SFTP
 *   php bin/export_powerschool.php --dry-run      list what would be exported
 *   php bin/export_powerschool.php --no-upload    write the CSVs, skip SFTP
 *   php bin/export_powerschool.php --new-only     only the new-staff file
 *   php bin/export_powerschool.php --updates-only only the name-update file
 *   php bin/export_powerschool.php --out=DIR      override EXPORT_POWERSCHOOL_DIR
 *
 * Local files land in EXPORT_POWERSCHOOL_DIR; uploads go to SFTP_PS_EXPORT_DIR
 * on the same SFTP server the feed pull uses (SFTP_HOST / SFTP_USER / key).
 * Column headers are the data-dictionary table.field names (see
 * docs/powerschool-staff-export.md) so the files map 1:1 in PowerSchool's Data
 * Import Manager. New hires missing an ALSDE ID — and name changes missing an
 * employee id to match on — are listed and held back, never silently dropped.
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
$wantNew = !isset($opts['updates-only']);
$wantUpdates = !isset($opts['new-only']);
$outDir = isset($opts['out']) && $opts['out'] !== '1'
    ? $opts['out']
    : trim((string) Config::get('EXPORT_POWERSCHOOL_DIR', ''));

$exporter = new PowerSchoolStaffExporter();
$candidates = $wantNew ? $exporter->candidates() : [];
$held = $wantNew ? $exporter->missingAlsdeId() : [];
$nameChanges = $wantUpdates ? $exporter->nameUpdates() : ['updates' => [], 'held' => []];

printf("PowerSchool staff export%s\n", $dryRun ? '  (DRY RUN)' : '');
if ($wantNew) {
    printf("  new staff:    %d ready (ALSDE ID set), %d held back (no ALSDE ID)\n",
        count($candidates), count($held));
}
if ($wantUpdates) {
    printf("  name updates: %d changed, %d held back (no employee id to match on)\n",
        count($nameChanges['updates']), count($nameChanges['held']));
}
echo "\n";

foreach ($candidates as $p) {
    printf("  + %-30s emp %-10s ALSDE %s\n",
        $p['last_name'] . ', ' . $p['first_name'], (string) $p['employee_id'], (string) $p['alsde_id']);
}
foreach ($held as $p) {
    printf("  ! %-30s emp %-10s — no ALSDE ID, not exported\n",
        $p['last_name'] . ', ' . $p['first_name'], (string) $p['employee_id']);
}
foreach ($nameChanges['updates'] as $p) {
    printf("  ~ %-30s emp %-10s was %s\n",
        $p['last_name'] . ', ' . $p['first_name'], (string) $p['employee_id'],
        $p['ps_last'] . ', ' . $p['ps_first']);
}
foreach ($nameChanges['held'] as $p) {
    printf("  ! %-30s (was %s) — no employee id, name change not exported\n",
        $p['last_name'] . ', ' . $p['first_name'], $p['ps_last'] . ', ' . $p['ps_first']);
}

if ($dryRun) {
    exit(0);
}
if ($candidates === [] && $nameChanges['updates'] === []) {
    echo "\nNothing to export.\n";
    exit(0);
}
if ($outDir === '') {
    fwrite(STDERR, "\nEXPORT_POWERSCHOOL_DIR is not set (or pass --out=DIR).\n");
    exit(1);
}

$stamp = date('Ymd_His');
$files = [];
if ($candidates !== []) {
    $files[] = PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::csv($candidates), $outDir, "ps_new_staff_{$stamp}.csv");
    printf("\n  wrote %s (%d bytes, %d row(s))\n", $files[0]['path'], $files[0]['bytes'], count($candidates));
}
if ($nameChanges['updates'] !== []) {
    $f = PowerSchoolStaffExporter::writeFile(
        PowerSchoolStaffExporter::updatesCsv($nameChanges['updates']), $outDir, "ps_name_updates_{$stamp}.csv");
    $files[] = $f;
    printf("%s  wrote %s (%d bytes, %d row(s))\n",
        count($files) === 1 ? "\n" : '', $f['path'], $f['bytes'], count($nameChanges['updates']));
}

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
    foreach ($files as $f) {
        $remotePath = PowerSchoolStaffExporter::uploadFile($client, $f['path'], $remoteDir);
        printf("  uploaded to sftp://%s%s\n", (string) Config::get('SFTP_HOST', ''), $remotePath);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '  SFTP upload failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
