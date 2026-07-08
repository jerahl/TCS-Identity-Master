<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PersonWriter::setGoldenFields / setAssignmentField — the writes behind the
 * person page's "Source field reconciliation" panel (PersonController::reconcile
 * picks a NextGen/PowerSchool value and hands it to these). Whitelisted columns
 * only, skips no-op writes, and audits before/after.
 */
final class ReconcileWriterTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // snapshot() reads a wide set of columns; declare them all.
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_type TEXT, status TEXT,
            first_name TEXT, middle_name TEXT, last_name TEXT, preferred_name TEXT,
            dob TEXT, gender TEXT, ethnicity_source TEXT, ethnicity_code TEXT,
            alsde_id TEXT, employee_id TEXT, primary_school_id INTEGER,
            hire_date TEXT, position_start_date TEXT, end_date TEXT,
            hr_email TEXT, position_number TEXT, cctr_description TEXT,
            phone TEXT, address1 TEXT, address2 TEXT, city TEXT, state_code TEXT,
            zip_code TEXT, updated_by TEXT)');
        $db->exec('CREATE TABLE assignment (
            id INTEGER PRIMARY KEY, person_id INTEGER, title TEXT)');
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

    private function col(PDO $db, string $col): ?string
    {
        // $col is a test literal; safe to interpolate.
        $v = $db->query("SELECT {$col} FROM person WHERE person_id = 1")->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    public function testSetGoldenFieldsWritesAndAudits(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name) VALUES (1, 'Jon', 'Smith')");

        $changed = $this->writer($db)->setGoldenFields(
            1,
            ['first_name' => 'Jonathan'],
            'tester',
            'Reconciled First Name to the PowerSchool value'
        );

        self::assertTrue($changed);
        self::assertSame('Jonathan', $this->col($db, 'first_name'));
        self::assertSame('tester', $this->col($db, 'updated_by'));
        self::assertSame('1', (string) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'update'")->fetchColumn());
        self::assertSame('1', (string) $db->query('SELECT COUNT(*) FROM lifecycle_event')->fetchColumn());
    }

    public function testSetGoldenFieldsWritesEthnicityAndCodeTogether(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, ethnicity_source, ethnicity_code) VALUES (1, 'White', 'X')");

        $changed = $this->writer($db)->setGoldenFields(
            1,
            ['ethnicity_source' => 'Black or African American', 'ethnicity_code' => '3'],
            'tester',
            'Reconciled Ethnicity'
        );

        self::assertTrue($changed);
        self::assertSame('Black or African American', $this->col($db, 'ethnicity_source'));
        self::assertSame('3', $this->col($db, 'ethnicity_code'));
    }

    public function testSetGoldenFieldsIsNoOpWhenUnchanged(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name) VALUES (1, 'Jon')");

        $changed = $this->writer($db)->setGoldenFields(1, ['first_name' => 'Jon'], 'tester', 'no change');

        self::assertFalse($changed);
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testSetGoldenFieldsRejectsNonWhitelistedColumn(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, status) VALUES (1, 'active')");

        $this->expectException(\InvalidArgumentException::class);
        $this->writer($db)->setGoldenFields(1, ['status' => 'disabled'], 'tester', 'nope');
    }

    public function testSetAssignmentFieldWritesTitle(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id) VALUES (1)");
        $db->exec("INSERT INTO assignment (id, person_id, title) VALUES (5, 1, 'Teacher')");

        $changed = $this->writer($db)->setAssignmentField(1, 5, 'title', 'Lead Teacher', 'tester', 'Reconciled title');

        self::assertTrue($changed);
        self::assertSame('Lead Teacher', (string) $db->query('SELECT title FROM assignment WHERE id = 5')->fetchColumn());
    }

    public function testSetAssignmentFieldIgnoresAssignmentOfAnotherPerson(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id) VALUES (1)");
        $db->exec("INSERT INTO assignment (id, person_id, title) VALUES (5, 2, 'Teacher')");

        $changed = $this->writer($db)->setAssignmentField(1, 5, 'title', 'Hijacked', 'tester', 'nope');

        self::assertFalse($changed);
        self::assertSame('Teacher', (string) $db->query('SELECT title FROM assignment WHERE id = 5')->fetchColumn());
    }

    public function testSetAssignmentFieldRejectsNonWhitelistedColumn(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id) VALUES (1)");
        $db->exec("INSERT INTO assignment (id, person_id, title) VALUES (5, 1, 'Teacher')");

        $this->expectException(\InvalidArgumentException::class);
        $this->writer($db)->setAssignmentField(1, 5, 'person_id', '99', 'tester', 'nope');
    }
}
