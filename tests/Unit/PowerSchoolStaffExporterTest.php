<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\PowerSchoolStaffExporter;
use App\Import\Csv;
use App\Sync\Sftp\InMemorySftpClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PowerSchoolStaffExporter — the CSVs behind bin/export_powerschool.php.
 * New staff: active/pending person with an ALSDE ID and no active powerschool
 * source id; columns are the data-dictionary table.field names with
 * S_USR_X.State_StaffNumber carrying the ALSDE ID. Name updates: person IS in
 * PowerSchool but the golden name OR district email differs from the latest
 * import snapshot; keyed by Users.TeacherNumber and carrying the current
 * name, email, and username.
 */
final class PowerSchoolStaffExporterTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, person_type TEXT, status TEXT,
            first_name TEXT, middle_name TEXT, last_name TEXT,
            dob TEXT, gender TEXT, ethnicity_code TEXT, alsde_id TEXT,
            employee_id TEXT, primary_school_id INTEGER, hire_date TEXT,
            email TEXT, username TEXT)');
        $db->exec('CREATE TABLE school (school_id INTEGER PRIMARY KEY, name TEXT, ps_school_id TEXT)');
        $db->exec('CREATE TABLE assignment (
            id INTEGER PRIMARY KEY, person_id INTEGER, title TEXT, is_primary INTEGER DEFAULT 0)');
        $db->exec('CREATE TABLE person_source_id (
            id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT, source_key TEXT, is_active INTEGER DEFAULT 1)');
        $db->exec('CREATE TABLE staging_record (
            id INTEGER PRIMARY KEY, system TEXT, matched_person_id INTEGER,
            n_first TEXT, n_last TEXT, raw_json TEXT)');

        $db->exec("INSERT INTO school (school_id, name, ps_school_id) VALUES (1, 'Central High', '310')");

        // 1: the export candidate — ALSDE ID set, not in PowerSchool.
        $db->exec("INSERT INTO person VALUES (1, 'faculty', 'pending', 'Avery', 'Q', 'Baker',
            '1990-04-07', 'Female', 'B', 'AL-100001', 'E1001', 1, '2026-08-01',
            'abaker@example.org', 'abaker')");
        $db->exec("INSERT INTO assignment (person_id, title, is_primary) VALUES (1, 'Teacher', 1)");

        // 2: already in PowerSchool, snapshot name matches -> excluded everywhere.
        $db->exec("INSERT INTO person VALUES (2, 'faculty', 'active', 'Casey', NULL, 'Adams',
            '1985-01-02', 'Male', 'C', 'AL-100002', 'E1002', 1, '2020-08-01',
            'cadams@example.org', 'cadams')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (2, 'powerschool', '5555')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 2, 'casey', 'ADAMS',
                           '{\"fields\":{\"hr_email\":\"CADAMS@example.org\"}}')");

        // 3: no ALSDE ID -> held back (missingAlsdeId), never exported.
        $db->exec("INSERT INTO person VALUES (3, 'staff', 'pending', 'Drew', NULL, 'Cole',
            NULL, NULL, NULL, '', 'E1003', NULL, NULL, NULL, NULL)");

        // 4: terminated -> excluded entirely.
        $db->exec("INSERT INTO person VALUES (4, 'staff', 'terminated', 'Em', NULL, 'Dane',
            NULL, NULL, NULL, 'AL-100004', 'E1004', NULL, NULL, NULL, NULL)");

        // 5: in PowerSchool once but the crosswalk row was deactivated -> candidate again.
        $db->exec("INSERT INTO person VALUES (5, 'staff', 'active', 'Blair', NULL, 'Ellis',
            NULL, 'M', NULL, 'AL-100005', 'E1005', NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (5, 'powerschool', '6666', 0)");

        // 6: in PowerSchool, married name in IDM differs from the PS snapshot
        //    (two snapshots — only the NEWEST counts) -> name update. The rename
        //    also minted a new username/email; PS still has the old email.
        $db->exec("INSERT INTO person VALUES (6, 'faculty', 'active', 'Morgan', 'L', 'Foster-Hill',
            NULL, 'F', NULL, 'AL-100006', 'E1006', 1, NULL, 'mfosterhill@example.org', 'mfosterhill')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (6, 'powerschool', '7777')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last)
                   VALUES ('powerschool', 6, 'Morgan', 'Foster-Hill')"); // older, already renamed once
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 6, 'Morgan', 'Foster',
                           '{\"fields\":{\"hr_email\":\"mfoster@example.org\"}}')"); // newest: old name + email

        // 7: name changed but no employee id -> held back from the update file.
        $db->exec("INSERT INTO person VALUES (7, 'staff', 'active', 'Riley', NULL, 'Grant',
            NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (7, 'powerschool', '8888')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last)
                   VALUES ('powerschool', 7, 'Riley', 'Gray')");

        // 8: in PowerSchool, no snapshot ever matched -> skipped (nothing to compare).
        $db->exec("INSERT INTO person VALUES (8, 'staff', 'active', 'Sam', NULL, 'Hale',
            NULL, NULL, NULL, NULL, 'E1008', NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (8, 'powerschool', '9999')");

        // 9: name unchanged but the district email moved on (e.g. username
        //    conflict resolved after the PS snapshot) -> update on email alone.
        $db->exec("INSERT INTO person VALUES (9, 'staff', 'active', 'Jesse', NULL, 'Irwin',
            NULL, NULL, NULL, NULL, 'E1009', NULL, NULL, 'jirwin2@example.org', 'jirwin2')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (9, 'powerschool', '1111')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 9, 'Jesse', 'Irwin',
                           '{\"fields\":{\"hr_email\":\"jirwin@example.org\"}}')");

        // 10: email differs from golden but the snapshot predates email capture
        //     (no fields.hr_email) -> unknown, NOT an update.
        $db->exec("INSERT INTO person VALUES (10, 'staff', 'active', 'Kai', NULL, 'Jones',
            NULL, NULL, NULL, NULL, 'E1010', NULL, NULL, 'kjones@example.org', 'kjones')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (10, 'powerschool', '2222')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last)
                   VALUES ('powerschool', 10, 'Kai', 'Jones')");

        return $db;
    }

    public function testCandidatesSelectsOnlyNewPeopleWithAlsdeId(): void
    {
        $rows = (new PowerSchoolStaffExporter($this->db()))->candidates();

        self::assertSame([1, 5], array_map(static fn($r) => (int) $r['person_id'], $rows),
            'ALSDE ID + no active powerschool source id, ordered by name');
        self::assertSame('Teacher', $rows[0]['title'], 'primary assignment title joined in');
        self::assertSame('310', $rows[0]['ps_school_id'], 'PowerSchool school id joined in');
    }

    public function testMissingAlsdeIdListsHeldBackPeople(): void
    {
        $rows = (new PowerSchoolStaffExporter($this->db()))->missingAlsdeId();

        self::assertSame([3], array_map(static fn($r) => (int) $r['person_id'], $rows),
            'only the new hire without an ALSDE ID; PS-matched and terminated people excluded');
    }

    public function testRowMapsToDataDictionaryFields(): void
    {
        $rows = (new PowerSchoolStaffExporter($this->db()))->candidates();
        $row = PowerSchoolStaffExporter::row($rows[0]);

        self::assertSame(PowerSchoolStaffExporter::HEADERS, array_keys($row), 'column order matches HEADERS');
        self::assertSame('Baker', $row['Users.Last_Name']);
        self::assertSame('AL-100001', $row['S_USR_X.State_StaffNumber'], 'ALSDE ID -> S_USR_X.State_StaffNumber');
        self::assertSame('E1001', $row['Users.TeacherNumber']);
        self::assertSame('E1001', $row['Users.SIF_StatePrid'], 'district practice: StatePrId = employee id');
        self::assertSame('F', $row['UsersCoreFields.gender'], 'Female -> F');
        self::assertSame('04/07/1990', $row['UsersCoreFields.dob'], 'Y-m-d -> MM/DD/YYYY');
        self::assertSame('08/01/2026', $row['S_USR_X.HireDate']);
        self::assertSame('310', $row['Users.HomeSchoolId']);
        self::assertSame('310', $row['SchoolStaff.SchoolID']);
        self::assertSame('1', $row['SchoolStaff.Status'], '1 = Current');
        self::assertSame('1', $row['SchoolStaff.StaffStatus'], 'faculty -> 1 = Teacher');
        self::assertSame('B', $row['TeacherRace.RaceCd']);
        self::assertSame('abaker', $row['Users.TeacherLoginID']);
    }

    public function testNonFacultyDefaultsToStaffStatusStaff(): void
    {
        $row = PowerSchoolStaffExporter::row(['person_type' => 'sub']);
        self::assertSame('2', $row['SchoolStaff.StaffStatus'], 'anything but faculty -> 2 = Staff');
    }

    public function testCsvRoundTripsThroughTheCsvReader(): void
    {
        $exporter = new PowerSchoolStaffExporter($this->db());
        $csv = PowerSchoolStaffExporter::csv($exporter->candidates());

        $tmp = tempnam(sys_get_temp_dir(), 'psx');
        file_put_contents($tmp, $csv);
        $rows = Csv::read($tmp);
        unlink($tmp);

        self::assertCount(2, $rows);
        self::assertSame('AL-100001', $rows[0]['S_USR_X.State_StaffNumber']);
        self::assertSame('Ellis', $rows[1]['Users.Last_Name']);
        self::assertStringEndsWith("\r\n", $csv, 'CRLF line endings');
    }

    public function testCsvQuotesValuesContainingDelimiters(): void
    {
        $csv = PowerSchoolStaffExporter::csv([[
            'person_type' => 'staff',
            'last_name'   => 'O"Neal, Jr',
            'first_name'  => 'Pat',
        ]]);
        [$header, $line] = explode("\r\n", $csv);
        self::assertStringContainsString('"O""Neal, Jr"', $line);
        self::assertSame(count(PowerSchoolStaffExporter::HEADERS), substr_count($header, ',') + 1);
    }

    public function testNameUpdatesComparesGoldenNameToLatestSnapshot(): void
    {
        $res = (new PowerSchoolStaffExporter($this->db()))->nameUpdates();

        self::assertSame([6, 9], array_map(static fn($r) => (int) $r['person_id'], $res['updates']),
            'people whose golden name or email differs from the NEWEST snapshot; '
            . 'case-insensitive match excludes person 2, no-snapshot person 8 skipped, '
            . 'email-unknown snapshot (person 10) never triggers on email');
        self::assertSame('Foster', $res['updates'][0]['ps_last'], 'old PS name carried for reporting');
        self::assertSame('mfoster@example.org', $res['updates'][0]['ps_email'],
            'old PS email carried for reporting');
        self::assertSame([7], array_map(static fn($r) => (int) $r['person_id'], $res['held']),
            'changed name without an employee id is held back, not dropped');
    }

    public function testEmailChangeAloneTriggersAnUpdate(): void
    {
        $res = (new PowerSchoolStaffExporter($this->db()))->nameUpdates();
        $byId = array_column($res['updates'], null, 'person_id');

        self::assertArrayHasKey(9, $byId, 'unchanged name but changed email still exports');
        self::assertSame('jirwin@example.org', $byId[9]['ps_email']);
        self::assertSame('jirwin2@example.org', $byId[9]['email']);
    }

    public function testUpdateRowAndCsvKeyedByTeacherNumber(): void
    {
        $exporter = new PowerSchoolStaffExporter($this->db());
        $updates = $exporter->nameUpdates()['updates'];

        $row = PowerSchoolStaffExporter::updateRow($updates[0]);
        self::assertSame(PowerSchoolStaffExporter::UPDATE_HEADERS, array_keys($row));
        self::assertSame('E1006', $row['Users.TeacherNumber'], 'match key = employee id');
        self::assertSame('Foster-Hill', $row['Users.Last_Name']);
        self::assertSame('L', $row['Users.Middle_Name'], 'full current name exported');
        self::assertSame('mfosterhill@example.org', $row['Users.Email_Addr'], 'renamed email exported');
        self::assertSame('mfosterhill', $row['Users.TeacherLoginID'], 'renamed username exported');

        $tmp = tempnam(sys_get_temp_dir(), 'psu');
        file_put_contents($tmp, PowerSchoolStaffExporter::updatesCsv($updates));
        $rows = Csv::read($tmp);
        unlink($tmp);
        self::assertCount(2, $rows);
        self::assertSame('Foster-Hill', $rows[0]['Users.Last_Name']);
        self::assertSame('jirwin2', $rows[1]['Users.TeacherLoginID']);
    }

    public function testWriteFileAndUpload(): void
    {
        $exporter = new PowerSchoolStaffExporter($this->db());
        $dir = sys_get_temp_dir() . '/psx_export_' . uniqid();

        $file = PowerSchoolStaffExporter::writeFile(
            PowerSchoolStaffExporter::csv($exporter->candidates()), $dir, 'ps_new_staff_test.csv');
        self::assertFileExists($file['path']);
        self::assertGreaterThan(0, $file['bytes']);

        $sftp = new InMemorySftpClient();
        $remote = PowerSchoolStaffExporter::uploadFile($sftp, $file['path'], '/exports/powerschool');
        self::assertSame('/exports/powerschool/ps_new_staff_test.csv', $remote);
        self::assertSame(file_get_contents($file['path']), $sftp->uploaded($remote));

        unlink($file['path']);
        rmdir($dir);
    }
}
