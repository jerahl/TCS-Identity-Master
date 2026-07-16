<?php

declare(strict_types=1);

/**
 * Direct-AD provisioning reconciler: create / edit / disable Active Directory
 * accounts from the golden record through the Adaxes REST API, bypassing OneSync
 * (see docs/adaxes-provisioning-design.md). IDM is the writer.
 *
 * --dry-run doubles as the change report: it reads live AD and prints, per
 * person, what is currently set vs. what the sync would change — edits, moves,
 * and leaver expirations render as "current → proposed" (e.g.
 * `userPrincipalName: stale@x → new@x`, `move OU=OldBuilding,… → OU=CO,…`,
 * `accountExpires: Never → 2026-07-13`), and groups show the +add/-remove delta.
 * Nothing is written.
 *
 * The "disable" phase expires leavers rather than flipping accountDisabled: it
 * sets accountExpires to the person's end date when one is set (otherwise today)
 * and stamps `description` with "Account expired set by TCS-IDM on {run date}".
 *
 *   php bin/adaxes_sync.php --dry-run                 # preview everything, write nothing
 *   php bin/adaxes_sync.php --dry-run --phases=disable # preview one phase
 *   php bin/adaxes_sync.php --phases=disable,edit      # apply (requires ADAXES_WRITE_ENABLED=true)
 *   php bin/adaxes_sync.php                            # apply all phases
 *   php bin/adaxes_sync.php --dry-run --verbose        # stream per-person progress
 *   php bin/adaxes_sync.php --only=1234,1250 --verbose # test a few users live
 *   php bin/adaxes_sync.php --employee=T9001 --dry-run # target by employee id
 *
 * Options:
 *   --dry-run           Compute and print intended writes; change nothing.
 *   --phases=a,b,c      Comma list of disable,edit,create,groups (default: all).
 *   --limit=N           Cap people examined per phase (default: all).
 *   --only=IDs          Restrict to these person_ids (comma list) — for testing a
 *                       handful of accounts live (Business Rules included) without
 *                       touching anyone else.
 *   --employee=IDs      Same, but select by employee_id (resolved to person_ids).
 *   --verbose, -v       Stream one line per person as each outcome is decided
 *                       (flushed live), instead of only the batch summary.
 *
 * Writes are OFF unless ADAXES_WRITE_ENABLED=true and a write credential is set;
 * without those a non-dry-run still changes nothing and says so. Exit code is 0
 * on success, 1 if any write errored, 2 on a usage/config problem.
 */

use App\Service\AdaxesService;
use App\Service\AdaxesWriter;
use App\Service\RunLogRecorder;
use App\Service\ServiceRunLog;
use App\Sync\AdaxesReconciler;

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
$dryRun  = isset($opts['dry-run']);
$verbose = isset($opts['verbose']);
$limit   = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;

// --verbose: stream a header per phase and one line per person as its outcome is
// decided. verify()/search() are live remote lookups, so a large run is slow —
// this shows progress rather than sitting silent. Each line is flushed
// immediately so it appears live even when stdout is piped/redirected.
$console = $verbose ? static function (string $event, array $d): void {
    if ($event === 'phase') {
        $n = (int) $d['total'];
        fwrite(STDOUT, sprintf("\n== %s == (%d %s to examine)\n", strtoupper((string) $d['phase']), $n, $n === 1 ? 'person' : 'people'));
    } elseif ($event === 'item') {
        fwrite(STDOUT, sprintf("  [%-16s] #%-6d %-28s %s\n",
            strtoupper((string) $d['outcome']), (int) $d['person_id'], substr((string) $d['name'], 0, 28), (string) $d['detail']));
    }
    fflush(STDOUT);
} : null;

$phases = ['disable', 'edit', 'create', 'groups'];
if (isset($opts['phases'])) {
    $requested = array_values(array_filter(array_map('trim', explode(',', (string) $opts['phases']))));
    $unknown = array_diff($requested, $phases);
    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown phase(s): ' . implode(', ', $unknown) . ". Valid: disable, edit, create, groups.\n");
        exit(2);
    }
    $phases = $requested;
}

