<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\PowerSchoolStaffExporter;
use App\Import\Csv;
use App\Sync\Sftp\InMemorySftpClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PowerSchoolStaffExporter — the CSV that creates NEW staff in PowerSchool
 * (bin/export_powerschool.php). Candidate = active/pending person with an
 * ALSDE ID and no active powerschool source id; columns are the data-dictionary
 * table.field names with S_USR_X.State_StaffNumber carrying the ALSDE ID.
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

        $db->exec("INSERT INTO school (school_id, name, ps_school_id) VALUES (1, 'Central High', '310')");

        // 1: the export candidate — ALSDE ID set, not in PowerSchool.
        $db->exec("INSERT INTO person VALUES (1, 'faculty', 'pending', 'Avery', 'Q', 'Baker',
            '1990-04-07', 'Female', 'B', 'AL-100001', 'E1001', 1, '2026-08-01',
            'abaker@example.org', 'abaker')");
        $db->exec("INSERT INTO assignment (person_id, title, is_primary) VALUES (1, 'Teacher', 1)");

        // 2: already in PowerSchool -> excluded.
        $db->exec("INSERT INTO person VALUES (2, 'faculty', 'active', 'Casey', NULL, 'Adams',
            '1985-01-02', 'Male', 'C', 'AL-100002', 'E1002', 1, '2020-08-01',
            'cadams@example.org', 'cadams')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (2, 'powerschool', '5555')");

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

    public function testWriteFileAndUpload(): void
    {
        $exporter = new PowerSchoolStaffExporter($this->db());
        $dir = sys_get_temp_dir() . '/psx_export_' . uniqid();

        $file = PowerSchoolStaffExporter::writeFile($exporter->candidates(), $dir, 'ps_new_staff_test.csv');
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
