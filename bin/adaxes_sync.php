<?php

declare(strict_types=1);

/**
 * Direct-AD provisioning reconciler: create / edit / disable Active Directory
 * accounts from the golden record through the Adaxes REST API, bypassing OneSync
 * (see docs/adaxes-provisioning-design.md). IDM is the writer.
 *
 *   php bin/adaxes_sync.php --dry-run                 # preview everything, write nothing
 *   php bin/adaxes_sync.php --dry-run --phases=disable # preview one phase
 *   php bin/adaxes_sync.php --phases=disable,edit      # apply (requires ADAXES_WRITE_ENABLED=true)
 *   php bin/adaxes_sync.php                            # apply all phases
 *   php bin/adaxes_sync.php --dry-run --verbose        # stream per-person progress
 *
 * Options:
 *   --dry-run           Compute and print intended writes; change nothing.
 *   --phases=a,b,c      Comma list of disable,edit,create (default: all three).
 *   --limit=N           Cap people examined per phase (default: all).
 *   --verbose, -v       Stream one line per person as each outcome is decided
 *                       (flushed live), instead of only the batch summary.
 *
 * Writes are OFF unless ADAXES_WRITE_ENABLED=true and a write credential is set;
 * without those a non-dry-run still changes nothing and says so. Exit code is 0
 * on success, 1 if any write errored, 2 on a usage/config problem.
 */

use App\Service\AdaxesService;
use App\Service\AdaxesWriter;
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
$log = $verbose ? static function (string $event, array $d): void {
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
    $reconciler = new AdaxesReconciler($db, new AdaxesService(), new AdaxesWriter());
    $result = $reconciler->run($dryRun, $phases, $limit, $log);
} catch (\Throwable $e) {
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
    foreach (['candidates', 'applied', 'added', 'removed', 'edited', 'created', 'noop', 'review', 'capped', 'skipped', 'errors'] as $k) {
        if (isset($r[$k])) {
            $summary[] = "{$k} {$r[$k]}";
        }
    }
    echo '  ' . implode('  ·  ', $summary) . "\n";
}

echo "\nTotal errors: {$result['errors']}\n";
exit($result['errors'] > 0 ? 1 : 0);
