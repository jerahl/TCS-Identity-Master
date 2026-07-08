<?php

declare(strict_types=1);

/**
 * Reconcile the golden record to Google Workspace, directly (bypassing OneSync).
 *   php bin/sync_google.php [--dry-run]
 *
 * Creates missing accounts (active people with a golden email), pushes name
 * drift, and suspends accounts for disabled/terminated people. Never
 * auto-restores. Config-gated on GOOGLE_DIRECT_ENABLED + GOOGLE_SA_*; honors the
 * GOOGLE_SYNC_MAX_RATIO guardrail. Intended to run on a nightly timer.
 */

use App\Sync\GoogleSync;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);

try {
    $result = (new GoogleSync())->run($dryRun, 'system:google_sync');
} catch (\Throwable $e) {
    fwrite(STDERR, 'Google sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$result['configured']) {
    fwrite(STDERR, ($result['note'] ?? 'Direct Google provisioning is off.') . "\n");
    exit(1);
}

$c = $result['counts'];
echo 'Google Workspace sync' . ($result['dry_run'] ? " (DRY RUN)\n" : "\n");
echo "  eligible {$c['eligible']}  ·  created {$c['created']}  ·  pushed {$c['pushed']}  ·  suspended {$c['suspended']}\n";
echo "  in-sync {$c['in_sync']}  ·  no-email {$c['no_email']}  ·  no-account {$c['no_account']}"
    . "  ·  manual-override {$c['manual_override']}  ·  errors {$c['errors']}\n";
if ($result['blocked']) {
    fwrite(STDERR, ($result['note'] ?? 'Run blocked by the threshold guardrail.') . "\n");
    exit(1);
}

exit($c['errors'] > 0 ? 1 : 0);
