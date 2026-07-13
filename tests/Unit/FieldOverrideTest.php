<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\NormalizedRow;
use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Per-field "manually overridden" flag: a hand-edited golden field is pinned in
 * person_field_override so subsequent feed imports leave it alone
 * (updateHrFields / upsertAssignment skip it) until it's cleared.
 */
final class FieldOverrideTest extends TestCase
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

    /** A NextGen feed row carrying the given HR values (title on the assignment). */
    private function feedRow(array $o = []): NormalizedRow
    {
        return new NormalizedRow(
            system: 'nextgen',
            sourceKey: $o['sourceKey'] ?? 'N1',
            firstName: $o['firstName'] ?? 'Feedfirst',
            lastName: $o['lastName'] ?? 'Feedlast',
            schoolId: $o['schoolId'] ?? null,
            title: $o['title'] ?? null,
        );
    }

    private function col(PDO $db, string $col): ?string
    {
        $v = $db->query("SELECT {$col} FROM person WHERE person_id = 1")->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    public function testSetGoldenFieldsPinsTheFieldAndImportSkipsIt(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name) VALUES (1, 'Jon', 'Smith')");
        $writer = $this->writer($db);

        // Hand-edit first_name → it should be pinned.
        $writer->setGoldenFields(1, ['first_name' => 'Jonathan'], 'admin', 'reconciled');
        self::assertContains('first_name', $writer->fieldOverrides(1));

        // A later feed carrying a DIFFERENT first_name AND last_name: first_name is
        // pinned (skipped), last_name is not (still synced).
        $row = $this->feedRow(['firstName' => 'Johnny', 'lastName' => 'Smithers']);
        $preview = $writer->previewHrChanges(1, $row);
        self::assertSame(['last_name'], array_map(static fn($c) => $c['field'], $preview));

        $writer->updateHrFields(1, $row, 'nextgen-import');
        self::assertSame('Jonathan', $this->col($db, 'first_name')); // pinned — not reverted
        self::assertSame('Smithers', $this->col($db, 'last_name'));  // not pinned — synced
    }

    public function testClearingTheOverrideLetsImportsSyncAgain(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name) VALUES (1, 'Jon', 'Smith')");
        $writer = $this->writer($db);
        $writer->setGoldenFields(1, ['first_name' => 'Jonathan'], 'admin', 'reconciled');

        self::assertTrue($writer->clearFieldOverride(1, 'first_name', 'admin'));
        self::assertNotContains('first_name', $writer->fieldOverrides(1));

        // Now the feed value wins again.
        $writer->updateHrFields(1, $this->feedRow(['firstName' => 'Johnny', 'lastName' => 'Smith']), 'nextgen-import');
        self::assertSame('Johnny', $this->col($db, 'first_name'));

        // Clearing a field that isn't pinned returns false.
        self::assertFalse($writer->clearFieldOverride(1, 'first_name', 'admin'));
    }

    public function testPinnedAssignmentTitleIsPreservedButOtherColumnsStillSync(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name, primary_school_id) VALUES (1, 'Jo', 'Doe', 5)");
        $db->exec("INSERT INTO school (school_id, name) VALUES (5, 'Central Office')");
        $db->exec("INSERT INTO assignment (id, person_id, school_id, title, job_code, is_primary, source)
                   VALUES (9, 1, 5, 'Teacher', 'OLD', 1, 'nextgen')");
        $writer = $this->writer($db);

        // Hand-edit the title → pinned.
        $writer->setAssignmentField(1, 9, 'title', 'Lead Teacher', 'admin', 'reconciled title');
        self::assertContains('title', $writer->fieldOverrides(1));

        // Feed carries a different title AND a new job code for the same school/source.
        $writer->upsertAssignment(1, $this->feedRow(['schoolId' => 5, 'title' => 'Substitute']), 'nextgen-import');

        $a = $db->query('SELECT title, job_code FROM assignment WHERE id = 9')->fetch();
        self::assertSame('Lead Teacher', $a['title']);       // pinned — preserved
        self::assertNull($a['job_code']);                    // not pinned — synced (feed row had none)
    }

    public function testUpdateProfilePinsChangedFeedFieldsButNotStatus(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name, status) VALUES (1, 'Jo', 'Doe', 'active')");
        $writer = $this->writer($db);

        // Edit form changes first_name (feed-owned) and status (not feed-owned).
        $writer->updateProfile(1, ['first_name' => 'Joanne', 'status' => 'disabled'], 'admin');

        $overrides = $writer->fieldOverrides(1);
        self::assertContains('first_name', $overrides);   // pinned
        self::assertNotContains('status', $overrides);    // status isn't feed-owned → not pinned
    }

    public function testNonPinnableFieldIsNeverRecorded(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, first_name, last_name) VALUES (1, 'Jo', 'Doe')");
        $writer = $this->writer($db);

        $writer->recordFieldOverride(1, 'status', 'admin');   // not in PINNABLE_FIELDS
        self::assertSame([], $writer->fieldOverrides(1));
    }
}
