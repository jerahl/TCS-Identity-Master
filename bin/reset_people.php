<?php

declare(strict_types=1);

/**
 * Reset imported person data so you can test a clean import.
 *
 *   php bin/reset_people.php                 show row counts, change nothing
 *   php bin/reset_people.php --dry-run       same as above (explicit)
 *   php bin/reset_people.php --yes           TRUNCATE the person-data tables
 *   php bin/reset_people.php --yes --include-feed-log
 *                                            also clear feed_fetch_log so the
 *                                            SFTP fetcher re-downloads feeds
 *
 * CLEARS (imported / derived person data):
 *   person, person_source_id, assignment, lifecycle_event, audit_log,
 *   import_batch, staging_record, match_candidate, onesync_writeback,
 *   account_sync_status, account_sync_event
 *
 * PRESERVES (reference + config — never touched):
 *   school, school_code_alias, ethnicity_map, app_user, feed_fetch_log
 *   (feed_fetch_log only cleared with --include-feed-log)
 *
 * Destructive: requires --yes to actually run. Runs as the MIGRATE role
 * (TRUNCATE needs the DROP privilege). This is a TEST/dev convenience — it does
 * not write an audit trail of its own deletions.
 */

use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true) || !in_array('--yes', $args, true);
$includeFeedLog = in_array('--include-feed-log', $args, true);

// Child-before-parent order so FK constraints are satisfied even if we didn't
// disable them. (We disable them anyway for a clean auto_increment reset.)
$tables = [
    'match_candidate',
    'staging_record',
    'import_batch',
    'account_sync_event',
    'account_sync_status',
    'onesync_writeback',
    'assignment',
    'person_source_id',
    'lifecycle_event',
    'audit_log',
    'person',
];
if ($includeFeedLog) {
    $tables[] = 'feed_fetch_log';
}

try {
    $pdo = Db::connect(Db::ROLE_MIGRATE);

    echo "Person-data tables to clear (preserving school, school_code_alias, ethnicity_map, app_user"
        . ($includeFeedLog ? '' : ', feed_fetch_log') . "):\n";
    $total = 0;
    foreach ($tables as $t) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $total += $n;
        printf("  %-22s %8d row(s)\n", $t, $n);
    }
    echo "  " . str_repeat('-', 32) . "\n";
    printf("  %-22s %8d row(s)\n", 'TOTAL', $total);

    if ($dryRun) {
        echo "\nDry run — nothing cleared. Re-run with --yes to TRUNCATE the tables above.\n";
        if (!$includeFeedLog) {
            echo "Add --include-feed-log to also clear feed_fetch_log (forces feeds to re-download).\n";
        }
        exit(0);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
        echo "  cleared {$t}\n";
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "\nDone — {$total} row(s) cleared across " . count($tables) . " table(s). Reference data and app_user untouched.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}
