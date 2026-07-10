<?php

declare(strict_types=1);

/**
 * Reconcile the golden record to Google Workspace, directly (bypassing OneSync).
 *   php bin/sync_google.php [--dry-run] [--verbose|-v]
 *   php bin/sync_google.php --only=1234,1250 --verbose   # test a few users live
 *   php bin/sync_google.php --employee=T9001 --dry-run    # target by employee id
 *
 * Creates missing accounts (active people with a golden email), pushes name
 * drift, and suspends accounts for disabled/terminated people. Never
 * auto-restores. Config-gated on GOOGLE_DIRECT_ENABLED + GOOGLE_SA_*; honors the
 * GOOGLE_SYNC_MAX_RATIO guardrail. Intended to run on a nightly timer.
 *
 * --verbose streams one line per account acted on: the planned action (and, on a
 * real run, whether it succeeded) — useful for spotting which accounts a run
 * touched or why one failed, beyond the summary counts.
 *
 * --only / --employee restrict the run to a handful of people so you can exercise
 * the Google provisioning live (or in dry-run) without syncing everyone:
 *   --only=IDs       Restrict to these person_ids (comma list).
 *   --employee=IDs   Same, but select by employee_id (resolved to person_ids).
 */

use App\Db;
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

// Test-cohort targeting: restrict to specific person_ids (--only) and/or
// employee_ids (--employee, resolved to person_ids). Lets an operator exercise
// the Google business rules against a few real accounts without syncing everyone.
$onlyIds = [];
foreach (array_filter(array_map('trim', explode(',', (string) ($opts['only'] ?? '')))) as $id) {
    $onlyIds[] = (int) $id;
}
$employeeIds = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['employee'] ?? '')))));
if ($employeeIds !== []) {
    $lookupDb = Db::connect(Db::ROLE_APP);
    $ph = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmt = $lookupDb->prepare("SELECT person_id FROM person WHERE employee_id IN ({$ph})");
    $stmt->execute($employeeIds);
    foreach ($stmt->fetchAll() as $r) {
        $onlyIds[] = (int) $r['person_id'];
    }
    if ($onlyIds === []) {
        fwrite(STDERR, 'No person matched --employee=' . $opts['employee'] . "\n");
        exit(1);
    }
}
$onlyIds = array_values(array_unique($onlyIds));
if ($onlyIds !== []) {
    echo 'Restricted to ' . count($onlyIds) . ' person(s): ' . implode(', ', $onlyIds) . "\n";
}

// --verbose: stream one line per person as it's scanned (the scan is slow — a
// live remote lookup each — so we show progress rather than sit silent), plus
// each action's result on a real run. Each line is flushed immediately so it
// appears live even when stdout is piped/redirected (block-buffered).
$log = $verbose ? static function (string $event, array $d) use ($dryRun): void {
    if ($event === 'start') {
        $n = (int) $d['total'];
        fwrite(STDOUT, "Scanning {$n} eligible " . ($n === 1 ? 'person' : 'people') . "…\n");
    } elseif ($event === 'scan') {
        $email = ($d['email'] ?? '') !== '' ? (string) $d['email'] : '(no email)';
        if (($d['bucket'] ?? '') === 'error') {
            $note = 'ERROR' . (($d['message'] ?? '') !== '' ? ': ' . (string) $d['message'] : '');
        } elseif (($d['action'] ?? null) !== null) {
            $note = $dryRun ? 'would ' . (string) $d['action'] : (string) $d['action'] . ' (planned)';
        } else {
            $note = str_replace('_', '-', (string) $d['bucket']);
        }
        fwrite(STDOUT, sprintf("  #%-6d %-30s %s\n", (int) $d['person_id'], $email, $note));
    } elseif ($event === 'result') {
        $email = ($d['email'] ?? '') !== '' ? (string) $d['email'] : '(no email)';
        $status = !empty($d['ok']) ? 'ok' : 'FAILED';
        $msg = ($d['message'] ?? '') !== '' ? ' — ' . (string) $d['message'] : '';
        fwrite(STDOUT, sprintf("    -> %-8s %s: %s%s\n", (string) $d['action'], $email, $status, $msg));
    }
    fflush(STDOUT);
} : null;

try {
    $result = (new GoogleSync())->run($dryRun, 'system:google_sync', $log, $onlyIds);
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
echo "  eligible {$c['eligible']}  ·  created {$c['created']}  ·  pushed {$c['pushed']}  ·  suspended {$c['suspended']}  ·  moved {$c['moved']}\n";
echo "  in-sync {$c['in_sync']}  ·  no-email {$c['no_email']}  ·  no-account {$c['no_account']}"
    . "  ·  manual-override {$c['manual_override']}  ·  errors {$c['errors']}\n";
if ($result['blocked']) {
    fwrite(STDERR, ($result['note'] ?? 'Run blocked by the threshold guardrail.') . "\n");
    exit(1);
}

exit($c['errors'] > 0 ? 1 : 0);
