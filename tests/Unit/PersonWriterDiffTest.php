<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\NormalizedRow;
use App\Import\PersonWriter;
use PHPUnit\Framework\TestCase;

/**
 * The pure diff helpers that back both the real write and the dry-run preview.
 * DB-free: they take a snapshot/assignment array + a NormalizedRow and return the
 * columns that would change — so the two paths can never disagree.
 */
final class PersonWriterDiffTest extends TestCase
{
    private function row(array $overrides = []): NormalizedRow
    {
        return new NormalizedRow(
            system: $overrides['system'] ?? 'intern',
            sourceKey: '90',
            firstName: $overrides['firstName'] ?? 'Maya',
            lastName: $overrides['lastName'] ?? 'Patel',
            employeeId: $overrides['employeeId'] ?? null,
            schoolId: $overrides['schoolId'] ?? null,
            title: $overrides['title'] ?? null,
            personType: $overrides['personType'] ?? 'intern',
        );
    }

    public function testDiffHrFieldsReportsOnlyChangedColumns(): void
    {
        $before = [
            'first_name' => 'Maya', 'last_name' => 'Patel', 'primary_school_id' => 5,
            'employee_id' => null, 'person_type' => 'intern',
        ];
        $row = $this->row(['lastName' => 'Patel-Jones', 'schoolId' => 7]);

        $changes = PersonWriter::diffHrFields($before, $row);
        $byField = [];
        foreach ($changes as $c) {
            $byField[$c['field']] = $c;
        }

        self::assertArrayHasKey('last_name', $byField, 'changed surname is reported');
        self::assertSame('Patel', $byField['last_name']['from']);
        self::assertSame('Patel-Jones', $byField['last_name']['to']);
        self::assertArrayHasKey('primary_school_id', $byField, 'changed school is reported');
        self::assertSame('5', $byField['primary_school_id']['from']);
        self::assertSame(7, $byField['primary_school_id']['to']);
        self::assertArrayNotHasKey('first_name', $byField, 'unchanged first name is not reported');
    }

    public function testDiffHrFieldsNeverBlanksOutExistingValues(): void
    {
        // Feed omits middle name / dob (null); existing values must be left alone.
        $before = ['first_name' => 'Maya', 'last_name' => 'Patel', 'middle_name' => 'Rae', 'dob' => '2002-03-15'];
        $row = $this->row(); // middleName / dob null

        $changes = PersonWriter::diffHrFields($before, $row);
        $fields = array_column($changes, 'field');

        self::assertNotContains('middle_name', $fields);
        self::assertNotContains('dob', $fields);
    }

    public function testDiffHrFieldsEmptyWhenNothingChanges(): void
    {
        $before = ['first_name' => 'Maya', 'last_name' => 'Patel', 'person_type' => 'intern'];
        self::assertSame([], PersonWriter::diffHrFields($before, $this->row()));
    }

    public function testDiffAssignmentReturnsAllValuesOnCreate(): void
    {
        $row = $this->row(['schoolId' => 7, 'title' => 'Teaching Intern']);
        $vals = PersonWriter::diffAssignment(null, $row);

        self::assertSame('Teaching Intern', $vals['title']);
        self::assertArrayHasKey('is_primary', $vals);
        self::assertArrayHasKey('end_date', $vals);
    }

    public function testDiffAssignmentReturnsOnlyChangedColumnsOnUpdate(): void
    {
        $existing = ['title' => 'Teaching Intern', 'job_code' => null, 'fte' => null, 'is_primary' => 1, 'effective_date' => null, 'end_date' => null];
        $row = $this->row(['schoolId' => 7, 'title' => 'Lead Intern']);

        $changed = PersonWriter::diffAssignment($existing, $row);
        self::assertSame(['title' => 'Lead Intern'], $changed, 'only the retitled column changes');
    }
}
