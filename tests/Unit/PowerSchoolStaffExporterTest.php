<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\PowerSchoolStaffExporter;
use App\Sync\Sftp\InMemorySftpClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PowerSchoolStaffExporter — the tab-delimited files behind
 * bin/export_powerschool.php. Demographics: one row per active/pending staff
 * member (DIM -> USERSCOREFIELDS, matched on USERS.TeacherNumber).
 * Assignments: one row per staff member per school (AutoComm -> Teachers,
 * prefix-free headers, sorted by SchoolID). Every rejected row, truncation,
 * and unmapped person type lands in the exceptions list.
 */
final class PowerSchoolStaffExporterTest extends TestCase
{
    private const TODAY = '2026-07-15';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_type TEXT, status TEXT,
            first_name TEXT, middle_name TEXT, last_name TEXT,
            alsde_id TEXT, employee_id TEXT, primary_school_id INTEGER,
            hire_date TEXT, email TEXT, username TEXT)');
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, ps_school_id TEXT)');
        $db->exec('CREATE TABLE assignment (
            id INTEGER PRIMARY KEY, person_id INTEGER, school_id INTEGER,
            title TEXT, is_primary INTEGER DEFAULT 0, end_date TEXT)');

        $db->exec("INSERT INTO school VALUES (1, 'Central High', '310')");   // 3 digits -> padded
        $db->exec("INSERT INTO school VALUES (2, 'Eastwood Middle', '0220')");
        $db->exec("INSERT INTO school VALUES (3, 'Annex', NULL)");           // no School_Number

        // 1: complete faculty member, one primary assignment, 3-digit school.
        $db->exec("INSERT INTO person VALUES (1, 'faculty', 'active', 'Avery', 'Q', 'Baker',
            'AL-100001', 'E1001', 1, '2020-08-01', 'abaker@example.org', 'abaker')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 1, 'Teacher', 1)");

        // 2: multi-school staff — current at school 2, ended at school 1.
        $db->exec("INSERT INTO person VALUES (2, 'staff', 'pending', 'Casey', NULL, 'Adams',
            NULL, 'E1002', 2, '2026-08-01', 'cadams@example.org', 'cadams')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (2, 2, 'Bookkeeper', 1)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary, end_date)
                   VALUES (2, 1, 'Bookkeeper', 0, '2026-05-31')");

        // 3: terminated -> excluded everywhere.
        $db->exec("INSERT INTO person VALUES (3, 'staff', 'terminated', 'Drew', NULL, 'Cole',
            NULL, 'E1003', 1, NULL, NULL, NULL)");

        // 4: no employee id -> rejected from both files (exception).
        $db->exec("INSERT INTO person VALUES (4, 'staff', 'active', 'Em', NULL, 'Dane',
            NULL, '', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (4, 1, 'Aide', 1)");

        // 5: home school has no ps_school_id -> demographics row rejected;
        //    assignment at the same school rejected too.
        $db->exec("INSERT INTO person VALUES (5, 'staff', 'active', 'Blair', NULL, 'Ellis',
            NULL, 'E1005', 3, NULL, 'bellis@example.org', 'bellis')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (5, 3, 'Clerk', 1)");

        // 6: substitute with NO assignment rows -> falls back to primary school;
        //    'sub' maps to StaffStatus 4.
        $db->exec("INSERT INTO person VALUES (6, 'sub', 'active', 'Morgan', NULL, 'Foster',
            NULL, 'E1006', 2, NULL, 'mfoster@example.org', 'mfoster')");

        return $db;
    }

    private function export(?PDO $db = null): array
    {
        return (new PowerSchoolStaffExporter($db ?? $this->db(), self::TODAY))->export();
    }

    public function testDemographicRowsCoverActiveAndPendingStaffOnly(): void
    {
        $res = $this->export();

        self::assertSame(['E1002', 'E1001', 'E1006'],
            array_column($res['demographics'], 'USERS.TeacherNumber'),
            'ordered by name (Adams, Baker, Foster); terminated, id-less, and unresolvable-school people rejected');
    }

    public function testDemographicRowMapsSpecColumns(): void
    {
        $rows = $this->export()['demographics'];
        $row = $rows[1]; // Baker

        self::assertSame(PowerSchoolStaffExporter::DEMOGRAPHIC_HEADERS, array_keys($row));
        self::assertSame('E1001', $row['USERS.TeacherNumber']);
        self::assertSame('Baker', $row['USERS.Last_Name']);
        self::assertSame('Avery', $row['USERS.First_Name']);
        self::assertSame('Q', $row['USERS.Middle_Name']);
        self::assertSame('abaker@example.org', $row['USERS.Email_Addr']);
        self::assertSame('E1001', $row['USERS.SIF_StatePrid'], 'district practice: StatePrid = employee id');
        self::assertSame('Teacher', $row['USERS.Title'], 'primary assignment title');
        self::assertSame('0310', $row['USERS.HomeSchoolId'], '3-digit code padded to the 4-digit School_Number');
        self::assertSame('abaker', $row['USERS.TeacherLoginID']);
        self::assertSame('08/01/2020', $row['S_USR_X.hiredate'], 'Y-m-d -> MM/DD/YYYY');
        self::assertSame('AL-100001', $row['S_USR_X.state_staffnumber'], 'ALSDE ID');
    }

    public function testNoRaceEthnicityGenderDobOrPasswordColumns(): void
    {
        $headers = implode(' ', array_merge(
            PowerSchoolStaffExporter::DEMOGRAPHIC_HEADERS,
            PowerSchoolStaffExporter::ASSIGNMENT_HEADERS));

        foreach (['Race', 'Ethnicity', 'FedEthnicity', 'FedRaceDecline', 'gender', 'dob',
                  'Password', 'SSN', 'employmentstatus'] as $banned) {
            self::assertStringNotContainsStringIgnoringCase($banned, $headers,
                "no {$banned} column — out of scope or no IDM source");
        }
    }

    public function testAssignmentRowsOnePerSchoolSortedBySchoolId(): void
    {
        $rows = $this->export()['assignments'];

        self::assertSame([
            ['TeacherNumber' => 'E1002', 'SchoolID' => '0220', 'Status' => '1', 'StaffStatus' => '2'],
            ['TeacherNumber' => 'E1006', 'SchoolID' => '0220', 'Status' => '1', 'StaffStatus' => '4'],
            ['TeacherNumber' => 'E1001', 'SchoolID' => '0310', 'Status' => '1', 'StaffStatus' => '1'],
            ['TeacherNumber' => 'E1002', 'SchoolID' => '0310', 'Status' => '2', 'StaffStatus' => '2'],
        ], $rows, 'sorted by SchoolID; ended assignment -> Status 2; sub falls back to primary school with StaffStatus 4');
    }

    public function testValidationExceptionsAreExplicit(): void
    {
        $res = $this->export();
        $text = implode("\n", $res['exceptions']);

        self::assertStringContainsString('Dane, Em (person 4) — missing TeacherNumber', $text);
        self::assertStringContainsString('Ellis, Blair (person 5) — home school cannot be resolved', $text);
    }

    public function testSubIsMappedNotLoggedAsUnmapped(): void
    {
        $res = $this->export();
        self::assertStringNotContainsString("unmapped person type 'sub'",
            implode("\n", $res['exceptions']));
    }

    public function testUnmappedPersonTypeDefaultsToStaffAndLogsOnce(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (7, 'contractor', 'active', 'Rene', NULL, 'Gray',
            NULL, 'E1007', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (7, 1, 'Contractor', 1)");
        $db->exec("INSERT INTO person VALUES (8, 'contractor', 'active', 'Sam', NULL, 'Hale',
            NULL, 'E1008', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (8, 1, 'Contractor', 1)");

        $res = $this->export($db);
        $byKey = array_column($res['assignments'], 'StaffStatus', 'TeacherNumber');
        self::assertSame('2', $byKey['E1007'], 'unmapped type defaults to 2 = Staff');

        $mentions = array_filter($res['exceptions'],
            static fn(string $e): bool => str_contains($e, "unmapped person type 'contractor'"));
        self::assertCount(1, $mentions, 'each unmapped type is logged once, not per person');
    }

    public function testDuplicateTeacherNumberIsRejected(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (7, 'staff', 'active', 'Twin', NULL, 'Zed',
            NULL, 'E1001', 1, NULL, NULL, NULL)"); // same employee id as Baker

        $res = $this->export($db);
        self::assertSame(['E1002', 'E1001', 'E1006'],
            array_column($res['demographics'], 'USERS.TeacherNumber'),
            'second E1001 rejected, first kept');
        self::assertStringContainsString("duplicate TeacherNumber 'E1001'",
            implode("\n", $res['exceptions']));
    }

    public function testOverLongValuesAreTruncatedAndLoggedButKeyRejects(): void
    {
        $db = $this->db();
        $longTitle = str_repeat('Coordinator ', 5); // > 40 chars
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary)
                   VALUES (6, 2, '" . trim($longTitle) . "', 1)");
        $db->exec("INSERT INTO person VALUES (7, 'staff', 'active', 'Key', NULL, 'Long',
            NULL, '" . str_repeat('9', 21) . "', 1, NULL, NULL, NULL)");

        $res = $this->export($db);
        $text = implode("\n", $res['exceptions']);

        $foster = array_values(array_filter($res['demographics'],
            static fn(array $r): bool => $r['USERS.TeacherNumber'] === 'E1006'))[0];
        self::assertSame(40, mb_strlen($foster['USERS.Title']), 'Title truncated to 40');
        self::assertStringContainsString('USERS.Title truncated to 40 chars', $text);

        self::assertNotContains(str_repeat('9', 20),
            array_column($res['demographics'], 'USERS.TeacherNumber'),
            'over-long match key is rejected, never truncated');
        self::assertStringContainsString('match key is never truncated', $text);
    }

    public function testTabsAndNewlinesAreStrippedFromValues(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (7, 'staff', 'active', 'Pat', NULL, 'ONeal',
            NULL, 'E1007', 1, NULL, NULL, NULL)");
        $db->exec("UPDATE person SET last_name = 'O''Neal' || char(9) || 'Jr' WHERE person_id = 7");

        $row = array_values(array_filter($this->export($db)['demographics'],
            static fn(array $r): bool => $r['USERS.TeacherNumber'] === 'E1007'))[0];
        self::assertSame("O'Neal Jr", $row['USERS.Last_Name'], 'tab replaced with a space');
    }

    public function testRenderIsTabDelimitedWithHeaderAndTrailingNewline(): void
    {
        $res = $this->export();
        $txt = PowerSchoolStaffExporter::render(
            PowerSchoolStaffExporter::ASSIGNMENT_HEADERS, $res['assignments']);

        $lines = explode("\r\n", rtrim($txt, "\r\n"));
        self::assertSame("TeacherNumber\tSchoolID\tStatus\tStaffStatus", $lines[0],
            'prefix-free headers for the Teachers import');
        self::assertCount(1 + count($res['assignments']), $lines, 'no blank rows');
        self::assertStringEndsWith("\r\n", $txt, 'one trailing newline');
        self::assertStringNotContainsString('"', $txt, 'no quoting');
    }

    public function testSampleIsHeaderPlusAtMostThreeRows(): void
    {
        $res = $this->export();
        $sample = PowerSchoolStaffExporter::sample(
            PowerSchoolStaffExporter::ASSIGNMENT_HEADERS, $res['assignments']);
        self::assertCount(4, explode("\r\n", rtrim($sample, "\r\n")), 'header + 3 rows');
    }

    public function testSummaryCountsRowsExceptionsAndDistinctSchools(): void
    {
        $s = $this->export()['summary'];
        self::assertSame(3, $s['demographics']);
        self::assertSame(4, $s['assignments']);
        self::assertSame(2, $s['schools'], '0220 and 0310');
        self::assertGreaterThanOrEqual(2, $s['exceptions']);
    }

    public function testExceptionsFileRendering(): void
    {
        self::assertSame('', PowerSchoolStaffExporter::exceptionsFile([]));
        self::assertSame("a\r\nb\r\n", PowerSchoolStaffExporter::exceptionsFile(['a', 'b']));
    }

    public function testPsSchoolIdPadsShortNumericCodesToFourDigits(): void
    {
        self::assertSame('0130', PowerSchoolStaffExporter::psSchoolId('130'), '3 digits -> leading zero');
        self::assertSame('0045', PowerSchoolStaffExporter::psSchoolId('45'));
        self::assertSame('0310', PowerSchoolStaffExporter::psSchoolId(' 310 '), 'trimmed before padding');
        self::assertSame('1300', PowerSchoolStaffExporter::psSchoolId('1300'), '4 digits untouched');
        self::assertSame('', PowerSchoolStaffExporter::psSchoolId(''), 'no school stays blank');
        self::assertSame('N/A', PowerSchoolStaffExporter::psSchoolId('N/A'), 'non-numeric passes through');
    }

    public function testWriteFileAndUpload(): void
    {
        $res = $this->export();
        $dir = sys_get_temp_dir() . '/psx_export_' . uniqid();

        $file = PowerSchoolStaffExporter::writeFile(
            PowerSchoolStaffExporter::render(PowerSchoolStaffExporter::DEMOGRAPHIC_HEADERS, $res['demographics']),
            $dir, PowerSchoolStaffExporter::DEMOGRAPHICS_FILE);
        self::assertFileExists($file['path']);
        self::assertStringEndsWith('/ps_staff_demographics.txt', $file['path'], 'fixed file name');

        $sftp = new InMemorySftpClient();
        $remote = PowerSchoolStaffExporter::uploadFile($sftp, $file['path'], '/exports/powerschool');
        self::assertSame('/exports/powerschool/ps_staff_demographics.txt', $remote);
        self::assertSame(file_get_contents($file['path']), $sftp->uploaded($remote));

        unlink($file['path']);
        rmdir($dir);
    }
}
