<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdaxesService;
use App\Service\AdaxesWriter;
use App\Sync\AdaxesReconciler;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
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
            employee_id TEXT, primary_school_id INTEGER, end_date TEXT,
            raptor_group_override TEXT)');
        $db->exec('CREATE TABLE person_source_id (
            id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT, source_key TEXT,
            is_active INTEGER DEFAULT 1, first_seen TEXT, last_seen TEXT)');
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, ad_ou TEXT)');
        $db->exec('CREATE TABLE school_code_alias (alias_id INTEGER PRIMARY KEY, school_id INTEGER, system TEXT, code TEXT)');
        $db->exec('CREATE TABLE assignment (
            id INTEGER PRIMARY KEY, person_id INTEGER, school_id INTEGER, title TEXT,
            is_primary INTEGER DEFAULT 1)');
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

    /**
     * Flatten an Adaxes REST property list ({propertyName, propertyType, values})
     * from a request body to name => first value.
     *
     * @return array<string,string>
     */
    private static function props(array $body): array
    {
        $out = [];
        foreach ($body['properties'] as $p) {
            $out[$p['propertyName']] = $p['values'][0] ?? null;
        }
        return $out;
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

    public function testExpiresLinkedLeaverBySettingAccountExpiresAndDescription(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Jo', 'last_name' => 'Leaver']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // AD account has no expiration yet (accountExpires 0 = never) → expire it.
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'joleaver', 'accountExpires' => '0']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));

        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(1, $res['disable']['applied']);
        self::assertSame(0, $res['disable']['errors']);
        self::assertFalse($res['disable']['blocked']);

        // The write is a modify (PATCH) that sets accountExpires + description,
        // NOT an accountDisabled toggle.
        self::assertNotEmpty($calls);
        self::assertSame('PATCH', $calls[0]['method']);
        $props = self::props(json_decode((string) $calls[0]['body'], true));
        $today = gmdate('Y-m-d');
        // accountExpires = midnight UTC of today, as an ISO-8601 timestamp.
        self::assertSame($today . 'T00:00:00Z', $props['accountExpires']);
        self::assertSame('Account expired set by TCS-IDM on ' . $today, $props['description']);
        self::assertArrayNotHasKey('accountDisabled', $props); // no longer a disable toggle

        // A disable lifecycle event was still recorded (the leaver lock-out).
        $ev = $db->query("SELECT event_type FROM lifecycle_event WHERE person_id = 1")->fetchColumn();
        self::assertSame('disable', $ev);
    }

    public function testExpiresOnEndDateWhenOneIsPresent(): void
    {
        $db = $this->db();
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'disabled', 'first_name' => 'End', 'last_name' => 'Dated',
            'end_date' => '2026-06-30',
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'enddated', 'accountExpires' => '0']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(1, $res['disable']['applied']);
        $props = self::props(json_decode((string) $calls[0]['body'], true));
        // accountExpires = midnight UTC of the END DATE (ISO-8601), not today.
        self::assertSame('2026-06-30T00:00:00Z', $props['accountExpires']);
        // description still records the run date (when IDM acted).
        self::assertSame('Account expired set by TCS-IDM on ' . gmdate('Y-m-d'), $props['description']);
    }

    public function testNoOpWhenAlreadyExpiredOnTheEndDate(): void
    {
        $db = $this->db();
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'disabled', 'first_name' => 'Al', 'last_name' => 'Ready',
            'end_date' => '2026-06-30',
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // AD already expires on exactly the end date → nothing to change.
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'already', 'accountExpirationDate' => '2026-06-30']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(0, $res['disable']['applied']);
        self::assertSame(1, $res['disable']['noop']);
        self::assertSame([], $calls);
    }

    public function testAlreadyExpiredAdAccountIsANoOp(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Al', 'last_name' => 'Ready']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // A past accountExpirationDate → already expired, nothing to change.
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'already', 'accountExpirationDate' => '2020-01-01']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['disable']);

        self::assertSame(0, $res['disable']['applied']);
        self::assertSame(1, $res['disable']['noop']);
        self::assertSame([], $calls); // never hit the writer
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

    public function testDryRunExpireReportShowsCurrentVsProposed(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Jo', 'last_name' => 'Leaver']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // Never-expiring account with an existing description → the report shows
        // both current values next to what would be written.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName' => 'joleaver', 'accountExpires' => '0', 'description' => 'Teacher',
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: true, phases: ['disable']);

        self::assertSame('would-expire', $res['disable']['items'][0]['outcome']);
        $today = gmdate('Y-m-d');
        $detail = $res['disable']['items'][0]['detail'];
        self::assertStringContainsString('accountExpires: Never → ' . $today, $detail);
        self::assertStringContainsString('description: Teacher → Account expired set by TCS-IDM on ' . $today, $detail);
        self::assertSame([], $calls); // read-only preview
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
        $names = array_column($body['properties'], 'propertyName');
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

    public function testEditMovesAccountToTheComputedOuWhenContainerDrifts(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Jo', 'last_name' => 'Smith',
            'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // Identity + department match golden; only the OU (container) is wrong.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'jsmith@tusc.k12.al.us',
            'mail'              => 'jsmith@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Central Office',
            'physicalDeliveryOfficeName' => 'Central Office',
            'distinguishedName' => 'CN=Jo Smith,OU=OldBuilding,' . self::BASE_DN,
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertSame(1, $res['edit']['applied']);
        self::assertContains('moved', array_column($res['edit']['items'], 'outcome'));

        $move = null;
        foreach ($calls as $c) {
            if (str_contains($c['url'], '/move')) {
                $move = $c;
                break;
            }
        }
        self::assertNotNull($move, 'expected a move call to the writer');
        self::assertSame('POST', $move['method']);
        $body = json_decode((string) $move['body'], true);
        self::assertSame('OU=CO,OU=Faculty,' . self::BASE_DN, $body['targetContainer']);
        self::assertSame(self::GUID1, $body['directoryObject']);
    }

    public function testEditDoesNotMoveWhenAlreadyInComputedOu(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Jo', 'last_name' => 'Smith',
            'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // DN already in the computed container — differing only in case/spacing,
        // which normalizeDn() must treat as equal (no churn).
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'jsmith@tusc.k12.al.us',
            'mail'              => 'jsmith@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Central Office',
            'physicalDeliveryOfficeName' => 'Central Office',
            'distinguishedName' => 'CN=Jo Smith, ou=co, ou=faculty, ' . self::BASE_DN,
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertSame(1, $res['edit']['noop']);
        self::assertSame([], $calls);
    }

    public function testEditPushesOfficeDescriptionAndInfoMappings(): void
    {
        putenv('GOOGLE_DOMAIN=example.edu');
        try {
            $db = $this->db();
            $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
            $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, 'Teacher', 1)");
            $this->seedPerson($db, [
                'person_id' => 1, 'status' => 'active', 'first_name' => 'Jo', 'last_name' => 'Smith',
                'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
                'primary_school_id' => 5,
            ]);
            $this->link($db, 1, self::GUID1);

            $calls = [];
            // In the right OU already, but missing the operational mappings.
            $read = $this->read([self::GUID1 => [
                'sAMAccountName'    => 'jsmith',
                'userPrincipalName' => 'jsmith@tusc.k12.al.us',
                'mail'              => 'jsmith@tusc.k12.al.us',
                'accountDisabled'   => 'false',
                'distinguishedName' => 'CN=Jo Smith,OU=CO,OU=Faculty,' . self::BASE_DN,
            ]]);
            $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
            $res = $rec->run(dryRun: false, phases: ['edit']);

            self::assertSame(1, $res['edit']['applied']);
            $patch = null;
            foreach ($calls as $c) {
                if ($c['method'] === 'PATCH') {
                    $patch = $c;
                    break;
                }
            }
            self::assertNotNull($patch, 'expected a modify PATCH');
            $props = self::props(json_decode((string) $patch['body'], true));
            self::assertSame('Teacher', $props['title']);
            self::assertSame('Teacher', $props['description']);          // description ← title
            self::assertSame('Central Office', $props['department']);
            self::assertSame('Central Office', $props['physicalDeliveryOfficeName']); // office ← department
            self::assertSame('jsmith@example.edu', $props['info']);       // info ← Google email
            self::assertArrayNotHasKey('sAMAccountName', $props);         // immutable
        } finally {
            putenv('GOOGLE_DOMAIN');
        }
    }

    public function testDryRunEditReportShowsCurrentVsProposed(): void
    {
        $db = $this->db();
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Ed', 'last_name' => 'It',
            'username' => 'edit', 'email' => 'edit@tusc.k12.al.us', 'upn' => 'edit@tusc.k12.al.us',
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // AD holds a stale UPN and NO mail — the report must show the live "before"
        // value (and "(unset)" when the account has none) next to the proposed one.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'edit',
            'userPrincipalName' => 'stale@tusc.k12.al.us',
            'accountDisabled'   => 'false',
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: true, phases: ['edit']);

        self::assertSame('would-edit', $res['edit']['items'][0]['outcome']);
        $detail = $res['edit']['items'][0]['detail'];
        self::assertStringContainsString('userPrincipalName: stale@tusc.k12.al.us → edit@tusc.k12.al.us', $detail);
        self::assertStringContainsString('mail: (unset) → edit@tusc.k12.al.us', $detail);
        self::assertSame([], $calls); // read-only preview
    }

    public function testDryRunMoveReportShowsCurrentContainerVsTarget(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Jo', 'last_name' => 'Smith',
            'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // Identity + department match; only the container is wrong.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'jsmith@tusc.k12.al.us',
            'mail'              => 'jsmith@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Central Office',
            'physicalDeliveryOfficeName' => 'Central Office',
            'distinguishedName' => 'CN=Jo Smith,OU=OldBuilding,' . self::BASE_DN,
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: true, phases: ['edit']);

        $move = null;
        foreach ($res['edit']['items'] as $it) {
            if ($it['outcome'] === 'would-move') {
                $move = $it;
                break;
            }
        }
        self::assertNotNull($move, 'expected a would-move report line');
        self::assertSame(
            'move OU=OldBuilding,' . self::BASE_DN . ' → OU=CO,OU=Faculty,' . self::BASE_DN,
            $move['detail'],
        );
        self::assertSame([], $calls); // read-only preview
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
        self::assertSame('OU=CO,OU=Faculty,DC=example,DC=org', $body['createIn']);
        $props = self::props($body);
        self::assertSame('jsmith', $props['sAMAccountName']);
        self::assertSame('E100', $props['employeeID']);
        // accountExpires = midnight UTC of the end date, as an ISO-8601 timestamp.
        self::assertSame('2027-05-31T00:00:00Z', $props['accountExpires']);
    }

    /**
     * The full type→container matrix: every type nests under the shared parent OU
     * (OU=Faculty) and its building OU; contractor/sub/intern add an innermost
     * type leaf, faculty/staff do not.
     */
    #[DataProvider('containerCases')]
    public function testContainerDnByPersonType(string $type, string $expectedPath): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => $type, 'status' => 'pending',
            'first_name' => 'Pat', 'last_name' => 'Person', 'primary_school_id' => 5,
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
        self::assertNotNull($create, "no create POST for {$type}");
        $body = json_decode((string) $create['body'], true);
        self::assertSame($expectedPath, $body['createIn']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function containerCases(): array
    {
        $base = 'OU=CO,OU=Faculty,DC=example,DC=org';
        return [
            'faculty'    => ['faculty', $base],
            'staff'      => ['staff', $base],
            'contractor' => ['contractor', 'OU=PTC,' . $base],
            'sub'        => ['sub', 'OU=Subs,' . $base],
            'intern'     => ['intern', 'OU=Interns,' . $base],
        ];
    }

    public function testCreateSendsCnTitleAndDepartment(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'pending',
            'first_name' => 'John', 'last_name' => 'Smith', 'primary_school_id' => 5,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, 'Teacher - Math', 1)");

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
        $props = self::props($body);
        // CN (the RDN) rides in the property list like any other attribute.
        self::assertSame('John Smith', $props['cn']);
        self::assertSame('Teacher - Math', $props['title']);
        self::assertSame('Central Office', $props['department']); // building name
    }

    public function testCnCollisionFallsBackToUsernameSuffixedForm(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'pending',
            'first_name' => 'John', 'last_name' => 'Smith', 'primary_school_id' => 5,
        ]);

        // The read fake: net-new confirmation + username-mint searches miss, but
        // the cn search hits (another John Smith already exists in AD).
        $readFetch = function (string $method, string $url, array $headers, ?string $body): ?array {
            if (str_contains($url, '/search')) {
                $hit = str_contains((string) $body, '"cn"');
                return ['status' => 200, 'body' => json_encode(['objects' => $hit ? [['properties' => ['cn' => 'John Smith']]] : []])];
            }
            return ['status' => 404, 'body' => 'not found'];
        };
        $read = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $readFetch, null, null, null, null, 'read-token');

        $calls = [];
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $rec->run(dryRun: false, phases: ['create']);

        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        self::assertNotNull($create);
        $body = json_decode((string) $create['body'], true);
        self::assertSame('John Smith (jsmith)', self::props($body)['cn']); // unique via the username
    }

    public function testBusDriverPlacedInTransOuWithDepartmentOverride(): void
    {
        $db = $this->db();
        // Deliberately NO school row: bus drivers don't need a building OU.
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'staff', 'status' => 'pending',
            'first_name' => 'Bud', 'last_name' => 'Driver',
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 99, 'Bus Driver', 1)");

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create']);

        self::assertSame(1, $res['create']['applied']);
        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=trans,OU=Faculty,DC=example,DC=org', $body['createIn']); // no school OU
        $props = self::props($body);
        self::assertSame('Transportation', $props['department']); // override, not a building
    }

    /**
     * Bus Aides (and other "bus" roles) are transportation too — they must get the
     * Transportation department + OU, not their building. This was the reported bug.
     */
    #[DataProvider('transportationTitles')]
    public function testTransportationTitlesGetTransOuAndDepartment(string $title): void
    {
        $db = $this->db();
        // Assigned to Central Office; must still land in Transportation, not CO.
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (4999, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'staff', 'status' => 'pending',
            'first_name' => 'Pat', 'last_name' => 'Rider', 'primary_school_id' => 4999,
        ]);
        $db->prepare("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 4999, ?, 1)")->execute([$title]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $rec->run(dryRun: false, phases: ['create']);

        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=trans,OU=Faculty,DC=example,DC=org', $body['createIn'], "{$title} OU");
        $props = self::props($body);
        self::assertSame('Transportation', $props['department'], "{$title} department");
    }

    /** @return array<string,array{0:string}> */
    public static function transportationTitles(): array
    {
        return [
            'bus aide'    => ['Bus Aide'],
            'bus driver'  => ['Bus Driver'],
            'bus monitor' => ['Bus Monitor'],
            'school bus aide' => ['School Bus Aide'],
            'transportation coordinator' => ['Transportation Coordinator'],
            'director of transportation' => ['Director of Transportation'],
        ];
    }

    public function testBusinessTitleIsNotTransportation(): void
    {
        // "Business Manager" contains "bus" but must NOT be transportation.
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (4999, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'staff', 'status' => 'pending',
            'first_name' => 'Biz', 'last_name' => 'Manager', 'primary_school_id' => 4999,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 4999, 'Business Manager', 1)");

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $rec->run(dryRun: false, phases: ['create']);

        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=CO,OU=Faculty,DC=example,DC=org', $body['createIn']); // stays at the building
        $props = self::props($body);
        self::assertSame('Central Office', $props['department']);
    }

    public function testSroGetsSroLeafAboveTheBuildingOu(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (7, 'Paul W. Bryant High School', 'OU=BHS')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'contractor', 'status' => 'pending',
            'first_name' => 'Sam', 'last_name' => 'Officer', 'primary_school_id' => 7,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 7, 'School Resource Officer', 1)");

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $rec->run(dryRun: false, phases: ['create']);

        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        $body = json_decode((string) $create['body'], true);
        // The SRO rule trumps the contractor type leaf (no OU=PTC).
        self::assertSame('OU=SRO,OU=BHS,OU=Faculty,DC=example,DC=org', $body['createIn']);
    }

    /**
     * Substitutes are placed in the Subs OU under their building based on TITLE,
     * even when the feed stamped them staff/faculty (person_type != 'sub'). The
     * reported bug: they were landing at the root building OU instead.
     */
    #[DataProvider('substituteTitles')]
    public function testSubstituteTitleGetsSubsLeafRegardlessOfType(string $type, string $title): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => $type, 'status' => 'pending',
            'first_name' => 'Sub', 'last_name' => 'Teacher', 'primary_school_id' => 5,
        ]);
        $db->prepare("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, ?, 1)")->execute([$title]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $rec->run(dryRun: false, phases: ['create']);

        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        self::assertNotNull($create, "no create POST for {$title}");
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=Subs,OU=CO,OU=Faculty,DC=example,DC=org', $body['createIn'], "{$title} ({$type})");
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function substituteTitles(): array
    {
        return [
            'staff substitute'         => ['staff', 'Substitute'],
            'faculty long-term sub'    => ['faculty', 'Long-term Substitute'],
            'staff substitute teacher' => ['staff', 'Substitute Teacher'],
        ];
    }

    public function testSubstituteInWrongOuIsMovedToSubsLeaf(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Sub', 'last_name' => 'Teacher',
            'username' => 'steacher', 'email' => 'steacher@tusc.k12.al.us', 'upn' => 'steacher@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, 'Long-term Substitute', 1)");
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // Identity + department match; the account sits at the root building OU
        // (the bug) and must move down into OU=Subs.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'steacher',
            'userPrincipalName' => 'steacher@tusc.k12.al.us',
            'mail'              => 'steacher@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Central Office',
            'physicalDeliveryOfficeName' => 'Central Office',
            'distinguishedName' => 'CN=Sub Teacher,OU=CO,OU=Faculty,' . self::BASE_DN,
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertContains('moved', array_column($res['edit']['items'], 'outcome'));
        $move = null;
        foreach ($calls as $c) {
            if (str_contains($c['url'], '/move')) {
                $move = $c;
                break;
            }
        }
        self::assertNotNull($move, 'expected a move to the Subs OU');
        $body = json_decode((string) $move['body'], true);
        self::assertSame('OU=Subs,OU=CO,OU=Faculty,' . self::BASE_DN, $body['targetContainer']);
    }

    public function testTransportationLocationCodeGetsTransOuRegardlessOfTitle(): void
    {
        $db = $this->db();
        // School 42 is the transportation depot: its NextGen code is 8410.
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (42, 'Transportation Dept', 'OU=CO')");
        $db->exec("INSERT INTO school_code_alias (school_id, system, code) VALUES (42, 'nextgen', '8410')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'staff', 'status' => 'pending',
            'first_name' => 'Dee', 'last_name' => 'Spatcher', 'primary_school_id' => 42,
        ]);
        // A title with no transportation keyword at all — the LOCATION is what
        // classifies them as transportation.
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 42, 'Dispatcher', 1)");

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create']);

        self::assertSame(1, $res['create']['applied']);
        $create = null;
        foreach ($calls as $c) {
            if ($c['method'] === 'POST') {
                $create = $c;
            }
        }
        $body = json_decode((string) $create['body'], true);
        self::assertSame('OU=trans,OU=Faculty,DC=example,DC=org', $body['createIn']); // trans OU, no building
        $props = self::props($body);
        self::assertSame('Transportation', $props['department']); // override, not the building name
    }

    public function testSharedBuildingWithTransportationAliasIsNotTransportation(): void
    {
        $db = $this->db();
        // Central Office carries BOTH the transportation code (8410) and its own
        // ordinary code (8620). It is NOT a dedicated transportation building, so a
        // Bookkeeper there must stay at OU=CO — never moved to OU=trans.
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $db->exec("INSERT INTO school_code_alias (school_id, system, code) VALUES (5, 'nextgen', '8410'), (5, 'nextgen', '8620')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Tearra', 'last_name' => 'Adams',
            'username' => 'tadams', 'email' => 'tadams@tusc.k12.al.us', 'upn' => 'tadams@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, 'Bookkeeper', 1)");
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // AD account already correctly at OU=CO with matching identity/dept.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'tadams',
            'userPrincipalName' => 'tadams@tusc.k12.al.us',
            'mail'              => 'tadams@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Central Office',
            'physicalDeliveryOfficeName' => 'Central Office',
            'title'             => 'Bookkeeper',
            'description'       => 'Bookkeeper',
            'distinguishedName' => 'CN=Tearra Adams,OU=CO,OU=Faculty,' . self::BASE_DN,
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        // No move to OU=trans (and no department flip to Transportation).
        self::assertNotContains('moved', array_column($res['edit']['items'], 'outcome'));
        foreach ($calls as $c) {
            self::assertStringNotContainsString('/move', (string) $c['url']);
        }
    }

    public function testTransportationTitleIsMovedToTransOu(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Tara', 'last_name' => 'Transit',
            'username' => 'ttransit', 'email' => 'ttransit@tusc.k12.al.us', 'upn' => 'ttransit@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        // "Transportation" in the title (no "bus") — must still land in OU=trans.
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, 'Transportation Coordinator', 1)");
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // Department already the transportation override; only the OU is wrong
        // (account sits under the building instead of the transportation OU).
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'ttransit',
            'userPrincipalName' => 'ttransit@tusc.k12.al.us',
            'mail'              => 'ttransit@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Transportation',
            'physicalDeliveryOfficeName' => 'Transportation',
            'title'             => 'Transportation Coordinator',
            'description'       => 'Transportation Coordinator',
            'distinguishedName' => 'CN=Tara Transit,OU=CO,OU=Faculty,' . self::BASE_DN,
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertContains('moved', array_column($res['edit']['items'], 'outcome'));
        $move = null;
        foreach ($calls as $c) {
            if (str_contains($c['url'], '/move')) {
                $move = $c;
                break;
            }
        }
        self::assertNotNull($move, 'expected a move to the transportation OU');
        $body = json_decode((string) $move['body'], true);
        self::assertSame('OU=trans,OU=Faculty,' . self::BASE_DN, $body['targetContainer']); // no building OU
    }

    public function testEditPushesTitleAndDepartmentDrift(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Moved', 'last_name' => 'Person',
            'username' => 'mperson', 'email' => 'mperson@tusc.k12.al.us', 'upn' => 'mperson@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 5, 'Coordinator', 1)");
        $this->link($db, 1, self::GUID1);

        $calls = [];
        // AD still shows the OLD building/title — a school move that must propagate.
        $read = $this->read([self::GUID1 => [
            'sAMAccountName'    => 'mperson',
            'userPrincipalName' => 'mperson@tusc.k12.al.us',
            'mail'              => 'mperson@tusc.k12.al.us',
            'accountDisabled'   => 'false',
            'department'        => 'Eastwood Middle School',
            'title'             => 'Teacher',
        ]]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['edit']);

        self::assertSame(1, $res['edit']['applied']);
        $body = json_decode((string) $calls[0]['body'], true);
        $props = self::props($body);
        self::assertSame('Central Office', $props['department']);
        self::assertSame('Coordinator', $props['title']);
    }

    public function testTypeLeafOuIsOverridableViaEnv(): void
    {
        putenv('AD_OU_CONTRACTOR=OU=Vendors');
        try {
            $db = $this->db();
            $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
            $this->seedPerson($db, [
                'person_id' => 1, 'person_type' => 'contractor', 'status' => 'pending',
                'first_name' => 'Con', 'last_name' => 'Tractor', 'primary_school_id' => 5,
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
            $body = json_decode((string) $create['body'], true);
            self::assertSame('OU=Vendors,OU=CO,OU=Faculty,DC=example,DC=org', $body['createIn']);
        } finally {
            putenv('AD_OU_CONTRACTOR');
        }
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
        self::assertSame(1, $res['create']['review']); // locked but no AD account found
        self::assertSame([], $calls); // never minted/created
    }

    /** A read fake whose /search returns one account with the given properties. */
    private function readSearchHit(array $props): AdaxesService
    {
        $fetch = function (string $method, string $url, array $headers, ?string $body) use ($props): ?array {
            if (str_contains($url, '/search')) {
                return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => $props]]])];
            }
            return ['status' => 404, 'body' => 'not found']; // no GUID crosswalk to GET
        };
        return new AdaxesService('https://adx.example.org/restv2', '', '', 5, $fetch, null, null, null, null, 'read-token');
    }

    public function testLockedPersonWithMatchingAdAccountIsAutoCorrelated(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Julia', 'last_name' => 'Horn',
            'username' => 'jhorn', 'username_locked' => 1, 'email' => 'jhorn@tusc.k12.al.us',
            'upn' => 'jhorn@tusc.k12.al.us', 'employee_id' => '15246', 'primary_school_id' => 5,
        ]);
        // No 'ad' crosswalk row → she lands in the create population.

        $calls = [];
        $read = $this->readSearchHit([
            'objectGUID'        => self::NEWGUID,
            'sAMAccountName'    => 'jhorn',                 // == her locked golden username
            'mail'              => 'jhorn@tusc.k12.al.us',  // == her golden email
            'userPrincipalName' => 'jhorn@tusc.k12.al.us',
        ]);
        $res = (new AdaxesReconciler($db, $read, $this->writer($calls)))->run(dryRun: false, phases: ['create']);

        self::assertSame(1, $res['create']['correlated']);
        self::assertSame(0, $res['create']['review']);
        self::assertSame(0, $res['create']['applied']); // linked, NOT recreated
        self::assertSame([], $calls);                    // no WRITE to AD
        // The objectGUID is now linked in the crosswalk, so next run she leaves create.
        $guid = $db->query("SELECT source_key FROM person_source_id WHERE person_id = 1 AND system = 'ad' AND is_active = 1")->fetchColumn();
        self::assertSame(self::NEWGUID, $guid);
    }

    public function testLockedPersonWithMismatchedAdAccountStaysInReview(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'status' => 'active', 'first_name' => 'Julia', 'last_name' => 'Horn',
            'username' => 'jhorn', 'username_locked' => 1, 'email' => 'jhorn@tusc.k12.al.us',
            'primary_school_id' => 5,
        ]);

        $calls = [];
        // The account found bears a DIFFERENT sAMAccountName → not an unambiguous
        // match → stays in review, never linked.
        $read = $this->readSearchHit([
            'objectGUID'     => self::NEWGUID,
            'sAMAccountName' => 'someoneelse',
            'mail'           => 'someoneelse@tusc.k12.al.us',
        ]);
        $res = (new AdaxesReconciler($db, $read, $this->writer($calls)))->run(dryRun: false, phases: ['create']);

        self::assertSame(0, $res['create']['correlated']);
        self::assertSame(1, $res['create']['review']);
        self::assertFalse($db->query("SELECT 1 FROM person_source_id WHERE person_id = 1 AND system = 'ad'")->fetchColumn());
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

    public function testOnlyPersonIdsRestrictsEveryPhaseToTheCohort(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (5, 'Central Office', 'OU=CO')");
        // Two net-new hires; only person 1 is in the test cohort.
        $this->seedPerson($db, ['person_id' => 1, 'person_type' => 'faculty', 'status' => 'pending', 'first_name' => 'In', 'last_name' => 'Cohort', 'primary_school_id' => 5]);
        $this->seedPerson($db, ['person_id' => 2, 'person_type' => 'faculty', 'status' => 'pending', 'first_name' => 'Not', 'last_name' => 'Cohort', 'primary_school_id' => 5]);

        $calls = [];
        $rec = new AdaxesReconciler($db, $this->read([], searchHit: false), $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['create'], limit: null, log: null, onlyPersonIds: [1]);

        // Only person 1 was created; person 2 was never examined.
        self::assertSame(1, $res['create']['applied']);
        $ids = array_column($res['create']['items'], 'person_id');
        self::assertSame([1], array_values(array_unique($ids)));
        self::assertNotFalse($db->query("SELECT username FROM person WHERE person_id = 1")->fetchColumn());
        self::assertNull($db->query("SELECT username FROM person WHERE person_id = 2")->fetchColumn());
    }

    // ---- groups (Phase 4) ---------------------------------------------------

    public function testGroupsPhaseAddsMissingAndRemovesManagedOnlyWithinTheSet(): void
    {
        $db = $this->db();
        // Both buildings exist, so EMS-Everyone is a known/managed group — a
        // realistic move: the person is now at CO but AD still has EMS-Everyone.
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (30, 'Central Office', 'OU=CO'), (60, 'Eastwood Middle', 'OU=EMS')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'active',
            'first_name' => 'Tea', 'last_name' => 'Cher', 'primary_school_id' => 30,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 30, 'Teacher - Math', 1)");
        $this->link($db, 1, self::GUID1);

        // The read fake answers two things: the memberOf GET, and the group-name→DN
        // resolution search (echoes a DN built from the searched cn).
        $readFetch = function (string $method, string $url, array $headers, ?string $body): ?array {
            if (str_contains($url, '/search')) {
                preg_match('/"value":"([^"]+)"/', (string) $body, $m);
                $cn = $m[1] ?? 'Unknown';
                return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => [
                    'distinguishedName' => 'CN=' . $cn . ',OU=Groups,DC=example,DC=org',
                    'objectGUID'        => '00000000-0000-0000-0000-000000000000',
                ]]]])];
            }
            return ['status' => 200, 'body' => json_encode(['properties' => [
                ['name' => 'memberOf', 'values' => [
                    'CN=All-Faculty,OU=Groups,DC=example,DC=org',
                    'CN=EMS-Everyone,OU=Groups,DC=example,DC=org',
                    'CN=Domain Users,CN=Users,DC=example,DC=org',
                ]],
            ]])];
        };
        $read = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $readFetch, null, null, null, null, 'read-token');

        $calls = [];
        $writerFetch = function (string $method, string $url, array $headers, ?string $body) use (&$calls): ?array {
            $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            return ['status' => 200, 'body' => '{}'];
        };
        $writer = new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, $writerFetch, 'write-token', true);

        $rec = new AdaxesReconciler($db, $read, $writer);
        $res = $rec->run(dryRun: false, phases: ['groups']);

        self::assertSame(1, $res['groups']['applied']);
        // desired = All-Faculty, CO-Everyone, M365 A3 License, Raptor_EmergencyManagementUser.
        // All-Faculty already present → 3 adds; EMS-Everyone removed; Domain Users kept.
        self::assertSame(3, $res['groups']['added']);
        self::assertSame(1, $res['groups']['removed']);

        // Adds are POSTs (group in the body), the removal is a DELETE (group in the URL).
        $joined = implode(' | ', array_map(static fn($c) => $c['method'] . ' ' . $c['url'] . ' ' . (string) $c['body'], $calls));
        self::assertStringContainsString('POST', $joined);
        self::assertStringContainsString('DELETE', $joined);
        self::assertStringContainsString('CO-Everyone', $joined);                 // added
        self::assertStringContainsString('newMember', $joined);                   // correct add field
        self::assertStringContainsString('EMS-Everyone', $joined);                // the managed removal
        self::assertStringNotContainsString('Domain Users', $joined);             // unmanaged, untouched
        self::assertStringNotContainsString('CN=All-Faculty', $joined);           // already a member, no re-add
    }

    public function testGroupMembershipComparedByRealCnNotConfiguredName(): void
    {
        // The reported bug: the M365 A3 License group's real cn is "M365-A3"
        // (the configured name matches its sAMAccountName, not its cn). The user
        // is ALREADY a member. Comparing the configured name against the memberOf
        // cn would re-add it every run; comparing the resolved real cn is a no-op.
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (30, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'active',
            'first_name' => 'Tea', 'last_name' => 'Cher', 'primary_school_id' => 30,
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 30, 'Teacher - Math', 1)");
        $this->link($db, 1, self::GUID1);

        $readFetch = function (string $method, string $url, array $headers, ?string $body): ?array {
            if (str_contains($url, '/search')) {
                // findGroup echoes a DN; the A3 license group's cn is "M365-A3"
                // even though it's configured/searched as "M365 A3 License".
                preg_match('/"value":"([^"]+)"/', (string) $body, $m);
                $name = $m[1] ?? 'Unknown';
                $cn = $name === 'M365 A3 License' ? 'M365-A3' : $name;
                return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => [
                    'distinguishedName' => 'CN=' . $cn . ',OU=Groups,DC=example,DC=org',
                ]]]])];
            }
            // The account is already a member of every desired group — A3 under
            // its REAL cn "M365-A3".
            return ['status' => 200, 'body' => json_encode(['properties' => [
                ['name' => 'memberOf', 'values' => [
                    'CN=All-Faculty,OU=Groups,DC=example,DC=org',
                    'CN=CO-Everyone,OU=Groups,DC=example,DC=org',
                    'CN=M365-A3,OU=Groups,DC=example,DC=org',
                    'CN=Raptor_EmergencyManagementUser,OU=Groups,DC=example,DC=org',
                ]],
            ]])];
        };
        $read = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $readFetch, null, null, null, null, 'read-token');

        $calls = [];
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: false, phases: ['groups']);

        // Nothing to do — already a member of everything (A3 matched by real cn).
        self::assertSame(1, $res['groups']['noop']);
        self::assertSame(0, $res['groups']['added']);
        self::assertSame(0, $res['groups']['applied']);
        self::assertSame([], $calls); // no group writes at all
    }

    public function testGroupsPhaseHonorsPerPersonRaptorOverride(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (30, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'active',
            'first_name' => 'Tea', 'last_name' => 'Cher', 'primary_school_id' => 30,
            // A Teacher would earn only Raptor_EmergencyManagementUser; the per-person
            // exception forces Raptor_ClientAdmin instead.
            'raptor_group_override' => 'clientadmin',
        ]);
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 30, 'Teacher - Math', 1)");
        $this->link($db, 1, self::GUID1);

        $readFetch = function (string $method, string $url, array $headers, ?string $body): ?array {
            if (str_contains($url, '/search')) {
                preg_match('/"value":"([^"]+)"/', (string) $body, $m);
                $cn = $m[1] ?? 'Unknown';
                return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => [
                    'distinguishedName' => 'CN=' . $cn . ',OU=Groups,DC=example,DC=org',
                ]]]])];
            }
            return ['status' => 200, 'body' => json_encode(['properties' => [['name' => 'memberOf', 'values' => []]]])];
        };
        $read = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $readFetch, null, null, null, null, 'read-token');

        $calls = [];
        $writerFetch = function (string $method, string $url, array $headers, ?string $body) use (&$calls): ?array {
            $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            return ['status' => 200, 'body' => '{}'];
        };
        $writer = new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, $writerFetch, 'write-token', true);

        $res = (new AdaxesReconciler($db, $read, $writer))->run(dryRun: false, phases: ['groups']);

        self::assertSame(1, $res['groups']['applied']);
        $joined = implode(' | ', array_map(static fn($c) => (string) $c['body'], $calls));
        self::assertStringContainsString('Raptor_ClientAdmin', $joined);                 // the exception
        self::assertStringNotContainsString('Raptor_EmergencyManagementUser', $joined);  // NOT the title default
    }

    public function testGroupsPhaseDryRunReportsButDoesNotWrite(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO school (school_id, name, ad_ou) VALUES (30, 'Central Office', 'OU=CO')");
        $this->seedPerson($db, [
            'person_id' => 1, 'person_type' => 'faculty', 'status' => 'active',
            'first_name' => 'Tea', 'last_name' => 'Cher', 'primary_school_id' => 30,
        ]);
        $this->link($db, 1, self::GUID1);

        $readFetch = fn(string $m, string $u, array $h, ?string $b): array =>
            ['status' => 200, 'body' => json_encode(['properties' => [['name' => 'memberOf', 'values' => []]]])];
        $read = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $readFetch, null, null, null, null, 'read-token');

        $calls = [];
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: true, phases: ['groups']);

        self::assertSame('would-sync', $res['groups']['items'][0]['outcome']);
        self::assertSame([], $calls); // read-only preview
    }

    // ---- dry run & off ------------------------------------------------------

    public function testDryRunWritesNothing(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Dry', 'last_name' => 'Run']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'dryrun', 'accountExpires' => '0']]);
        $rec = new AdaxesReconciler($db, $read, $this->writer($calls));
        $res = $rec->run(dryRun: true, phases: ['disable']);

        self::assertTrue($res['dry_run']);
        self::assertSame('would-expire', $res['disable']['items'][0]['outcome']);
        self::assertSame([], $calls); // read-only preview
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM lifecycle_event')->fetchColumn());
    }

    public function testProgressCallbackStreamsPhaseAndItemEvents(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Jo', 'last_name' => 'Leaver']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'joleaver', 'accountExpires' => '0']]);
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
        self::assertSame('would-expire', $items[0][1]['outcome']);
        self::assertSame(1, $items[0][1]['person_id']);
    }

    public function testNonDryRunWithWritesOffChangesNothing(): void
    {
        $db = $this->db();
        $this->seedPerson($db, ['person_id' => 1, 'status' => 'disabled', 'first_name' => 'Off', 'last_name' => 'Switch']);
        $this->link($db, 1, self::GUID1);

        $calls = [];
        $read = $this->read([self::GUID1 => ['sAMAccountName' => 'off', 'accountExpires' => '0']]);
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
        // The candidate was still identified, just not written (would-expire).
        self::assertSame('would-expire', $res['disable']['items'][0]['outcome']);
    }
}
