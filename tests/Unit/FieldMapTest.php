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
}
