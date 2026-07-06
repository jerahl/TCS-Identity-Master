<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\ColumnMap;
use App\Import\Normalizer;
use App\Import\UnmatchedSchoolException;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    private function normalizer(): Normalizer
    {
        // school_code_alias: nextgen 401 -> school 1; ethnicity White -> 5.
        return new Normalizer(
            ['nextgen' => ['401' => 1], 'powerschool' => ['4010' => 1]],
            ['white' => '5', 'black or african american' => '3'],
        );
    }

    public function testResolvesSchoolAndEthnicity(): void
    {
        // Real NextGen ITExtract.csv headers.
        $map = ColumnMap::for('nextgen');
        $raw = [
            'Employee Number' => '15241', 'First Name' => 'Jennifer', 'Last Name' => 'Marsh',
            'Hire Date' => '08/18/2014', 'Ethnicity Description' => 'White', 'Location Code' => '401',
            'Gender Type' => 'Female', 'Job Code Desc' => 'Teacher',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertSame('15241', $row->sourceKey);
        self::assertSame('15241', $row->employeeId);
        self::assertSame('Jennifer', $row->firstName);
        self::assertSame('Marsh', $row->lastName);
        self::assertSame('2014-08-18', $row->hireDate, 'm/d/Y should parse to Y-m-d');
        self::assertSame(1, $row->schoolId);
        self::assertSame('5', $row->ethnicityCode);
        self::assertTrue($row->isPrimary, 'defaults to primary when the feed has no Primary column');
        self::assertSame([], $row->warnings);
    }

    public function testCarriesFullNextGenContactAndPositionFields(): void
    {
        // The full NextGen ITExtract column set — contact + position fields that
        // were previously dropped must now be normalized onto the row.
        $map = ColumnMap::for('nextgen');
        $raw = [
            'Employee Number' => '15241', 'First Name' => 'Jennifer', 'Last Name' => 'Marsh',
            'EMail Address' => 'jmarsh@example.org', 'Position Number' => 'P-7781',
            'Location Code' => '401', 'CCTR Description' => 'Central High Math',
            'JOB CODE' => 'TCH', 'Job Code Desc' => 'Teacher Mathematics',
            'Hire Date' => '08/18/2014', 'Position Start Date' => '08/01/2020',
            'Position End Date' => '', 'Ethnicity Description' => 'White', 'Gender Type' => 'Female',
            'Phone Number' => '205-555-0100', 'Address 1' => '12 Oak St', 'Address 2' => 'Apt 4',
            'City' => 'Tuscaloosa', 'State Code' => 'AL', 'Zip Code' => '35401',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertSame('jmarsh@example.org', $row->hrEmail);
        self::assertSame('P-7781', $row->positionNumber);
        self::assertSame('Central High Math', $row->cctrDescription);
        self::assertSame('TCH', $row->jobCode);
        self::assertSame('Teacher Mathematics', $row->title);
        self::assertSame('2020-08-01', $row->positionStartDate, 'm/d/Y parses to Y-m-d');
        self::assertSame('205-555-0100', $row->phone);
        self::assertSame('12 Oak St', $row->address1);
        self::assertSame('Apt 4', $row->address2);
        self::assertSame('Tuscaloosa', $row->city);
        self::assertSame('AL', $row->stateCode);
        self::assertSame('35401', $row->zipCode);
        // NextGen carries no DOB/ALSID — those come from PowerSchool.
        self::assertNull($row->dob);
        self::assertNull($row->alsdeId);
    }

    public function testUnmappedValuesProduceWarningsNotFailures(): void
    {
        $map = ColumnMap::for('nextgen');
        $raw = [
            'Employee Number' => '999', 'First Name' => 'Dana', 'Last Name' => 'Reed',
            'Ethnicity Description' => 'Caucasian', 'Location Code' => '999',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertNull($row->schoolId);
        self::assertNull($row->ethnicityCode);
        self::assertSame('Caucasian', $row->ethnicitySource, 'raw value preserved when unmapped');
        self::assertCount(2, $row->warnings);
    }

    public function testPowerSchoolHeaderMapping(): void
    {
        // PowerSchool TEACHERS export. source_key = TEACHERS.ID (= AD uniqueId
        // minus the T); TeacherNumber is the NextGen Employee Number.
        $map = ColumnMap::for('powerschool');
        $raw = [
            'TEACHERS.ID' => '8422', 'TEACHERS.TeacherNumber' => '9849',
            'TEACHERS.First_Name' => 'Kirby', 'TEACHERS.Last_Name' => 'Abraham',
            'TEACHERS.LoginID' => 'kabraham',
        ];
        $norm = new Normalizer([], []);
        $row = $norm->normalize($raw, 'powerschool', $map, 'powerschool', 'powerschool', null);

        self::assertSame('8422', $row->sourceKey, 'source key = TEACHERS.ID');
        self::assertSame('9849', $row->employeeId, 'employee id = TeacherNumber (links to NextGen)');
        self::assertSame('Kirby', $row->firstName);
        self::assertSame('Abraham', $row->lastName);
    }

    public function testSchoolCodeMatchesIgnoringLeadingZeros(): void
    {
        // Alias seeded unpadded ("55"); feed sends zero-padded ("0055").
        $norm = new Normalizer(['nextgen' => ['55' => 7, '106' => 9]], []);
        $map = ColumnMap::for('nextgen');

        $row = $norm->normalize(['Employee Number' => '1', 'First Name' => 'A', 'Last Name' => 'B', 'Location Code' => '0055'], 'nextgen', $map);
        self::assertSame(7, $row->schoolId, '0055 should resolve to alias 55');
        self::assertSame([], $row->warnings);

        $row2 = $norm->normalize(['Employee Number' => '2', 'First Name' => 'C', 'Last Name' => 'D', 'Location Code' => '0106'], 'nextgen', $map);
        self::assertSame(9, $row2->schoolId, '0106 should resolve to alias 106');
    }

    public function testNormalizeSchoolCodeKeepsZero(): void
    {
        self::assertSame('55', Normalizer::normalizeSchoolCode('0055'));
        self::assertSame('106', Normalizer::normalizeSchoolCode('0106'));
        self::assertSame('0', Normalizer::normalizeSchoolCode('0'), 'all-zero stays "0" (Central Office)');
        self::assertSame('0', Normalizer::normalizeSchoolCode('000'));
    }

    public function testResolvesSchoolByName(): void
    {
        // Feed carries a "School Name" column; it matches a known school and wins
        // over any code lookup. schoolId resolves; the name is carried on the row.
        $norm = new Normalizer([], [], ['Central High School' => 42]);
        $map = ColumnMap::for('intern');
        $raw = ['InternID' => '90', 'FirstName' => 'Maya', 'LastName' => 'Patel', 'School Name' => 'Central High School'];

        $row = $norm->normalize($raw, 'intern', $map, 'intern_csv', 'powerschool', 'intern');

        self::assertSame(42, $row->schoolId);
        self::assertSame('Central High School', $row->schoolName);
        self::assertSame([], $row->warnings);
    }

    public function testUnmatchedSchoolNameIsAHardError(): void
    {
        $norm = new Normalizer([], [], ['Central High School' => 42]);
        $map = ColumnMap::for('intern');
        $raw = ['InternID' => '91', 'FirstName' => 'Devon', 'LastName' => 'Mills', 'School Name' => 'Nonexistent Academy'];

        $this->expectException(UnmatchedSchoolException::class);
        $this->expectExceptionMessage('Nonexistent Academy');
        $norm->normalize($raw, 'intern', $map, 'intern_csv', 'powerschool', 'intern');
    }

    public function testSchoolNameMatchIgnoresCaseAndPunctuation(): void
    {
        $norm = new Normalizer([], [], ['Martin Luther King Jr Elementary School' => 7]);
        $map = ColumnMap::for('intern');
        $raw = ['InternID' => '1', 'FirstName' => 'A', 'LastName' => 'B', 'School Name' => "  martin luther king, jr. elementary school "];

        $row = $norm->normalize($raw, 'intern', $map, 'intern_csv', 'powerschool', 'intern');
        self::assertSame(7, $row->schoolId);
    }

    public function testFallsBackToSchoolCodeWhenNoNameColumn(): void
    {
        // No "School Name" column present -> resolve the SchoolID code as before.
        $norm = new Normalizer(['powerschool' => ['2100' => 5]], [], ['Central High School' => 42]);
        $map = ColumnMap::for('intern');
        $raw = ['InternID' => '90', 'FirstName' => 'Maya', 'LastName' => 'Patel', 'SchoolID' => '2100'];

        $row = $norm->normalize($raw, 'intern', $map, 'intern_csv', 'powerschool', 'intern');
        self::assertSame(5, $row->schoolId);
        self::assertNull($row->schoolName);
        self::assertSame([], $row->warnings);
    }

    public function testNormalizeSchoolNameFoldsCaseAndPunctuation(): void
    {
        self::assertSame(
            Normalizer::normalizeSchoolName('Martin Luther King Jr Elementary School'),
            Normalizer::normalizeSchoolName('  Martin Luther King, Jr. Elementary School ')
        );
        self::assertSame('central high school', Normalizer::normalizeSchoolName('Central High School'));
    }

    public function testJobCodeClassifiesPersonTypeViaPositionMap(): void
    {
        // NextGen has no person-type column; the position map classifies by
        // JOB CODE so teachers come in as faculty instead of the staff default.
        $norm = new Normalizer([], [], [], ['TCH' => 'faculty', 'CUST' => 'staff']);
        $map = ColumnMap::for('nextgen');

        $row = $norm->normalize(
            ['Employee Number' => '1', 'First Name' => 'A', 'Last Name' => 'B', 'JOB CODE' => 'TCH'],
            'nextgen',
            $map
        );
        self::assertSame('faculty', $row->personType);
        self::assertSame([], $row->warnings, 'a mapped job code produces no warnings');

        $row2 = $norm->normalize(
            ['Employee Number' => '2', 'First Name' => 'C', 'Last Name' => 'D', 'JOB CODE' => 'CUST'],
            'nextgen',
            $map
        );
        self::assertSame('staff', $row2->personType);
    }

    public function testJobCodeMatchIgnoresCaseAndWhitespace(): void
    {
        $norm = new Normalizer([], [], [], ['tch' => 'faculty']);
        $map = ColumnMap::for('nextgen');

        $row = $norm->normalize(
            ['Employee Number' => '1', 'First Name' => 'A', 'Last Name' => 'B', 'JOB CODE' => ' TCH '],
            'nextgen',
            $map
        );
        self::assertSame('faculty', $row->personType);
    }

    public function testUnmappedJobCodeFallsThroughWithoutWarning(): void
    {
        // The position map may be partial by design (list only faculty codes):
        // an unmapped code is NOT a row warning; personType stays null so
        // PersonWriter's 'staff' default applies on create.
        $norm = new Normalizer([], [], [], ['TCH' => 'faculty']);
        $map = ColumnMap::for('nextgen');

        $row = $norm->normalize(
            ['Employee Number' => '1', 'First Name' => 'A', 'Last Name' => 'B', 'JOB CODE' => 'CNW'],
            'nextgen',
            $map
        );
        self::assertNull($row->personType);
        self::assertSame([], $row->warnings);
    }

    public function testExplicitFeedPersonTypeWinsOverPositionMap(): void
    {
        // A feed that carries its own type column overrides the job-code map.
        $norm = new Normalizer([], [], [], ['TCH' => 'faculty']);
        $map = ['source_key' => 'ID', 'first' => 'First', 'last' => 'Last', 'person_type' => 'Type', 'job_code' => 'Job'];

        $row = $norm->normalize(
            ['ID' => '1', 'First' => 'A', 'Last' => 'B', 'Type' => 'Contract', 'Job' => 'TCH'],
            'other',
            $map
        );
        self::assertSame('contractor', $row->personType);
    }

    public function testPositionMapBeatsSourceDefaultType(): void
    {
        // The job-code classification outranks the source's default type.
        $norm = new Normalizer([], [], [], ['TCH' => 'faculty']);
        $map = ColumnMap::for('nextgen');

        $row = $norm->normalize(
            ['Employee Number' => '1', 'First Name' => 'A', 'Last Name' => 'B', 'JOB CODE' => 'TCH'],
            'nextgen',
            $map,
            null,
            null,
            'staff'
        );
        self::assertSame('faculty', $row->personType);
    }

    public function testDateParsingVariants(): void
    {
        self::assertSame('1990-05-05', Normalizer::parseDate('1990-05-05'));
        self::assertSame('1990-05-05', Normalizer::parseDate('5/5/1990'));
        self::assertNull(Normalizer::parseDate(''));
        self::assertNull(Normalizer::parseDate(null));
    }
}
