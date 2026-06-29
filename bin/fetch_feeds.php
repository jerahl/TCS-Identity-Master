<?php

declare(strict_types=1);

/**
 * Pull feed CSVs from the SFTP server and import them, and import PowerSchool
 * directly from its Oracle DB over ODBC.
 *
 *   php bin/fetch_feeds.php                 fetch SFTP feeds + ODBC PowerSchool
 *   php bin/fetch_feeds.php --source=intern only one source (intern via SFTP)
 *   php bin/fetch_feeds.php --source=powerschool   only the ODBC PowerSchool import
 *   php bin/fetch_feeds.php --dry-run       list what would be downloaded/imported
 *   php bin/fetch_feeds.php --no-import      download only (don't import)
 *
 * SFTP sources are enabled by setting SFTP_<SOURCE>_DIR; already-fetched files are
 * skipped (feed_fetch_log). PowerSchool is enabled by setting PS_ODBC_DSN.
 * Intended for cron, before the nightly OneSync run.
 */

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
$doImport = !isset($opts['no-import']);
$sourceOpt = isset($opts['source']) && $opts['source'] !== '1' ? $opts['source'] : null;

// PowerSchool comes from Oracle (ODBC), every other source from SFTP.
$wantPowerSchool = ($sourceOpt === null || $sourceOpt === 'powerschool') && FeedSync::powerSchoolOdbcEnabled();
$sftpSources = $sourceOpt === 'powerschool'
    ? []
    : ($sourceOpt !== null ? [$sourceOpt] : FeedSync::configuredSources());

if ($sftpSources === [] && !$wantPowerSchool) {
    fwrite(STDERR, "Nothing to do. Set SFTP_HOST + SFTP_<SOURCE>_DIR for SFTP feeds, and/or PS_ODBC_* for the PowerSchool Oracle import.\n");
    exit(1);
}

$label = array_merge($sftpSources, $wantPowerSchool ? ['powerschool (odbc)'] : []);
echo 'Fetching feeds: ' . implode(', ', $label)
    . ($dryRun ? '  (DRY RUN)' : ($doImport ? '' : '  (download only)')) . "\n";

$sources = [];
$totals = ['downloaded' => 0, 'imported' => 0, 'errors' => 0];

if ($sftpSources !== []) {
    try {
        $result = FeedSync::fromConfig()->run($sftpSources, $dryRun, $doImport);
        $sources = $result['sources'];
        foreach ($result['totals'] as $k => $v) {
            $totals[$k] += $v;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'SFTP fetch failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// PowerSchool: import straight from Oracle (skipped under --no-import).
if ($wantPowerSchool && $doImport) {
    $ps = FeedSync::importPowerSchoolOdbc($dryRun);
    if ($ps !== null) {
        $sources[] = $ps;
        $totals['imported'] += $ps['imported'];
        $totals['errors'] += $ps['errors'];
    }
}

foreach ($sources as $s) {
    if (isset($s['error'])) {
        printf("  [%s] ERROR: %s\n", $s['key'], $s['error']);
        continue;
    }
    $via = ($s['source'] ?? '') === 'oracle-odbc' ? ' (oracle odbc)' : sprintf(' %d new file(s)', $s['downloaded']);
    printf("  [%s]%s\n", $s['key'], $via);
    foreach ($s['files'] as $f) {
        printf("      %-32s %s\n", $f['name'], $f['reason']);
    }
}
echo "\n  downloaded {$totals['downloaded']}  ·  imported {$totals['imported']}  ·  errors {$totals['errors']}\n";

exit($totals['errors'] > 0 ? 1 : 0);
