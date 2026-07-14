<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\PowerSchoolAutoCommExporter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Drives the AutoComm/DIM staff exporter against an in-memory SQLite copy of
 * the golden-record tables (portable — no MySQL). Covers the contractual
 * column order, the value mappings (HireDate, Sched_Gender, StaffStatus,
 * FedEthnicity), the FedRaceDecline<->race-file coupling rule, hard-failure
 * skips (missing TeacherNumber, unmapped school, unmapped race code), the SSO
 * file's existing-in-PS scope, field sanitizing, rendering (delimiter, CRLF,
 * header rules) and the empty-file guard.
 */
final class PowerSchoolAutoCommExporterTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, ps_school_id TEXT)');
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_type TEXT, status TEXT,
            first_name TEXT, last_name TEXT, gender TEXT,
            ethnicity_source TEXT, ethnicity_code TEXT, alsde_id TEXT,
            employee_id TEXT, hire_date TEXT, email TEXT, username TEXT,
            primary_school_id INTEGER)');
        $db->exec('CREATE TABLE assignment (
            id INTEGER PRIMARY KEY, person_id INTEGER, school_id INTEGER,
            title TEXT, is_primary INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE person_source_id (
            id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT,
            source_key TEXT, is_active INTEGER DEFAULT 1)');

        $db->exec("INSERT INTO school VALUES (1,'Northridge High School','75'), (2,'No-PS-Number School',''), (3,'Eastwood Middle','60')");

        $seed = $db->prepare('INSERT INTO person
            (person_id, person_type, status, first_name, last_name, gender, ethnicity_source,
             ethnicity_code, alsde_id, employee_id, hire_date, email, username, primary_school_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        // 1: teacher, complete record, White, in PS already (multi-school: primary NHS + secondary EMS)
        $seed->execute([1, 'faculty', 'active', 'Ada', 'Bell', 'Female', 'White', '5', 'AL123', 'T1001', '2019-08-01', 'abell@example.org', 'abell', 1]);
        // 2: staff, Hispanic/Latino (ethnicity only -> no race row, FedRaceDecline empty), NOT in PS
        $seed->execute([2, 'staff', 'active', 'Beto', 'Cruz', 'M', 'Hispanic', '4', '', 'T1002', null, 'bcruz@example.org', 'bcruz', 1]);
        // 3: sub, no hire date, nonbinary gender value, no race data, in PS but no username yet
        $seed->execute([3, 'sub', 'pending', 'Cam', 'Dee', 'Nonbinary', '', '', 'AL999', 'T1003', null, 'cdee@example.org', '', 1]);
        // 4: missing employee id -> skipped everywhere, hard failure
        $seed->execute([4, 'faculty', 'active', 'Eve', 'Faulk', 'F', 'Black', '3', '', '', '2020-01-15', 'efaulk@example.org', 'efaulk', 1]);
        // 5: school without a PS number -> unmapped location hard failure
        $seed->execute([5, 'staff', 'active', 'Gus', 'Hart', 'M', 'White', '5', '', 'T1005', '2021-05-10', 'ghart@example.org', 'ghart', 2]);
        // 6: unmapped race code -> race hard failure; Two or More Races person; title needs sanitizing
        $seed->execute([6, 'faculty', 'active', 'Ida', 'Jones', 'F', 'Martian', '9', '', 'T1006', '2018-03-02', 'ijones@example.org', 'ijones', 3]);
        // 7: disabled -> out of scope entirely
        $seed->execute([7, 'staff', 'disabled', 'Old', 'Karr', 'M', 'White', '5', '', 'T1007', null, 'okarr@example.org', 'okarr', 1]);
        // 8: Two or More Races (code 7), in PS, everything set
        $seed->execute([8, 'staff', 'active', 'Lee', 'Moss', 'male', 'Multiracial', '7', '', 'T1008', '2022-07-01', 'lmoss@example.org', 'lmoss', 3]);

        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES
            (1, 1, 'Teacher', 1), (1, 3, 'Coach', 0),
            (2, 1, 'Custodian', 1),
            (6, 3, \"Media\tSpecialist\", 1),
            (8, 3, 'Aide', 1)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES
            (1, 'powerschool', '1001', 1),
            (3, 'powerschool', '1003', 1),
            (8, 'powerschool', '1008', 1),
            (2, 'powerschool', 'stale', 0),
            (5, 'ad', 'guid-5', 1)");
        return $db;
    }

    /** @return array{create:array,sso:array,race:array,report:array} */
    private function build(): array
    {
        $exporter = new PowerSchoolAutoCommExporter($this->db());
        return $exporter->buildAll($exporter->people());
    }

    private static function createRowFor(array $built, string $empId): ?array
    {
        foreach ($built['create'] as $row) {
            if ($row[0] === $empId) {
                return array_combine(PowerSchoolAutoCommExporter::CREATE_FIELDS, $row);
            }
        }
        return null;
    }

    public function testCreateFileColumnOrderAndFullRecord(): void
    {
        $built = $this->build();
        $row = self::createRowFor($built, 'T1001');
        self::assertNotNull($row);
        self::assertSame(16, count($row));
        // Contractual positional order — spot-check via the raw row.
        $raw = null;
        foreach ($built['create'] as $r) {
            if ($r[0] === 'T1001') {
                $raw = $r;
            }
        }
        self::assertSame(
            ['T1001', 'Bell', 'Ada', '75', '75', 'Teacher', '08/01/2019', 'F', '1', '1', 'AL123', '0', '0', 'abell', 'abell', 'abell@example.org'],
            $raw
        );
        // Multi-school person exports the PRIMARY school in both school columns.
        self::assertSame('75', $row['HomeSchoolId']);
        self::assertSame('75', $row['SchoolID']);
    }

    public function testNoPasswordFieldsAnywhere(): void
    {
        foreach ([PowerSchoolAutoCommExporter::CREATE_FIELDS, PowerSchoolAutoCommExporter::SSO_FIELDS, PowerSchoolAutoCommExporter::RACE_HEADERS] as $fields) {
            foreach ($fields as $f) {
                self::assertStringNotContainsStringIgnoringCase('password', $f);
                self::assertStringNotContainsStringIgnoringCase('loginpw', $f);
            }
        }
    }

    public function testMissingHireDateAndNonBinaryGenderExportEmptyWithWarning(): void
    {
        $built = $this->build();
        $row = self::createRowFor($built, 'T1003');
        self::assertNotNull($row);
        self::assertSame('', $row['S_USR_X.HireDate']);
        self::assertSame('', $row['Sched_Gender']);
        self::assertSame('4', $row['StaffStatus']); // sub -> 4 (Substitute)
        self::assertNotEmpty(array_filter($built['report']['warnings'], fn ($w) => str_contains($w, 'Nonbinary')));
    }

    public function testHispanicOnlyPersonFedEthnicityAndCoupling(): void
    {
        $built = $this->build();
        $row = self::createRowFor($built, 'T1002');
        self::assertNotNull($row);
        self::assertSame('1', $row['FedEthnicity']);
        // Coupling rule: no race rows -> FedRaceDecline must be EMPTY, never 0.
        self::assertSame('', $row['FedRaceDecline']);
        foreach ($built['race'] as $r) {
            self::assertNotSame('T1002', $r[0], 'Hispanic-only person must not get a TeacherRace row');
        }
        self::assertNotEmpty(array_filter($built['report']['coupling_flags'], fn ($w) => str_contains($w, 'Cruz')));
    }

    public function testFedRaceDeclineZeroOnlyWithRaceRow(): void
    {
        $built = $this->build();
        $raceEmpIds = array_map(fn ($r) => $r[0], $built['race']);
        foreach ($built['create'] as $row) {
            $keyed = array_combine(PowerSchoolAutoCommExporter::CREATE_FIELDS, $row);
            if ($keyed['FedRaceDecline'] === '0') {
                self::assertContains($keyed['TeacherNumber'], $raceEmpIds,
                    'FedRaceDecline=0 requires a row in the race file (coupling rule)');
            }
        }
    }

    public function testMissingEmployeeIdIsSkippedAndHardFailure(): void
    {
        $built = $this->build();
        self::assertNull(self::createRowFor($built, ''));
        self::assertNotEmpty(array_filter($built['report']['errors']['create'], fn ($e) => str_contains($e, 'Faulk')));
        $skipped = array_filter($built['report']['skipped'], fn ($s) => str_contains($s['who'], 'Faulk'));
        self::assertNotEmpty($skipped);
        // No race row without a TeacherNumber either.
        foreach ($built['race'] as $r) {
            self::assertNotSame('', $r[0]);
        }
    }

    public function testUnmappedSchoolIsHardFailure(): void
    {
        $built = $this->build();
        self::assertNull(self::createRowFor($built, 'T1005'));
        self::assertNotEmpty(array_filter($built['report']['errors']['create'],
            fn ($e) => str_contains($e, 'Hart') && str_contains($e, 'unmapped location')));
    }

    public function testUnmappedRaceCodeIsHardFailureAndNeverPassedThrough(): void
    {
        $built = $this->build();
        self::assertNotEmpty(array_filter($built['report']['errors']['race'],
            fn ($e) => str_contains($e, 'Jones') && str_contains($e, 'unmapped race code 9')));
        foreach ($built['race'] as $r) {
            self::assertNotSame('9', $r[1], 'unmapped race codes must never pass through');
        }
        // Their create row still exports, with FedRaceDecline empty.
        $row = self::createRowFor($built, 'T1006');
        self::assertNotNull($row);
        self::assertSame('', $row['FedRaceDecline']);
    }

    public function testTwoOrMoreRacesUsesConfigMap(): void
    {
        $built = $this->build();
        $rows = array_values(array_filter($built['race'], fn ($r) => $r[0] === 'T1008'));
        self::assertCount(1, $rows);
        self::assertSame(PowerSchoolAutoCommExporter::PS_RACE_MAP['7'], $rows[0][1]);
    }

    public function testSsoFileOnlyContainsPeopleAlreadyInPowerSchool(): void
    {
        $built = $this->build();
        $empIds = array_map(fn ($r) => $r[0], $built['sso']);
        self::assertContains('T1001', $empIds);
        self::assertContains('T1008', $empIds);
        // Not in PS (no active powerschool source id) -> must never appear.
        self::assertNotContains('T1002', $empIds);
        self::assertNotContains('T1005', $empIds);
        // In PS but no AD username yet -> held back (a blank would wipe LoginID).
        self::assertNotContains('T1003', $empIds);
        self::assertNotEmpty(array_filter($built['report']['skipped'],
            fn ($s) => $s['mode'] === 'sso' && str_contains($s['who'], 'Dee')));
        // Column order: TeacherNumber, LoginID, TeacherLoginID (same), Email_Addr.
        $row = array_values(array_filter($built['sso'], fn ($r) => $r[0] === 'T1001'))[0];
        self::assertSame(['T1001', 'abell', 'abell', 'abell@example.org'], $row);
    }

    public function testTabInFieldValueIsSanitizedAndLogged(): void
    {
        $built = $this->build();
        $row = self::createRowFor($built, 'T1006');
        self::assertNotNull($row);
        self::assertSame('Media Specialist', $row['Title']);
        self::assertNotEmpty(array_filter($built['report']['sanitized'], fn ($s) => str_contains($s, 'Title')));
    }

    public function testRenderAutoCommNoHeaderRaceHasHeaderCrlfAndTabs(): void
    {
        $body = PowerSchoolAutoCommExporter::render([['a', 'b'], ['c', 'd']]);
        self::assertSame("a\tb\r\nc\td\r\n", $body);
        $race = PowerSchoolAutoCommExporter::render([['T1', '5']], PowerSchoolAutoCommExporter::RACE_HEADERS);
        self::assertSame("Users.TeacherNumber\tTeacherRace.RaceCd\r\nT1\t5\r\n", $race);
        self::assertSame('', PowerSchoolAutoCommExporter::render([]));
    }

    public function testEmptyFileGuard(): void
    {
        // No previous file / empty previous file -> never trips.
        self::assertFalse(PowerSchoolAutoCommExporter::guardTrips(null, 0, 0.5));
        self::assertFalse(PowerSchoolAutoCommExporter::guardTrips(0, 0, 0.5));
        // Collapse below the ratio trips; staying at/above it doesn't.
        self::assertTrue(PowerSchoolAutoCommExporter::guardTrips(1000, 0, 0.5));
        self::assertTrue(PowerSchoolAutoCommExporter::guardTrips(1000, 499, 0.5));
        self::assertFalse(PowerSchoolAutoCommExporter::guardTrips(1000, 500, 0.5));
        self::assertFalse(PowerSchoolAutoCommExporter::guardTrips(1000, 1200, 0.5));
    }

    public function testCountDataRowsHonorsHeaderAndAtomicWrite(): void
    {
        $dir = sys_get_temp_dir() . '/pstest_' . bin2hex(random_bytes(4));
        $path = $dir . '/ps_staff_race.txt';
        PowerSchoolAutoCommExporter::writeFileAtomic($path,
            PowerSchoolAutoCommExporter::render([['T1', '5'], ['T2', '3']], PowerSchoolAutoCommExporter::RACE_HEADERS));
        self::assertSame(2, PowerSchoolAutoCommExporter::countDataRows($path, true));
        self::assertFileDoesNotExist($path . '.tmp');
        PowerSchoolAutoCommExporter::writeFileAtomic($dir . '/ps_staff_create.txt',
            PowerSchoolAutoCommExporter::render([['x'], ['y'], ['z']]));
        self::assertSame(3, PowerSchoolAutoCommExporter::countDataRows($dir . '/ps_staff_create.txt', false));
        self::assertNull(PowerSchoolAutoCommExporter::countDataRows($dir . '/nope.txt', false));
        unlink($path);
        unlink($dir . '/ps_staff_create.txt');
        rmdir($dir);
    }
}