try {
    $db = \App\Db::connect(\App\Db::ROLE_APP);

    // Test-cohort targeting: restrict to specific person_ids (--only) and/or
    // employee_ids (--employee, resolved to person_ids). Lets an operator exercise
    // the full pipeline (and the Adaxes Business Rules a real write triggers)
    // against a handful of test accounts without syncing everyone.
    $onlyIds = [];
    foreach (array_filter(array_map('trim', explode(',', (string) ($opts['only'] ?? '')))) as $id) {
        $onlyIds[] = (int) $id;
    }
    $employeeIds = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['employee'] ?? '')))));
    if ($employeeIds !== []) {
        $ph = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $db->prepare("SELECT person_id FROM person WHERE employee_id IN ({$ph})");
        $stmt->execute($employeeIds);
        foreach ($stmt->fetchAll() as $r) {
            $onlyIds[] = (int) $r['person_id'];
        }
        if ($onlyIds === []) {
            fwrite(STDERR, 'No person matched --employee=' . $opts['employee'] . "\n");
            exit(2);
        }
    }
    $onlyIds = array_values(array_unique($onlyIds));
    if ($onlyIds !== []) {
        echo 'Restricted to ' . count($onlyIds) . ' person(s): ' . implode(', ', $onlyIds) . "\n";
    }

    // Record the run so the admin "Services" page shows when AD last synced and
    // its outcome. Dry runs change nothing, so they aren't recorded.
    $runLog = $dryRun ? null : new ServiceRunLog();
    $runId  = $runLog?->start('adaxes', 'cron', 'system:adaxes_sync');

    // Persist each person's decided outcome (errors, review, changes) as the
    // run's detailed log — what the web console's Outputs log view shows —
    // alongside the optional --verbose console stream. No-op on a dry run.
    $recorder = new RunLogRecorder($runLog, $runId);
    $log = static function (string $event, array $d) use ($recorder, $console): void {
        $recorder->adaxes($event, $d);
        if ($console !== null) {
            $console($event, $d);
        }
    };

    $reconciler = new AdaxesReconciler($db, new AdaxesService(), new AdaxesWriter());
    $result = $reconciler->run($dryRun, $phases, $limit, $log, $onlyIds);
} catch (\Throwable $e) {
    if (isset($runLog, $runId)) {
        $runLog?->finish($runId, 'failed', [], $e->getMessage());
    }
    fwrite(STDERR, 'Adaxes reconcile failed: ' . $e->getMessage() . "\n");
    exit(2);
}

echo 'Adaxes reconcile' . ($dryRun ? ' (DRY RUN)' : '')
   . ($result['write_enabled'] ? '' : ' [writes OFF]') . "\n";
foreach ($result['notes'] as $note) {
    echo "  note: {$note}\n";
}

foreach (['disable', 'edit', 'create', 'groups'] as $phase) {
    if (!isset($result[$phase])) {
        continue;
    }
    $r = $result[$phase];
    echo "\n== " . strtoupper($phase) . " ==\n";
    if (!empty($r['blocked'])) {
        echo "  ** BLOCKED by threshold valve **\n";
    }
    // In verbose mode the items already streamed live as they were decided; here
    // we print only the rolled-up summary to avoid repeating every line.
    if (!$verbose) {
        foreach ($r['items'] as $it) {
            printf("  [%-16s] %-28s %s\n", strtoupper($it['outcome']), substr($it['name'], 0, 28), $it['detail']);
        }
    }
    $summary = [];
    foreach (['candidates', 'applied', 'added', 'removed', 'edited', 'created', 'correlated', 'rehired', 'noop', 'review', 'capped', 'skipped', 'errors'] as $k) {
        if (isset($r[$k])) {
            $summary[] = "{$k} {$r[$k]}";
        }
    }
    echo '  ' . implode('  ·  ', $summary) . "\n";
}

echo "\nTotal errors: {$result['errors']}\n";

// Close the run row with a compact per-phase counts summary.
if ($runLog !== null) {
    $counts = ['errors' => (int) $result['errors'], 'write_enabled' => !empty($result['write_enabled']), 'phases' => []];
    foreach (['disable', 'edit', 'create', 'groups'] as $phase) {
        if (isset($result[$phase])) {
            // Structured per-phase counts (drop the per-person 'items' list) so the
            // Services page can render a full summary; plus a few flat keys for
            // quick reads / backward compatibility.
            $phaseCounts = [];
            foreach ($result[$phase] as $k => $v) {
                if (is_int($v) || is_bool($v)) {
                    $phaseCounts[$k] = is_bool($v) ? (int) $v : $v;
                }
            }
            $counts['phases'][$phase] = $phaseCounts;
            foreach (['applied', 'added', 'removed', 'created', 'correlated', 'rehired', 'errors'] as $k) {
                if (isset($result[$phase][$k]) && $result[$phase][$k] > 0) {
                    $counts["{$phase}_{$k}"] = (int) $result[$phase][$k];
                }
            }
        }
    }
    $summary = 'phases ' . implode(',', $phases) . ' · errors ' . (int) $result['errors']
        . (!empty($result['write_enabled']) ? '' : ' · writes OFF');
    $runLog->finish($runId, $result['errors'] > 0 ? 'failed' : 'complete', $counts, $summary);
}

exit($result['errors'] > 0 ? 1 : 0);
