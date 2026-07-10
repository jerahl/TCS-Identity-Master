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

    /**
     * A GoogleSync whose GAM runner reports one specific found user (as an
     * `info user … formatjson` JSON object) — for exercising OU drift and the
     * disabled-OU move. Search (`print users`) stays empty.
     *
     * @param array<string,mixed> $user
     */
    private function syncWithUser(PDO $db, array $user): GoogleSync
    {
        $runner = static function (array $argv) use ($user): ?array {
            if (in_array('info', $argv, true) && in_array('user', $argv, true)) {
                return ['status' => 0, 'stdout' => (string) json_encode($user), 'stderr' => ''];
            }
            if (in_array('print', $argv, true)) {
                return ['status' => 0, 'stdout' => "primaryEmail,JSON\n", 'stderr' => ''];
            }
            // Writes (update/move/suspend) succeed and echo the key back.
            return ['status' => 0, 'stdout' => (string) json_encode($user), 'stderr' => ''];
        };
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: $runner);
        $google = new GoogleWorkspaceService(enabled: true, fetch: fn() => self::fail('gam mode must not use HTTP'), gam: $gam);
        return new GoogleSync($db, new GoogleProvisioner($db, $google));
    }

    public function testDisabledSuspendedUserInWrongOuIsMovedToDisabledOu(): void
    {
        $db = $this->db();
        $db->exec("UPDATE person SET status='disabled' WHERE person_id=1");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1,'google','G-1',1)");

        // Suspended already, but sitting in a building OU rather than the disabled OU.
        $user = ['id' => 'G-1', 'primaryEmail' => 'jsmith@x.org', 'suspended' => true,
                 'orgUnitPath' => '/tcs/faculty/CO', 'name' => ['givenName' => 'John', 'familyName' => 'Smith']];
        $result = $this->syncWithUser($db, $user)->run(dryRun: true, actor: 'tester', onlyPersonIds: [1]);

        self::assertSame(1, $result['counts']['moved']);
        self::assertSame(0, $result['counts']['suspended']);
        self::assertSame('move_disabled', $result['actions'][0]['action']);
    }

    public function testSuspendedUserAlreadyInDisabledOuIsInSync(): void
    {
        $db = $this->db();
        $db->exec("UPDATE person SET status='terminated' WHERE person_id=1");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1,'google','G-1',1)");

        $user = ['id' => 'G-1', 'primaryEmail' => 'jsmith@x.org', 'suspended' => true,
                 'orgUnitPath' => '/tcs/faculty/disabled', 'name' => ['givenName' => 'John', 'familyName' => 'Smith']];
        $result = $this->syncWithUser($db, $user)->run(dryRun: true, actor: 'tester', onlyPersonIds: [1]);

        self::assertSame(0, $result['counts']['moved']);
        self::assertSame(1, $result['counts']['in_sync']);
        self::assertSame([], $result['actions']);
    }

    public function testActiveUserWithOuDriftIsPushed(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, google_ou TEXT)');
        $db->exec("INSERT INTO school (school_id, name, google_ou) VALUES (7, 'Central Office', '/tcs/faculty/CO')");
        $db->exec("UPDATE person SET primary_school_id=7 WHERE person_id=1");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1,'google','G-1',1)");

        // Active, name matches golden (John Smith), but the OU has drifted.
        $user = ['id' => 'G-1', 'primaryEmail' => 'jsmith@x.org', 'suspended' => false,
                 'orgUnitPath' => '/tcs/faculty/OLD', 'name' => ['givenName' => 'John', 'familyName' => 'Smith']];
        $result = $this->syncWithUser($db, $user)->run(dryRun: true, actor: 'tester', onlyPersonIds: [1]);

        self::assertSame(1, $result['counts']['pushed']);
        self::assertSame('push', $result['actions'][0]['action']);
    }

    public function testActiveUserInCorrectOuWithNoDriftIsInSync(): void
    {
        $db = $this->db();
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, google_ou TEXT)');
        $db->exec("INSERT INTO school (school_id, name, google_ou) VALUES (7, 'Central Office', '/tcs/faculty/CO')");
        $db->exec("UPDATE person SET primary_school_id=7 WHERE person_id=1");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1,'google','G-1',1)");

        // Case-only OU difference must NOT count as drift.
        $user = ['id' => 'G-1', 'primaryEmail' => 'jsmith@x.org', 'suspended' => false,
                 'orgUnitPath' => '/tcs/faculty/co', 'name' => ['givenName' => 'John', 'familyName' => 'Smith']];
        $result = $this->syncWithUser($db, $user)->run(dryRun: true, actor: 'tester', onlyPersonIds: [1]);

        self::assertSame(0, $result['counts']['pushed']);
        self::assertSame(1, $result['counts']['in_sync']);
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

    public function testOnlyPersonIdsRestrictsTheRunToTheCohort(): void
    {
        // Person 1 (active + golden email) would create; person 2 has no email.
        // Restricting to [2] must examine ONLY person 2 — no create, one eligible.
        $result = $this->sync($this->db())->run(dryRun: true, actor: 'tester', onlyPersonIds: [2]);

        self::assertSame(1, $result['counts']['eligible']);  // just the cohort
        self::assertSame(0, $result['counts']['created']);   // person 1 was excluded
        self::assertSame(1, $result['counts']['no_email']);
        self::assertSame([], $result['actions']);
    }

    public function testOnlyPersonIdsCohortActsOnTheTargetedPerson(): void
    {
        // Restricting to [1] examines only person 1 and plans their create.
        $result = $this->sync($this->db())->run(dryRun: true, actor: 'tester', onlyPersonIds: [1]);

        self::assertSame(1, $result['counts']['eligible']);
        self::assertSame(1, $result['counts']['created']);
        self::assertSame(1, $result['actions'][0]['person_id']);
        self::assertSame('create', $result['actions'][0]['action']);
    }
}
