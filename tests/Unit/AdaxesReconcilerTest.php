<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdaxesService;
use App\Service\AdaxesWriter;
use App\Sync\AdaxesReconciler;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AdaxesReconciler — the create/edit/disable engine over a seeded sqlite DB and a
 * faked Adaxes (both the read service and the writer take an injected $fetch).
 * Exercises the guardrails: disable a linked leaver, route an unlinked one to
 * review, create a net-new hire and link its GUID, skip a locked-but-unlinked
 * person, and trip both the disable ratio valve and the create cap.
 */
final class AdaxesReconcilerTest extends TestCase
{
    private const GUID1 = '11111111-1111-1111-1111-111111111111';
    private const GUID2 = '22222222-2222-2222-2222-222222222222';
    private const NEWGUID = '99999999-9999-9999-9999-999999999999';
    private const BASE_DN = 'DC=example,DC=org';

    protected function setUp(): void
    {
        // The reconciler forms container DNs as {ad_ou}[,OU=faculty],{AD_BASE_DN}.
        putenv('AD_BASE_DN=' . self::BASE_DN);
    }

    protected function tearDown(): void
    {
        putenv('AD_BASE_DN');
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_type TEXT DEFAULT \'faculty\', status TEXT,
            first_name TEXT, last_name TEXT,
            preferred_name TEXT, username TEXT UNIQUE, email TEXT UNIQUE, upn TEXT,
            username_locked INTEGER DEFAULT 0, username_assigned_at TEXT,
            employee_id TEXT, primary_school_id INTEGER, end_date TEXT)');
        $db->exec('CREATE TABLE person_source_id (
            id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT, source_key TEXT,
            is_active INTEGER DEFAULT 1, first_seen TEXT, last_seen TEXT)');
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, ad_ou TEXT)');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT,
            detail TEXT, actor TEXT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    /**
     * A read AdaxesService whose fetch answers get-object (by GUID) from $accounts
     * (guid => attributes, or absent = not found) and search POSTs from $searchHit
     * (true = a hit, false = net-new).
     *
     * @param array<string,array<string,mixed>> $accounts
     */
    private function read(array $accounts, bool $searchHit = false): AdaxesService
    {
        $fetch = function (string $method, string $url, array $headers, ?string $body) use ($accounts, $searchHit): ?array {
            if (str_contains($url, '/search')) {
                if (!$searchHit) {
                    return ['status' => 200, 'body' => json_encode(['objects' => []])];
                }
                return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => ['sAMAccountName' => 'hit']]]])];
            }
            // get-object by directoryObject=<guid>
            foreach ($accounts as $guid => $attrs) {
                if (str_contains($url, 'directoryObject=' . $guid)) {
                    $props = [];
                    foreach ($attrs as $k => $v) {
                        $props[] = ['name' => $k, 'value' => $v];
                    }
                    return ['status' => 200, 'body' => json_encode(['properties' => $props])];
                }
            }
            return ['status' => 404, 'body' => 'not found'];
        };
        return new AdaxesService('https://adx.example.org/restv2', '', '', 5, $fetch, null, null, null, null, 'read-token');
    }

    /**
     * A write AdaxesWriter capturing calls into $calls and returning $createGuid on
     * create (null = bare success).
     *
     * @param list<array<string,mixed>> $calls
     */
    private function writer(array &$calls, ?string $createGuid = self::NEWGUID): AdaxesWriter
    {
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$calls, $createGuid): ?array {
            $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            if ($method === 'POST' && !str_contains($url, '/search')) {
                // create
                $payload = $createGuid !== null ? ['objectGUID' => $createGuid] : [];
                return ['status' => 200, 'body' => json_encode($payload)];
            }
            return ['status' => 200, 'body' => '{}']; // modify/disable
        };
        return new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, $fetch, 'write-token', true);
    }

    private function seedPerson(PDO $db, array $cols): void
    {
        $keys = array_keys($cols);
        $sql = 'INSERT INTO person (' . implode(',', $keys) . ') VALUES (:' . implode(',:', $keys) . ')';
        $params = [];
        foreach ($cols as $k => $v) {
            $params[':' . $k] = $v;
        }
        $db->prepare($sql)->execute($params);
    }

    private function link(PDO $db, int $personId, string $guid, int $active = 1): void
    {
        $db->prepare('INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (:p, :s, :k, :a)')
            ->execute([':p' => $personId, ':s' => 'ad', ':k' => $guid, ':a' => $active]);
    }

    // ---- disable ------------------------------------------------------------

    public function testDisablesLinkedLeaverWhoseAdIsStillEnabled(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Jo', 'last_name' => 'Leaver']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'joleaver', 'accountDisabled' => 'false']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));

        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(1, $res['disable']['applied']);
        self::assertSame(0, $res['disable']['errors']);
        self::assertFalse($res['disable']['blocked']);
        // The writer was asked to disable (a PATCH toggling accountDisabled).
        self::assertNotEmpty($calls);
        self::assertSame('PATCH', $calls[0]['method']);
        // A disable lifecycle event was recorded.
        $ev = $db->query("SELECT event_type FROM lifecycle_event WHERE person_id = 1")->fetchColumn();
        self::assertSame('disable', $ev);
    }

    public function testUnlinkedLeaverIsRoutedToReviewNotWritten(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'No', 'last_name' => 'Link']);
        // A non-GUID alias in the crosswalk is NOT a reliable link.
        $this->link($db, 1, 'T12345');

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([]), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(0, $res['disable']['applied']);
        self::assertSame(1, $res['disable']['skipped']);
        self::assertSame('review', $res['disable']['items'][0]['outcome']);
        self::assertSame([], $calls); // never hit the writer
    }

    public function testAlreadyDisabledAdAccountIsANoOp(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Al', 'last_name' => 'Ready']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'already', 'accountDisabled' => 'true']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(0, $res['disable']['applied']);
        self::assertSame(1, $res['disable']['noop']);
        self::assertSame([], $calls);
    }

    public function testDisableRatioValveBlocksMassDisable(): void
    {
        putenv('ADAXES_WRITE_DISABLE_GUARD_MIN=2');
        try {
            $db = $this->db();
            // 3 linked AD accounts total; 2 are leavers still enabled in AD.
            $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'A', 'last_name' => 'One']);
            $this->seedPerson($db, ['person_id' => 2, 'status' => 'disabled', 'first_name' => 'B', 'last_name' => 'Two']);
            $this->seedPerson($db, ['person_id' => 3, 'status' => 'active', 'first_name' => 'C', 'last_name' => 'Three']);
            $this->link($db, 1, self::GUID1);
            $this->link($db, 2, self::GUID2);
            $this->link($db, 3, '33333333-3333-3333-3333-333333333333');

            $calls = [];
            $read = $this->read([
                self::GUID1 => ['sAMAccountName' => 'a', 'accountDisabled' => 'false'],
                self::GUID2 => ['sAMAccountName' => 'b', 'accountDisabled' => 'false'],
            ]);
            $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
            $res = $rec->run(dryRun: false, phases: ['disable']);

            // 2 candidates / 3 linked = 66% > 20% → blocked, nothing written.
            self::assertTrue($res['disable']['blocked']);
            self::assertSame(2, $res['disable']['candidates']);
            self::assertSame(0, $res['disable']['applied']);
            self::assertSame([], $calls);
        } finally {
            putenv('ADAXES_WRITE_DISABLE_GUARD_MIN');
        }
    }

    // ---- edit ---------------------------------------------------------------

    public function testEditPushesDriftedUpnButNeverSamAccountName(): void
    {
        $db = $this->db();
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Ed', 'last_name' => 'It',
            'username' => 'edit', 'email' => 'edit@tusc.k12.al.us', 'upn' => 'edit@tusc.k12.al.us',
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // AD holds a stale UPN (differs) but the sAMAccountName + mail match golden.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'edit',
            'userPrincipalName' => 'stale@tusc.k12.al.us',
            'mail'              => 'edit@tusc.k12.al.us',
            'accountDisabled'   => 'false',
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertSame(1, $res['edit']['applied']);
        self::assertNotEmpty($calls);
        $body = json_decode((string) $calls[0]['body'], true);
        $names = array_column($body['properties'], 'name');
        self::assertContains('userPrincipalName', $names);
        self::assertNotContains('sAMAccountName', $names); // immutable
    }

    public function testEditIsANoOpWhenAdAlreadyMatches(): void
    {
        $db = $this->db();
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'In', 'last_name' => 'Sync',
            'username' => 'insync', 'email' => 'insync@tusc.k12.al.us', 'upn' => 'insync@tusc.k12.al.us',
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'insync',
            'userPrincipalName' => 'insync@tusc.k12.al.us',
            'mail'              => 'insync@tusc.k12.al.us',
            'accountDisabled'   => 'false',
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertSame(0, $res['edit']['applied']);
        self::assertSame(1, $res['edit']['noop']);
        self::assertSame([], $calls);
    }

    // ---- create -------------------------------------------------------------

    public function testCreatesNetNewFacultyHireLinksGuidAndStampsGolden(): void
    {
        $db = $this->db();
        // ad_ou is the relative building OU; the reconciler appends OU=faculty + base.
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'pending',
            'first_name' => 'John', 'last_name' => 'Smith',
            'employee_id' => 'E100', 'primary_school_id' => 5, 'end_date' => '2027-05-31',
        ]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create']);

        self::assertSame(1, $res['create']['applied']);
        self::assertSame(0, $res['create']['errors']);

        $p = $db->query('SELECT * FROM person WHERE person_id = 1')->fetch();
        self::assertSame('jsmith', $p['username']);                 // lowercase
        self::assertSame('jsmith@tusc.k12.al.us', $p['email']);
        self::assertSame('jsmith@tusc.k12.al.us', $p['upn']);
        self::assertSame(1, (int) $p['username_locked']);
        self::assertSame('active', $p['status']); // pending → active

        // GUID linked into the crosswalk.
        $guid = $db->query("SELECT source_key FROM person_source_id WHERE person_id = 1 AND system = 'ad'")->fetchColumn();
        self::assertSame(self::NEWGUID, $guid);

        // The create POST carried the identity core + faculty container + expiry.
        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        self::assertNotNull($create);
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=CO,OU=faculty,DC=example,DC=org', $body['path']);
        $props = [];
        foreach ($body['properties'] as $pr) {
            $props[$pr['name']] = $pr['value'];
        }
        self::assertSame('jsmith', $props['sAMAccountName']);
        self::assertSame('E100', $props['employeeID']);
        // accountExpires = midnight UTC of the end date, as a FILETIME.
        $expectFt = (string) ((strtotime('2027-05-31 00:00:00 UTC') + 11644473600) * 10000000);
        self::assertSame($expectFt, $props['accountExpires']);
    }

    public function testNonFacultyIsPlacedDirectlyUnderBuildingOu(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'staff', 'status' => 'pending',
            'first_name' => 'Sam', 'last_name' => 'Staff', 'primary_school_id' => 5,
        ]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $rec->run(dryRun: false, phases: ['create']);

        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        self::assertNotNull($create);
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=CO,DC=example,DC=org', $body['path']); // no OU=faculty
    }

    public function testCreateSkippedWhenBaseDnMissing(): void
    {
        putenv('AD_BASE_DN'); // unset for this case
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'pending',
            'first_name' => 'John', 'last_name' => 'Smith', 'primary_school_id' => 5,
        ]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create']);

        self::assertSame(0, $res['create']['applied']);
        self::assertSame(1, $res['create']['skipped']);
        self::assertStringContainsString('AD_BASE_DN', $res['create']['items'][0]['detail']);
        self::assertSame([], $calls);
    }

    public function testExistingAdAccountRoutesToReviewInsteadOfCreating(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Jane', 'last_name' => 'Doe',
            'employee_id' => 'E200', 'primary_school_id' => 5,
        ]);

        $calls = [];
        // AD search returns a hit → the account exists but isn't linked.
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: true), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create']);

        self::assertSame(0, $res['create']['applied']);
        self::assertSame(1, $res['create']['review']);
        self::assertSame([], $calls); // never created
    }

    public function testLockedPersonWithoutLinkIsSkippedToReview(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Lock', 'last_name' => 'Ed',
            'username' => 'existing', 'username_locked' => 1, 'primary_school_id' => 5,
        ]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create']);

        self::assertSame(0, $res['create']['applied']);
        self::assertSame(1, $res['create']['review']);
        self::assertSame([], $calls); // never minted/created
    }

    public function testCreateCapDefersOverflow(): void
    {
        putenv('ADAXES_WRITE_MAX_CREATES=1');
        try {
            $db = $this->db();
            $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central', 'OU=CO')");
            $this->seedPerson($db, ['person_id' => 1, 'status' => 'pending', 'first_name' => 'Aaa', 'last_name' => 'One', 'primary_school_id' => 5]);
            $this->seedPerson($db, ['person_id' => 2, 'status' => 'pending', 'first_name' => 'Bbb', 'last_name' => 'Two', 'primary_school_id' => 5]);

            $calls = [];
            $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
            $res = $rec->run(dryRun: false, phases: ['create']);

            self::assertSame(1, $res['create']['applied']);
            self::assertSame(1, $res['create']['capped']);
        } finally {
            putenv('ADAXES_WRITE_MAX_CREATES');
        }
    }

    // ---- dry run & off ------------------------------------------------------

    public function testDryRunWritesNothing(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Dry', 'last_name' => 'Run']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'dryrun', 'accountDisabled' => 'false']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: true, phases: ['disable']);

        self::assertTrue($res['dry_run']);
        self::assertSame('would-disable', $res['disable']['items'][0]['outcome']);
        self::assertSame([], $calls); // read-only preview
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM lifecycle_event')->fetchColumn());
    }

    public function testProgressCallbackStreamsPhaseAndItemEvents(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Jo', 'last_name' => 'Leaver']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'joleaver', 'accountDisabled' => 'false']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));

        $events = [];
        $log = static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        };
        $rec->run(dryRun: true, phases: ['disable'], limit: null, log: $log);

        // A 'phase' header fires first with the count of people to examine, then
        // one 'item' per decided outcome — live, as it happens.
        self::assertSame('phase', $events[0][0]);
        self::assertSame('disable', $events[0][1]['phase']);
        self::assertSame(1, $events[0][1]['total']);

        $items = array_values(array_filter($events, static fn($e) => $e[0] === 'item'));
        self::assertCount(1, $items);
        self::assertSame('would-disable', $items[0][1]['outcome']);
        self::assertSame(1, $items[0][1]['person_id']);
    }

    public function testNonDryRunWithWritesOffChangesNothing(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Off', 'last_name' => 'Switch']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'off', 'accountDisabled' => 'false']]);
        // Writer NOT enabled → configured() false.
        $writerFetch = function (string $m, string $u, array $h, ?string $b) use (&$calls): ?array {
            $calls[] = $u;
            return ['status' => 200, 'body' => '{}'];
        };
        $writer = new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, $writerFetch, 'write-token', false);

        $rec = new AdaxesReconciler($db, $read, $writer);
        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertFalse($res['write_enabled']);
        self::assertSame([], $calls);
        // The candidate was still identified, just not written (would-disable).
        self::assertSame('would-disable', $res['disable']['items'][0]['outcome']);
    }
}
