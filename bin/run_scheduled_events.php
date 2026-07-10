<?php

declare(strict_types=1);

/**
 * Process due scheduled_event rows (the delayed-events queue): the rename
 * cutover 7 days after approval, the old-alias reminders, and the alias removal
 * ~90 days out. Meant to run on a timer (systemd / cron, e.g. every 15 min).
 *
 *   php bin/run_scheduled_events.php            process everything due now
 *   php bin/run_scheduled_events.php --limit=50
 *   php bin/run_scheduled_events.php --verbose
 *
 * Handlers are idempotent, so a transient failure is retried on the next run
 * (up to ScheduledEventService::MAX_ATTEMPTS, then parked as 'failed'). Exit code
 * is 0 unless one or more events failed this run.
 */

use App\Db;
use App\Service\AdaxesService;
use App\Service\AdaxesWriter;
use App\Service\AuditService;
use App\Service\GoogleWorkspaceService;
use App\Service\Mailer;
use App\Service\RenameEventHandlers;
use App\Service\ScheduledEventRunner;
use App\Service\ScheduledEventService;
use App\Import\PersonWriter;

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
$verbose = isset($opts['verbose']);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 100;

$log = $verbose ? static function (string $ev, array $d): void {
    fwrite(STDOUT, sprintf("  [%-6s] #%-5d %-18s %s\n", strtoupper((string) $d['outcome']), (int) $d['id'], (string) $d['type'], (string) $d['note']));
    fflush(STDOUT);
} : null;

try {
    $db = Db::connect(Db::ROLE_APP);
    $audit = new AuditService($db);
    $events = new ScheduledEventService($db, $audit);

    $google = null;
    try {
        $g = new GoogleWorkspaceService();
        $google = $g->configured() ? $g : null;
    } catch (\Throwable) {
        $google = null; // Google optional
    }

    $handlers = new RenameEventHandlers(
        $db,
        new AdaxesService(),
        new AdaxesWriter(),
        new Mailer($db),
        $events,
        $audit,
        new PersonWriter($db, $audit),
        $google,
    );

    $now = gmdate('Y-m-d H:i:s');
    $result = (new ScheduledEventRunner($events, $handlers->map()))->run($now, $limit, $log);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Scheduled-events run failed: ' . $e->getMessage() . "\n");
    exit(2);
}

echo "Scheduled events: processed {$result['processed']}  ·  done {$result['done']}  ·  failed {$result['failed']}\n";
exit($result['failed'] > 0 ? 1 : 0);
