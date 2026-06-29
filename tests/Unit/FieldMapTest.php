<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\FieldMap;
use PHPUnit\Framework\TestCase;

/**
 * The NextGen↔PowerSchool crosswalk: it must cover every NextGen export column
 * the district sends, source DOB + ALSID from PowerSchool, and resolve a person's
 * per-field values from the golden record / primary assignment.
 */
final class FieldMapTest extends TestCase
{
    public function testCoversEveryNextGenExportColumn(): void
    {
        $nextgenHeaders = [];
        foreach (FieldMap::fields() as $f) {
            if ($f['nextgen'] !== null) {
                $nextgenHeaders[] = $f['nextgen'];
            }
        }

        $expected = [
            'Employee Number', 'Last Name', 'First Name', 'EMail Address', 'Position Number',
            'Location Code', 'CCTR Description', 'JOB CODE', 'Job Code Desc', 'Hire Date',
            'Position Start Date', 'Position End Date', 'Ethnicity Description', 'Gender Type',
            'Phone Number', 'Address 1', 'Address 2', 'City', 'State Code', 'Zip Code',
        ];
        foreach ($expected as $header) {
            self::assertContains($header, $nextgenHeaders, "NextGen column '{$header}' must be in the crosswalk");
        }
    }

    public function testDobAndAlsidAreSourcedFromPowerSchool(): void
    {
        $byKey = [];
        foreach (FieldMap::fields() as $f) {
            $byKey[$f['key']] = $f;
        }

        self::assertSame('powerschool', $byKey['dob']['origin']);
        self::assertNull($byKey['dob']['nextgen'], 'DOB has no NextGen column');
        self::assertSame('powerschool', $byKey['alsde_id']['origin']);
        self::assertNull($byKey['alsde_id']['nextgen'], 'ALSID has no NextGen column');
        self::assertSame('ALSID', $byKey['alsde_id']['label']);
    }

    public function testPersonRowsResolveGoldenAssignmentAndSchoolValues(): void
    {
        $person = [
            'employee_id' => '15241', 'first_name' => 'Jennifer', 'last_name' => 'Marsh',
            'hr_email' => 'jmarsh@example.org', 'gender' => 'Female',
            'dob' => '1985-03-09', 'alsde_id' => 'AL-552201',
            'phone' => '205-555-0100', 'city' => 'Tuscaloosa', 'state_code' => 'AL', 'zip_code' => '35401',
            'primary_school_id' => 7, 'primary_school_name' => 'Central High',
        ];
        $primary = ['title' => 'Teacher Mathematics', 'job_code' => 'TCH'];

        $rows = [];
        foreach (FieldMap::personRows($person, $primary) as $r) {
            $rows[$r['key']] = $r;
        }

        self::assertSame('15241', $rows['employee_id']['value']);
        self::assertSame('jmarsh@example.org', $rows['hr_email']['value']);
        self::assertSame('Central High', $rows['school_code']['value'], 'Location Code resolves to the primary school name');
        self::assertSame('TCH', $rows['job_code']['value'], 'job_code comes from the primary assignment');
        self::assertSame('Teacher Mathematics', $rows['title']['value']);
        self::assertSame('1985-03-09', $rows['dob']['value']);
        self::assertSame('AL-552201', $rows['alsde_id']['value']);
        self::assertSame('', $rows['address1']['value'], 'absent fields resolve to empty string');
        self::assertTrue($rows['phone']['pii'], 'phone is flagged PII');
    }

    public function testSchoolCodeFallsBackToIdWhenNameMissing(): void
    {
        $rows = [];
        foreach (FieldMap::personRows(['primary_school_id' => 42], null) as $r) {
            $rows[$r['key']] = $r;
        }
        self::assertSame('42', $rows['school_code']['value']);
        // Assignment-backed fields are empty when there's no primary assignment.
        self::assertSame('', $rows['title']['value']);
    }

    /** @return array<string,array<string,mixed>> reconcile rows keyed by field key */
    private function reconcile(?array $ngRaw, ?array $psFields, bool $idmOnly = false, array $person = [], ?array $primary = null): array
    {
        $rows = [];
        foreach (FieldMap::reconcileRows($person, $primary, $ngRaw, $psFields, $idmOnly) as $r) {
            $rows[$r['key']] = $r;
        }
        return $rows;
    }

    public function testReconcileMatchesAndFlagsDifferences(): void
    {
        $ngRaw = [
            'Employee Number' => '15241', 'First Name' => 'Jennifer', 'Last Name' => 'Marsh',
            'EMail Address' => 'jmarsh@example.org', 'Gender Type' => 'Female',
            'Hire Date' => '08/18/2014', 'Phone Number' => '205-555-0100',
            'Position Number' => 'P-7781',
        ];
        $psFields = [
            'employee_id' => '15241', 'first_name' => 'Jennifer', 'last_name' => 'MARSH',
            'hr_email' => 'different@example.org', 'gender' => 'Female',
            'hire_date' => '2014-08-18', 'phone' => '(205) 555-0100',
            'dob' => '1985-03-09', 'alsde_id' => 'AL-552201',
        ];
        $rows = $this->reconcile($ngRaw, $psFields);

        self::assertSame('match', $rows['employee_id']['state']);
        self::assertSame('match', $rows['last_name']['state'], 'case-insensitive name match');
        self::assertSame('match', $rows['hire_date']['state'], 'dates match across formats');
        self::assertSame('match', $rows['phone']['state'], 'phones match ignoring punctuation');
        self::assertSame('differ', $rows['hr_email']['state'], 'emails disagree');
        self::assertSame('match', $rows['gender']['state'], 'gender present on both sides');
        // Structural verdicts independent of values.
        self::assertSame('ng_only', $rows['position_number']['state']);
        self::assertSame('ps_only', $rows['dob']['state']);
        self::assertSame('AL-552201', $rows['alsde_id']['psValue']);
        self::assertSame('info', $rows['school_code']['state']);
        // Values surfaced from each side.
        self::assertSame('Jennifer', $rows['first_name']['ngValue']);
        self::assertSame('1985-03-09', $rows['dob']['psValue']);
    }

    public function testReconcileWithoutPowerSchoolGivesNoVerdict(): void
    {
        $ngRaw = ['Employee Number' => '15241', 'First Name' => 'Jennifer', 'Last Name' => 'Marsh'];
        $rows = $this->reconcile($ngRaw, null);

        self::assertSame('15241', $rows['employee_id']['ngValue']);
        self::assertSame('', $rows['employee_id']['psValue']);
        self::assertSame('', $rows['employee_id']['state'], 'no PS side -> cannot verify');
        // Structural verdicts still hold.
        self::assertSame('ps_only', $rows['dob']['state']);
        self::assertSame('ng_only', $rows['position_number']['state']);
    }

    public function testIdmOnlyRecordFallsBackToGoldenForNextGenColumn(): void
    {
        $person = [
            'first_name' => 'Elena', 'last_name' => 'Ruiz', 'gender' => 'Female',
            'primary_school_id' => 7, 'primary_school_name' => 'Central High',
        ];
        $primary = ['title' => 'Student Teacher', 'job_code' => 'INTERN'];
        $rows = $this->reconcile(null, null, true, $person, $primary);

        self::assertSame('Elena', $rows['first_name']['ngValue'], 'IDM-only NextGen column shows golden value');
        self::assertSame('Student Teacher', $rows['title']['ngValue']);
        self::assertSame('', $rows['first_name']['psValue']);
        self::assertSame('', $rows['first_name']['state'], 'nothing to verify for IDM-only');
    }
}
