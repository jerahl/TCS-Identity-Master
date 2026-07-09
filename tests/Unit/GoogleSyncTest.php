<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GamClient;
use App\Service\GoogleWorkspaceService;
use App\Sync\GoogleProvisioner;
use App\Sync\GoogleSync;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The batch reconciliation planner, and its --verbose streaming hook: run()
 * calls $log('plan', …) as each account's action is decided (uncapped, unlike
 * the returned 50-row summary). Driven in dry-run with a GAM-backed service
 * whose runner reports every account as not-found, so an active person with a
 * golden email plans a create.
 */
final class GoogleSyncTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_uuid TEXT, username TEXT, first_name TEXT, last_name TEXT,
            email TEXT, upn TEXT, employee_id TEXT, status TEXT, person_type TEXT, primary_school_id INTEGER)');
        $db->exec('CREATE TABLE person_source_id (person_id INTEGER, system TEXT, source_key TEXT, is_active INTEGER)');
        $db->exec("INSERT INTO person (person_id, person_uuid, username, first_name, last_name, email, status, person_type) VALUES
                   (1, 'uuid-1', 'jsmith', 'John', 'Smith', 'jsmith@x.org', 'active', 'faculty'),
                   (2, 'uuid-2', '', 'Jane', 'Doe', '', 'active', 'faculty')");   // no golden email -> no action
        return $db;
    }

    /** A GoogleSync whose GAM runner reports every account as not-found. */
    private function sync(PDO $db): GoogleSync
    {
        $runner = static function (array $argv): ?array {
            // Search endpoints return an empty (header-only) result = not found;
            // `info user` returns GAM's "Does not exist" = a clean not-found.
            if (in_array('print', $argv, true)) {
                return ['status' => 0, 'stdout' => "primaryEmail,JSON\n", 'stderr' => ''];
            }
            return ['status' => 60, 'stdout' => '', 'stderr' => 'ERROR: Does not exist'];
        };
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: $runner);
        $google = new GoogleWorkspaceService(enabled: true, fetch: fn() => self::fail('gam mode must not use HTTP'), gam: $gam);
        return new GoogleSync($db, new GoogleProvisioner($db, $google));
    }

    public function testVerboseLogStreamsStartAndPerPersonScan(): void
    {
        $events = [];
        $log = static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        };

        $result = $this->sync($this->db())->run(dryRun: true, actor: 'tester', log: $log);

        self::assertSame(2, $result['counts']['eligible']);
        self::assertSame(1, $result['counts']['created']);   // person 1: active + golden email, no account -> create
        self::assertSame(1, $result['counts']['no_email']);  // person 2: active, no golden email -> no action

        // A 'start' with the total, then one 'scan' per person (not just per action).
        self::assertSame(['start', ['total' => 2]], $events[0]);
        self::assertSame('scan', $events[1][0]);
        self::assertSame(1, $events[1][1]['person_id']);
        self::assertSame('create', $events[1][1]['action']);
        self::assertSame('created', $events[1][1]['bucket']);
        self::assertSame('scan', $events[2][0]);
        self::assertSame(2, $events[2][1]['person_id']);
        self::assertNull($events[2][1]['action']);            // no-op still emits a scan line
        self::assertSame('no_email', $events[2][1]['bucket']);
        self::assertCount(3, $events);
    }

    public function testRunWithoutLogStillPlans(): void
    {
        // The log hook is optional — omitting it must not change the outcome.
        $result = $this->sync($this->db())->run(dryRun: true, actor: 'tester');

        self::assertSame(1, $result['counts']['created']);
        self::assertNotEmpty($result['actions']);
        self::assertSame('create', $result['actions'][0]['action']);
    }
}
