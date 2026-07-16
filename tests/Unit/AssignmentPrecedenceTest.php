<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\NormalizedRow;
use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Primary-school authority: NextGen is the HR source of record for placement,
 * so while a person has any NextGen assignment, rows from the other feeds
 * (powerschool, intern, sub, contractor) may not move the primary school —
 * neither through the assignment primary flag (upsertAssignment) nor through
 * the person-level primary_school_id write (updateHrFields). And an operator
 * pin on primary_school_id freezes placement against EVERY feed, NextGen
 * included. This is what stops a lagging PowerSchool building from flipping a
 * transferred person back to their old school after every nightly import.
 */
final class AssignmentPrecedenceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_type TEXT, status TEXT DEFAULT \'active\',
            first_name TEXT, middle_name TEXT, last_name TEXT, preferred_name TEXT,
            dob TEXT, gender TEXT, ethnicity_source TEXT, ethnicity_code TEXT,
            alsde_id TEXT, employee_id TEXT, primary_school_id INTEGER,
            hire_date TEXT, position_start_date TEXT, end_date TEXT,
            hr_email TEXT, position_number TEXT, cctr_description TEXT,
            phone TEXT, address1 TEXT, address2 TEXT, city TEXT, state_code TEXT,
            zip_code TEXT, board_approval_date TEXT, board_approval_note TEXT,
            notes TEXT, raptor_group_override TEXT, updated_by TEXT)');
        $db->exec('CREATE TABLE assignment (
            id INTEGER PRIMARY KEY, person_id INTEGER, school_id INTEGER, title TEXT,
            job_code TEXT, fte TEXT, is_primary INTEGER DEFAULT 1,
            effective_date TEXT, end_date TEXT, source TEXT)');
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, ad_ou TEXT)');
        $db->exec('CREATE TABLE person_field_override (
            person_id INTEGER, field TEXT, actor TEXT, note TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (person_id, field))');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT,
            detail TEXT, actor TEXT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    private function writer(PDO $db): PersonWriter
    {
        return new PersonWriter($db, new AuditService($db));
    }

    private function row(string $system, int $schoolId, bool $primary = true): NormalizedRow
    {
        return new NormalizedRow(
            system: $system,
            sourceKey: 'K1',
            firstName: 'Preeti',
            lastName: 'Nichani',
            schoolId: $schoolId,
            isPrimary: $primary,
        );
    }

    private function seed(PDO $db): void
    {
        $db->exec("INSERT INTO person (person_id, first_name, last_name, primary_school_id) VALUES (1, 'Preeti', 'Nichani', 10)");
        // NextGen (HR) says school 10 — the current, correct placement.
        $db->exec("INSERT INTO assignment (person_id, school_id, is_primary, source) VALUES (1, 10, 1, 'nextgen')");
    }

    private function primarySchool(PDO $db): ?int
    {
        $v = $db->query('SELECT primary_school_id FROM person WHERE person_id = 1')->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    /** school_id => is_primary for person 1. */
    private function flags(PDO $db): array
    {
        $out = [];
        foreach ($db->query('SELECT school_id, is_primary FROM assignment WHERE person_id = 1')->fetchAll() as $r) {
            $out[(int) $r['school_id']] = (int) $r['is_primary'];
        }
        return $out;
    }

    public function testPowerSchoolCannotStealPrimaryFromNextgen(): void
    {
        $db = $this->db();
        $this->seed($db);

        // PowerSchool still carries the OLD building (20) and flags it primary —
        // exactly the lagging-transfer case. It must not move the placement.
        $this->writer($db)->upsertAssignment(1, $this->row('powerschool', 20), 'test');

        self::assertSame(10, $this->primarySchool($db), 'NextGen keeps the primary school');
        self::assertSame([10 => 1, 20 => 0], $this->flags($db), 'PS row stored, but never as primary');
    }

    public function testPowerSchoolClaimsPrimaryWhenPersonHasNoNextgenAssignment(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name) VALUES (1, 'Only', 'Ps')");

        $this->writer($db)->upsertAssignment(1, $this->row('powerschool', 20), 'test');

        self::assertSame(20, $this->primarySchool($db), 'PS-only people still get their primary from PS');
        self::assertSame([20 => 1], $this->flags($db));
    }

    public function testNextgenStillMovesThePrimary(): void
    {
        $db = $this->db();
        // Person previously placed by PowerSchool at 20; HR (NextGen) now says 10.
        $db->exec("INSERT INTO person (person_id, first_name, last_name, primary_school_id) VALUES (1, 'New', 'Hire', 20)");
        $db->exec("INSERT INTO assignment (person_id, school_id, is_primary, source) VALUES (1, 20, 1, 'powerschool')");

        $this->writer($db)->upsertAssignment(1, $this->row('nextgen', 10), 'test');

        self::assertSame(10, $this->primarySchool($db), 'NextGen moves the placement');
        self::assertSame([20 => 0, 10 => 1], $this->flags($db), 'old PS primary demoted');
    }

    public function testPinnedPrimarySchoolFreezesPlacementAgainstEveryFeed(): void
    {
        $db = $this->db();
        $this->seed($db);
        $db->exec("INSERT INTO person_field_override (person_id, field, actor) VALUES (1, 'primary_school_id', 'op')");

        // Even NextGen may not move a pinned placement.
        $this->writer($db)->upsertAssignment(1, $this->row('nextgen', 30), 'test');

        self::assertSame(10, $this->primarySchool($db), 'pin freezes primary_school_id');
        self::assertSame([10 => 1, 30 => 0], $this->flags($db), 'existing primary flag untouched; new row not primary');
    }

    public function testUpdateHrFieldsHonorsTheSamePrecedence(): void
    {
        $db = $this->db();
        $this->seed($db);
        $writer = $this->writer($db);

        // The person-level school write from a PowerSchool row must yield too…
        $writer->updateHrFields(1, $this->row('powerschool', 20), 'test');
        self::assertSame(10, $this->primarySchool($db), 'PS row may not move primary_school_id while NextGen places the person');

        // …while a NextGen row still updates it.
        $writer->updateHrFields(1, $this->row('nextgen', 30), 'test');
        self::assertSame(30, $this->primarySchool($db));
    }
}
