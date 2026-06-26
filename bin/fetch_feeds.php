<?php

declare(strict_types=1);

/**
 * Pull feed CSVs from the SFTP server and import them.
 *   php bin/fetch_feeds.php                 fetch + import all configured sources
 *   php bin/fetch_feeds.php --source=intern only one source
 *   php bin/fetch_feeds.php --dry-run       list what would be downloaded
 *   php bin/fetch_feeds.php --no-import      download only (don't import)
 *
 * Sources are enabled by setting SFTP_<SOURCE>_DIR. Already-fetched files are
 * skipped (feed_fetch_log). Intended for cron, before the nightly OneSync run.
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

$sources = isset($opts['source']) && $opts['source'] !== '1'
    ? [$opts['source']]
    : FeedSync::configuredSources();

if ($sources === []) {
    fwrite(STDERR, "No SFTP sources configured. Set SFTP_HOST + SFTP_<SOURCE>_DIR in .env.\n");
    exit(1);
}

echo 'Fetching feeds from SFTP: ' . implode(', ', $sources)
    . ($dryRun ? '  (DRY RUN)' : ($doImport ? '' : '  (download only)')) . "\n";

try {
    $result = FeedSync::fromConfig()->run($sources, $dryRun, $doImport);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Fetch failed: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($result['sources'] as $s) {
    if (isset($s['error'])) {
        printf("  [%s] ERROR: %s\n", $s['key'], $s['error']);
        continue;
    }
    printf("  [%s] %d new file(s)\n", $s['key'], $s['downloaded']);
    foreach ($s['files'] as $f) {
        printf("      %-32s %s\n", $f['name'], $f['reason']);
    }
}
$t = $result['totals'];
echo "\n  downloaded {$t['downloaded']}  ·  imported {$t['imported']}  ·  errors {$t['errors']}\n";

exit($t['errors'] > 0 ? 1 : 0);
