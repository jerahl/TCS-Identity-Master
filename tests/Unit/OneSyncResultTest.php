<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\OneSyncResultImporter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The OneSync export-log enum decoding (inferred from the live value spread:
 * actionStatus 3 dominates = Success, 4 = Fail, 10 = Skipped).
 */
final class OneSyncResultTest extends TestCase
{
    public function testStatusMapping(): void
    {
        self::assertSame('Success', OneSyncResultImporter::status(3));
        self::assertSame('Fail', OneSyncResultImporter::status(4));
        self::assertSame('Skipped', OneSyncResultImporter::status(10));
        self::assertSame('New', OneSyncResultImporter::status(0));
        self::assertSame('New', OneSyncResultImporter::status(99));
    }

    public function testActionMapping(): void
    {
        self::assertSame('Add', OneSyncResultImporter::action(1));
        self::assertSame('Disable', OneSyncResultImporter::action(3));
        self::assertSame('NoChange', OneSyncResultImporter::action(0));
        self::assertSame('Edit', OneSyncResultImporter::action(11)); // update / default
    }

    public function testDestTypeMapping(): void
    {
        self::assertSame('ActiveDirectory', OneSyncResultImporter::destType(3));
        self::assertSame('GSuite', OneSyncResultImporter::destType(5));
        self::assertSame('CSV', OneSyncResultImporter::destType(2));
        self::assertNull(OneSyncResultImporter::destType(99));
    }

    public function testSourceIdsReadsBothFeeds(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS=31');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY=32');
        putenv('ONESYNC_DB_SOURCE_ID'); // legacy unset

        self::assertSame([31, 32], OneSyncResultImporter::sourceIds());

        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
    }

    public function testSourceIdsFallsBackToLegacyAndDedupes(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY=32');
        putenv('ONESYNC_DB_SOURCE_ID=32'); // duplicate of faculty — must collapse

        self::assertSame([32], OneSyncResultImporter::sourceIds());

        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
        putenv('ONESYNC_DB_SOURCE_ID');
    }

    public function testSourceIdsEmptyWhenNoneConfigured(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
        putenv('ONESYNC_DB_SOURCE_ID');

        self::assertSame([], OneSyncResultImporter::sourceIds());
    }

    /**
     * A dry-run activates (in the projected count) only pending people whose
     * latest export succeeded and wasn't a Disable — once per person — and never
     * an already-active record. (Dry-run skips the MySQL-only upsert, so the
     * counting path is what's exercised here; the real status flip reuses the
     * same guarded UPDATE as WritebackImporter/linkAdAccount.)
     */
    public function testDryRunCountsOnlySuccessfulPendingActivations(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY=31');
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID');

        $uuidA = '00000000-0000-0000-0000-00000000000a';
        $uuidB = '00000000-0000-0000-0000-00000000000b';
        $uuidC = '00000000-0000-0000-0000-00000000000c';
        $uuidD = '00000000-0000-0000-0000-00000000000d';

        $src = $this->oneSyncDb([
            'dests' => [[1, 'Faculty AD', 3], [2, 'Google', 5]],
            'users' => [[10, $uuidA, 31], [11, $uuidB, 31], [12, $uuidC, 31], [13, $uuidD, 31]],
            // id, userId, destinationId, action, actionStatus
            'logs'  => [
                [100, 10, 1, 1, 3],  // A: success Add (AD)      -> activate
                [103, 10, 2, 1, 3],  // A: success Add (Google)  -> deduped, still one
                [101, 11, 1, 3, 3],  // B: success Disable       -> excluded
                [102, 12, 1, 1, 4],  // C: failed Add            -> excluded
                [104, 13, 1, 1, 3],  // D: success Add, but active-> excluded
            ],
        ]);
        $app = $this->appDb([
            [1, $uuidA, 'pending'], [2, $uuidB, 'pending'],
            [3, $uuidC, 'pending'], [4, $uuidD, 'active'],
        ]);

        $result = (new OneSyncResultImporter($src, $app, new AuditService($app)))->run(true);

        self::assertTrue($result['dry_run']);
        self::assertSame(1, $result['counts']['activated']);
        self::assertSame(4, $result['counts']['users']);

        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
    }

    /** In-memory stand-in for OneSync's source DB. */
    private function oneSyncDb(array $data): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE os_destinations (id INTEGER PRIMARY KEY, name TEXT, typeId INTEGER)');
        $db->exec('CREATE TABLE os_users (id INTEGER PRIMARY KEY, userId TEXT, sourceId INTEGER)');
        $db->exec('CREATE TABLE os_export_log (id INTEGER PRIMARY KEY, userId INTEGER, destinationId INTEGER,
            action INTEGER, actionStatus INTEGER, endTime TEXT, dryRecord INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE os_export_log_part (id INTEGER PRIMARY KEY, sourceId INTEGER, message TEXT)');

        foreach ($data['dests'] as [$id, $name, $type]) {
            $db->prepare('INSERT INTO os_destinations (id, name, typeId) VALUES (?, ?, ?)')->execute([$id, $name, $type]);
        }
        foreach ($data['users'] as [$id, $uuid, $sid]) {
            $db->prepare('INSERT INTO os_users (id, userId, sourceId) VALUES (?, ?, ?)')->execute([$id, $uuid, $sid]);
        }
        foreach ($data['logs'] as [$id, $uid, $dest, $action, $status]) {
            $db->prepare('INSERT INTO os_export_log (id, userId, destinationId, action, actionStatus, endTime, dryRecord)
                          VALUES (?, ?, ?, ?, ?, ?, 0)')->execute([$id, $uid, $dest, $action, $status, '2026-01-01 10:00:00']);
        }
        return $db;
    }

    /** In-memory stand-in for our app DB (person + audit tables). */
    private function appDb(array $people): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (person_id INTEGER PRIMARY KEY, person_uuid TEXT, status TEXT)');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT,
            detail TEXT, actor TEXT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        foreach ($people as [$id, $uuid, $status]) {
            $db->prepare('INSERT INTO person (person_id, person_uuid, status) VALUES (?, ?, ?)')->execute([$id, $uuid, $status]);
        }
        return $db;
    }
}
