<?php

declare(strict_types=1);

/**
 * Reconcile the golden record to Google Workspace, directly (bypassing OneSync).
 *   php bin/sync_google.php [--dry-run] [--verbose|-v]
 *
 * Creates missing accounts (active people with a golden email), pushes name
 * drift, and suspends accounts for disabled/terminated people. Never
 * auto-restores. Config-gated on GOOGLE_DIRECT_ENABLED + GOOGLE_SA_*; honors the
 * GOOGLE_SYNC_MAX_RATIO guardrail. Intended to run on a nightly timer.
 *
 * --verbose streams one line per account acted on: the planned action (and, on a
 * real run, whether it succeeded) — useful for spotting which accounts a run
 * touched or why one failed, beyond the summary counts.
 */

use App\Sync\GoogleSync;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '-v') {
        $opts['verbose'] = '1';
        continue;
    }
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);

// --verbose: stream each planned action, and (on a real run) its result. The
// callback is uncapped, unlike the summary's 50-row `actions` list.
$log = $verbose ? static function (string $event, array $d) use ($dryRun): void {
    $email = ($d['email'] ?? '') !== '' ? (string) $d['email'] : '(no email)';
    if ($event === 'plan') {
        fwrite(STDOUT, sprintf("  %-6s %-8s %s  (person #%d)\n",
            $dryRun ? 'would' : 'plan', (string) $d['action'], $email, (int) $d['person_id']));
    } elseif ($event === 'result') {
        $status = !empty($d['ok']) ? 'ok' : 'FAILED';
        $msg = ($d['message'] ?? '') !== '' ? ' — ' . (string) $d['message'] : '';
        fwrite(STDOUT, sprintf("    -> %-8s %s: %s%s\n", (string) $d['action'], $email, $status, $msg));
    }
} : null;

try {
    $result = (new GoogleSync())->run($dryRun, 'system:google_sync', $log);
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
