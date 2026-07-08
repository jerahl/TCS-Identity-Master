<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\StudentImporter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The students passthrough: rows pulled from PowerSchool are upserted into the
 * `student` table by DCID, drop-outs are disabled (not deleted), and a sync row
 * is recorded for the dashboard. These tests run the importer against an in-memory
 * SQLite DB whose schema mirrors migration 0008 (the importer uses only portable
 * SQL — CURRENT_TIMESTAMP, named params), so no MySQL is needed.
 */
final class StudentImporterTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE student (
                student_id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_uuid TEXT NOT NULL,
                ps_dcid TEXT NOT NULL UNIQUE,
                ps_id TEXT, state_studentnumber TEXT, ps_school_id TEXT, grade_level TEXT,
                first_name TEXT NOT NULL, last_name TEXT NOT NULL,
                entry_code TEXT, exit_code TEXT, exit_date TEXT, enroll_status TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                first_seen TEXT DEFAULT CURRENT_TIMESTAMP, last_seen TEXT DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE student_import_batch (
                batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL DEFAULT \'powerschool_odbc\',
                started_at TEXT DEFAULT CURRENT_TIMESTAMP, finished_at TEXT,
                row_count INTEGER, inserted INTEGER NOT NULL DEFAULT 0,
                updated INTEGER NOT NULL DEFAULT 0, deactivated INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'running\', message TEXT
            )'
        );
    }

    /** @param array<string,?string> $over */
    private static function row(string $dcid, array $over = []): array
    {
        return array_merge([
            'Students.DCID' => $dcid,
            'Students.ID' => 'id-' . $dcid,
            'Students.State_StudentNumber' => 'S' . $dcid,
            'Students.SchoolID' => '160',
            'Students.Grade_Level' => '9',
            'Students.First_Name' => 'First' . $dcid,
            'Students.Last_Name' => 'Last' . $dcid,
            'Students.EntryCode' => 'E1',
            'Students.ExitCode' => '',
            'Students.ExitDate' => '',
            'Students.Enroll_Status' => '0',
        ], $over);
    }

    private function importer(): StudentImporter
    {
        return new StudentImporter($this->db);
    }

    public function testInsertsNewStudentsAndRecordsBatch(): void
    {
        $res = $this->importer()->importRows([self::row('1001'), self::row('1002')]);

        self::assertSame(['total' => 2, 'inserted' => 2, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0, 'dropout_blocked' => 0], $res['counts']);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM student')->fetchColumn());

        $batch = $this->db->query('SELECT * FROM student_import_batch ORDER BY batch_id DESC LIMIT 1')->fetch();
        self::assertSame('complete', $batch['status']);
        self::assertSame(2, (int) $batch['inserted']);
        self::assertSame(2, (int) $batch['row_count']);
        self::assertNotNull($batch['finished_at']);

        // Each student gets a distinct, stable uuid (the OneSync uniqueId).
        $uuids = $this->db->query('SELECT DISTINCT student_uuid FROM student')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $uuids);
    }

    public function testReimportUpdatesInPlaceAndKeepsUuid(): void
    {
        $this->importer()->importRows([self::row('1001', ['Students.Grade_Level' => '9'])]);
        $uuid = $this->db->query("SELECT student_uuid FROM student WHERE ps_dcid = '1001'")->fetchColumn();

        $res = $this->importer()->importRows([self::row('1001', ['Students.Grade_Level' => '10', 'Students.First_Name' => 'Renamed'])]);

        self::assertSame(['total' => 1, 'inserted' => 0, 'updated' => 1, 'deactivated' => 0, 'skipped' => 0, 'dropout_blocked' => 0], $res['counts']);
        $student = $this->db->query("SELECT * FROM student WHERE ps_dcid = '1001'")->fetch();
        self::assertSame('10', $student['grade_level'], 'field refreshed');
        self::assertSame('Renamed', $student['first_name']);
        self::assertSame($uuid, $student['student_uuid'], 'uuid preserved across re-import');
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM student')->fetchColumn(), 'no duplicate row');
    }

    public function testDropoutsAreDisabledNotDeleted(): void
    {
        $this->importer()->importRows([self::row('1001'), self::row('1002')]);

        // 1002 is gone from the next pull → flagged inactive, kept in the table.
        $res = $this->importer()->importRows([self::row('1001')]);

        self::assertSame(1, $res['counts']['deactivated']);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM student')->fetchColumn(), 'no hard delete');
        self::assertSame(0, (int) $this->db->query("SELECT is_active FROM student WHERE ps_dcid = '1002'")->fetchColumn());
        self::assertSame(1, (int) $this->db->query("SELECT is_active FROM student WHERE ps_dcid = '1001'")->fetchColumn());
    }

    public function testReturningDropoutIsReactivated(): void
    {
        $this->importer()->importRows([self::row('1001')]);
        $this->importer()->importRows([]);                         // 1001 drops out → inactive
        self::assertSame(0, (int) $this->db->query("SELECT is_active FROM student WHERE ps_dcid = '1001'")->fetchColumn());

        $res = $this->importer()->importRows([self::row('1001')]);   // comes back
        self::assertSame(1, $res['counts']['updated']);
        self::assertSame(1, (int) $this->db->query("SELECT is_active FROM student WHERE ps_dcid = '1001'")->fetchColumn());
    }

    public function testRowWithoutDcidIsSkipped(): void
    {
        $res = $this->importer()->importRows([self::row('1001'), self::row('', ['Students.DCID' => ''])]);

        self::assertSame(1, $res['counts']['skipped']);
        self::assertSame(1, $res['counts']['inserted']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM student')->fetchColumn());
    }

    public function testDryRunWritesNothingButCounts(): void
    {
        $this->importer()->importRows([self::row('1001'), self::row('1002')]);

        // Next pull: 1001 stays, 1003 is new, 1002 drops out — all dry.
        $res = $this->importer()->importRows([self::row('1001'), self::row('1003')], true);

        self::assertTrue($res['dry_run']);
        self::assertSame(1, $res['counts']['inserted']);
        self::assertSame(1, $res['counts']['updated']);
        self::assertSame(1, $res['counts']['deactivated']);
        self::assertNull($res['batch_id'], 'dry run records no batch');

        // Nothing changed on disk — and no new batch beyond the first real import.
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM student')->fetchColumn());
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM student WHERE is_active = 1')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM student_import_batch')->fetchColumn());
    }

    public function testDuplicateDcidInOnePullIsStagedOnce(): void
    {
        // A repeated DCID must not blow up the unique key — stage the first, skip the rest.
        $res = $this->importer()->importRows([self::row('1001'), self::row('1001', ['Students.Grade_Level' => '12'])]);

        self::assertSame(1, $res['counts']['inserted']);
        self::assertSame(1, $res['counts']['skipped']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM student')->fetchColumn());
        self::assertSame('9', $this->db->query("SELECT grade_level FROM student WHERE ps_dcid = '1001'")->fetchColumn());
    }

    public function testExitDateIsNormalised(): void
    {
        $this->importer()->importRows([self::row('1001', ['Students.ExitCode' => 'WD', 'Students.ExitDate' => '5/30/2026'])]);

        self::assertSame('2026-05-30', $this->db->query("SELECT exit_date FROM student WHERE ps_dcid = '1001'")->fetchColumn());
    }

    public function testGuardBlocksMassDeactivationFromTruncatedFeed(): void
    {
        // Seed a sizeable active population (>= the guardMinActive floor of 20).
        $seed = [];
        for ($i = 1; $i <= 30; $i++) {
            $seed[] = self::row((string) (1000 + $i));
        }
        $this->importer()->importRows($seed);

        // The next pull comes back nearly empty (5 of 30) — 83% would drop, over
        // the 20% ratio. The destructive step must be blocked, inserts/updates OK.
        $next = [];
        for ($i = 1; $i <= 5; $i++) {
            $next[] = self::row((string) (1000 + $i));
        }
        $res = $this->importer()->importRows($next);

        self::assertSame(1, $res['counts']['dropout_blocked']);
        self::assertSame(0, $res['counts']['deactivated'], 'blocked run deactivates nothing');
        self::assertSame(5, $res['counts']['updated']);
        self::assertSame(30, (int) $this->db->query('SELECT COUNT(*) FROM student WHERE is_active = 1')->fetchColumn(),
            'every student stays active when the guard trips');

        // The block is surfaced on the batch the dashboard reads.
        $batch = $this->db->query('SELECT * FROM student_import_batch ORDER BY batch_id DESC LIMIT 1')->fetch();
        self::assertSame('complete', $batch['status']);
        self::assertStringContainsString('DROP-OUT BLOCKED', (string) $batch['message']);
    }

    public function testEmptyFeedAgainstLargePopulationIsBlocked(): void
    {
        $seed = [];
        for ($i = 1; $i <= 25; $i++) {
            $seed[] = self::row((string) (2000 + $i));
        }
        $this->importer()->importRows($seed);

        // The classic failure mode: the ODBC pull returns zero rows.
        $res = $this->importer()->importRows([]);

        self::assertSame(1, $res['counts']['dropout_blocked']);
        self::assertSame(0, $res['counts']['deactivated']);
        self::assertSame(25, (int) $this->db->query('SELECT COUNT(*) FROM student WHERE is_active = 1')->fetchColumn());
    }

    public function testGuardIgnoredForSmallPopulations(): void
    {
        // Below the guardMinActive floor a 100% drop is plausible (tiny cohort),
        // so small populations still roll over — matching the staff valve.
        $this->importer()->importRows([self::row('3001'), self::row('3002'), self::row('3003')]);

        $res = $this->importer()->importRows([]);

        self::assertSame(0, $res['counts']['dropout_blocked']);
        self::assertSame(3, $res['counts']['deactivated']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM student WHERE is_active = 1')->fetchColumn());
    }

    public function testGuardRatioIsConfigurable(): void
    {
        putenv('STUDENT_DROPOUT_MAX_RATIO=0.9');
        try {
            $seed = [];
            for ($i = 1; $i <= 30; $i++) {
                $seed[] = self::row((string) (4000 + $i));
            }
            $this->importer()->importRows($seed);

            // 50% would drop — under the raised 90% threshold, so it proceeds.
            $next = [];
            for ($i = 1; $i <= 15; $i++) {
                $next[] = self::row((string) (4000 + $i));
            }
            $res = $this->importer()->importRows($next);

            self::assertSame(0, $res['counts']['dropout_blocked']);
            self::assertSame(15, $res['counts']['deactivated']);
        } finally {
            putenv('STUDENT_DROPOUT_MAX_RATIO');
        }
    }
}
