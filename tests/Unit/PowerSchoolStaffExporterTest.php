<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\PowerSchoolStaffExporter;
use App\Sync\Sftp\InMemorySftpClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PowerSchoolStaffExporter — the tab-delimited files behind
 * bin/export_powerschool.php. Exports ONLY new users (no active powerschool
 * source id, ALSDE ID set) and changed users (name or district email differs
 * from the latest import snapshot), not the full roster. Demographics: one
 * row per exported person (DIM -> USERSCOREFIELDS, matched on
 * USERS.TeacherNumber). Assignments: one row per exported person per school
 * (AutoComm -> Teachers, prefix-free headers, sorted by SchoolID). Every
 * held-back or rejected row, truncation, and unmapped person type lands in
 * the exceptions list.
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
        $db->exec('CREATE TABLE person_source_id (
            id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT, source_key TEXT, is_active INTEGER DEFAULT 1)');
        $db->exec('CREATE TABLE staging_record (
            id INTEGER PRIMARY KEY, system TEXT, matched_person_id INTEGER,
            n_first TEXT, n_last TEXT, raw_json TEXT)');

        $db->exec("INSERT INTO school VALUES (1, 'Central High', '310')");   // 3 digits -> padded
        $db->exec("INSERT INTO school VALUES (2, 'Eastwood Middle', '0220')");
        $db->exec("INSERT INTO school VALUES (3, 'Annex', NULL)");           // no School_Number

        // 1: NEW — ALSDE ID set, no powerschool source id -> exported.
        $db->exec("INSERT INTO person VALUES (1, 'faculty', 'pending', 'Avery', 'Q', 'Baker',
            'AL-100001', 'E1001', 1, '2026-08-01', 'abaker@example.org', 'abaker')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 1, 'Teacher', 1)");

        // 2: in PowerSchool, snapshot matches golden record -> NOT exported.
        $db->exec("INSERT INTO person VALUES (2, 'staff', 'active', 'Casey', NULL, 'Adams',
            'AL-100002', 'E1002', 2, '2020-08-01', 'cadams@example.org', 'cadams')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (2, 2, 'Bookkeeper', 1)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (2, 'powerschool', '5555')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 2, 'casey', 'ADAMS',
                           '{\"fields\":{\"hr_email\":\"CADAMS@example.org\"}}')");

        // 3: terminated -> excluded entirely.
        $db->exec("INSERT INTO person VALUES (3, 'staff', 'terminated', 'Drew', NULL, 'Cole',
            'AL-100003', 'E1003', 1, NULL, NULL, NULL)");

        // 4: NEW but no ALSDE ID -> held back (exception), not exported.
        $db->exec("INSERT INTO person VALUES (4, 'staff', 'active', 'Em', NULL, 'Dane',
            '', 'E1004', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (4, 1, 'Aide', 1)");

        // 5: in PowerSchool once but the crosswalk row was deactivated -> NEW again.
        //    Home school has no ps_school_id -> demographics + assignment rejected.
        $db->exec("INSERT INTO person VALUES (5, 'staff', 'active', 'Blair', NULL, 'Ellis',
            'AL-100005', 'E1005', 3, NULL, 'bellis@example.org', 'bellis')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (5, 3, 'Clerk', 1)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active)
                   VALUES (5, 'powerschool', '6666', 0)");

        // 6: CHANGED — married name + renamed username/email in IDM; the newest
        //    of two snapshots still has the old values. Current at school 2,
        //    ended assignment at school 1 (transfer).
        $db->exec("INSERT INTO person VALUES (6, 'faculty', 'active', 'Morgan', 'L', 'Foster-Hill',
            'AL-100006', 'E1006', 2, '2018-08-01', 'mfosterhill@example.org', 'mfosterhill')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (6, 2, 'Teacher', 1)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary, end_date)
                   VALUES (6, 1, 'Teacher', 0, '2026-05-31')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (6, 'powerschool', '7777')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last)
                   VALUES ('powerschool', 6, 'Morgan', 'Foster-Hill')"); // older, already renamed once
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 6, 'Morgan', 'Foster',
                           '{\"fields\":{\"hr_email\":\"mfoster@example.org\"}}')"); // newest: old name + email

        // 7: CHANGED — name unchanged but the district email/username moved on
        //    (rename detected via the email comparison). Sub with no assignment
        //    rows -> falls back to primary school, StaffStatus 4.
        $db->exec("INSERT INTO person VALUES (7, 'sub', 'active', 'Jesse', NULL, 'Irwin',
            NULL, 'E1007', 2, NULL, 'jirwin2@example.org', 'jirwin2')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (7, 'powerschool', '8888')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 7, 'Jesse', 'Irwin',
                           '{\"fields\":{\"hr_email\":\"jirwin@example.org\"}}')");

        // 8: in PowerSchool, email differs but the snapshot predates email
        //    capture (no fields.hr_email) -> unknown, NOT exported.
        $db->exec("INSERT INTO person VALUES (8, 'staff', 'active', 'Kai', NULL, 'Jones',
            NULL, 'E1008', 1, NULL, 'kjones@example.org', 'kjones')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (8, 'powerschool', '9999')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last)
                   VALUES ('powerschool', 8, 'Kai', 'Jones')");

        // 9: in PowerSchool, never snapshotted -> skipped (nothing to compare).
        $db->exec("INSERT INTO person VALUES (9, 'staff', 'active', 'Sam', NULL, 'Hale',
            NULL, 'E1009', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (9, 'powerschool', '1010')");

        return $db;
    }

    private function export(?PDO $db = null): array
    {
        return (new PowerSchoolStaffExporter($db ?? $this->db(), self::TODAY))->export();
    }

    public function testOnlyNewAndChangedPeopleAreExported(): void
    {
        $res = $this->export();

        self::assertSame(['E1001', 'E1006', 'E1007'],
            array_column($res['demographics'], 'USERS.TeacherNumber'),
            'new user Baker + changed Foster-Hill (name) + changed Irwin (email/username); '
            . 'unchanged, unknown-email, never-snapshotted, terminated, and held-back people excluded');
        self::assertSame(2, $res['summary']['new'], 'Baker + Ellis (reactivated crosswalk = new again)');
        self::assertSame(2, $res['summary']['changed']);
    }

    public function testNewUserWithoutAlsdeIdIsHeldBackAndLogged(): void
    {
        $res = $this->export();

        self::assertNotContains('E1004', array_column($res['demographics'], 'USERS.TeacherNumber'));
        self::assertNotContains('E1004', array_column($res['assignments'], 'TeacherNumber'),
            'held back from both files');
        self::assertStringContainsString('Dane, Em (person 4) — new user without an ALSDE ID; held back',
            implode("\n", $res['exceptions']));
    }

    public function testChangedSelectionReportsOldValues(): void
    {
        $res = $this->export();
        $text = implode("\n", $res['changed']);

        self::assertStringContainsString('Foster-Hill, Morgan (person 6) — was Foster, Morgan', $text);
        self::assertStringContainsString('email was mfoster@example.org', $text);
        self::assertStringContainsString('Irwin, Jesse (person 7)', $text);
        self::assertStringContainsString('email was jirwin@example.org', $text);
    }

    public function testDemographicRowMapsSpecColumns(): void
    {
        $rows = $this->export()['demographics'];
        $row = $rows[0]; // Baker

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
        self::assertSame('08/01/2026', $row['S_USR_X.hiredate'], 'Y-m-d -> MM/DD/YYYY');
        self::assertSame('AL-100001', $row['S_USR_X.state_staffnumber'], 'ALSDE ID');
    }

    public function testChangedUserRowCarriesTheRenamedEmailAndUsername(): void
    {
        $rows = $this->export()['demographics'];
        $foster = $rows[1];

        self::assertSame('E1006', $foster['USERS.TeacherNumber']);
        self::assertSame('Foster-Hill', $foster['USERS.Last_Name']);
        self::assertSame('mfosterhill@example.org', $foster['USERS.Email_Addr'], 'renamed email exported');
        self::assertSame('mfosterhill', $foster['USERS.TeacherLoginID'], 'renamed username exported');
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

    public function testAssignmentsCoverOnlyExportedPeopleSortedBySchoolId(): void
    {
        $rows = $this->export()['assignments'];

        self::assertSame([
            ['TeacherNumber' => 'E1006', 'SchoolID' => '0220', 'Status' => '1', 'StaffStatus' => '1'],
            ['TeacherNumber' => 'E1007', 'SchoolID' => '0220', 'Status' => '1', 'StaffStatus' => '4'],
            ['TeacherNumber' => 'E1001', 'SchoolID' => '0310', 'Status' => '1', 'StaffStatus' => '1'],
            ['TeacherNumber' => 'E1006', 'SchoolID' => '0310', 'Status' => '2', 'StaffStatus' => '1'],
        ], $rows, 'sorted by SchoolID; unchanged Adams excluded; ended assignment -> Status 2; '
            . 'sub with no assignment rows falls back to primary school with StaffStatus 4');
    }

    public function testUnresolvableSchoolRejectsTheRowAndLogs(): void
    {
        $res = $this->export();
        $text = implode("\n", $res['exceptions']);

        self::assertNotContains('E1005', array_column($res['demographics'], 'USERS.TeacherNumber'));
        self::assertStringContainsString('Ellis, Blair (person 5) — home school cannot be resolved', $text);
        self::assertStringContainsString('assignments: Ellis, Blair (person 5) — school cannot be resolved', $text);
    }

    public function testMissingTeacherNumberIsRejected(): void
    {
        $db = $this->db();
        $db->exec("UPDATE person SET employee_id = '' WHERE person_id = 1");

        $res = $this->export($db);
        self::assertNotContains('Baker', array_column($res['demographics'], 'USERS.Last_Name'));
        self::assertStringContainsString('Baker, Avery (person 1) — missing TeacherNumber',
            implode("\n", $res['exceptions']));
    }

    public function testDuplicateTeacherNumberIsRejected(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (10, 'staff', 'active', 'Twin', NULL, 'Zed',
            'AL-100010', 'E1001', 1, NULL, NULL, NULL)"); // new user, same employee id as Baker

        $res = $this->export($db);
        self::assertSame(1, count(array_keys(
            array_column($res['demographics'], 'USERS.TeacherNumber'), 'E1001', true)));
        self::assertStringContainsString("duplicate TeacherNumber 'E1001'",
            implode("\n", $res['exceptions']));
    }

    public function testUnmappedPersonTypeDefaultsToStaffAndLogsOnce(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (10, 'contractor', 'active', 'Rene', NULL, 'Gray',
            'AL-100010', 'E1010', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (10, 1, 'Contractor', 1)");
        $db->exec("INSERT INTO person VALUES (11, 'contractor', 'active', 'Sam', NULL, 'Ives',
            'AL-100011', 'E1011', 1, NULL, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (11, 1, 'Contractor', 1)");

        $res = $this->export($db);
        $byKey = array_column($res['assignments'], 'StaffStatus', 'TeacherNumber');
        self::assertSame('2', $byKey['E1010'], 'unmapped type defaults to 2 = Staff');

        $mentions = array_filter($res['exceptions'],
            static fn(string $e): bool => str_contains($e, "unmapped person type 'contractor'"));
        self::assertCount(1, $mentions, 'each unmapped type is logged once, not per person');
    }

    public function testOverLongValuesAreTruncatedAndLoggedButKeyRejects(): void
    {
        $db = $this->db();
        $db->exec("UPDATE assignment SET title = '" . trim(str_repeat('Coordinator ', 5)) . "'
                   WHERE person_id = 1"); // > 40 chars
        $db->exec("INSERT INTO person VALUES (10, 'staff', 'active', 'Key', NULL, 'Long',
            'AL-100010', '" . str_repeat('9', 21) . "', 1, NULL, NULL, NULL)");

        $res = $this->export($db);
        $text = implode("\n", $res['exceptions']);

        $baker = array_values(array_filter($res['demographics'],
            static fn(array $r): bool => $r['USERS.TeacherNumber'] === 'E1001'))[0];
        self::assertSame(40, mb_strlen($baker['USERS.Title']), 'Title truncated to 40');
        self::assertStringContainsString('USERS.Title truncated to 40 chars', $text);

        self::assertNotContains(str_repeat('9', 20),
            array_column($res['demographics'], 'USERS.TeacherNumber'),
            'over-long match key is rejected, never truncated');
        self::assertStringContainsString('match key is never truncated', $text);
    }

    public function testTabsAndNewlinesAreStrippedFromValues(): void
    {
        $db = $this->db();
        $db->exec("UPDATE person SET last_name = 'O''Neal' || char(9) || 'Jr' WHERE person_id = 1");

        $row = array_values(array_filter($this->export($db)['demographics'],
            static fn(array $r): bool => $r['USERS.TeacherNumber'] === 'E1001'))[0];
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
        self::assertSame(2, $s['new']);
        self::assertSame(2, $s['changed']);
        self::assertSame(3, $s['demographics']);
        self::assertSame(4, $s['assignments']);
        self::assertSame(2, $s['schools'], '0220 and 0310');
        self::assertSame(3, $s['exceptions'], 'Dane held back + Ellis rejected from both files');
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
