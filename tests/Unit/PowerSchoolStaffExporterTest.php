<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Export\PowerSchoolStaffExporter;
use App\Sync\Sftp\InMemorySftpClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PowerSchoolStaffExporter — the single tab-delimited AutoComm file behind
 * bin/export_powerschool.php (Teachers view is the only import path in this
 * PS build). Exports ONLY new users (no active powerschool source id, ALSDE
 * ID required) and changed users (name or district email differs from the
 * latest import snapshot), one row per exported person per school, sorted by
 * SCHOOLID with prefix-free Teachers-view column names. Every held-back or
 * rejected row, truncation, and unmapped person type lands in the exceptions
 * list.
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
            email TEXT, username TEXT)');
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
            'AL-100001', 'E1001', 1, 'abaker@example.org', 'abaker')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (1, 1, 'Teacher', 1)");

        // 2: in PowerSchool, snapshot matches golden record -> NOT exported.
        $db->exec("INSERT INTO person VALUES (2, 'staff', 'active', 'Casey', NULL, 'Adams',
            'AL-100002', 'E1002', 2, 'cadams@example.org', 'cadams')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (2, 2, 'Bookkeeper', 1)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (2, 'powerschool', '5555')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 2, 'casey', 'ADAMS',
                           '{\"fields\":{\"hr_email\":\"CADAMS@example.org\"}}')");

        // 3: terminated -> excluded entirely.
        $db->exec("INSERT INTO person VALUES (3, 'staff', 'terminated', 'Drew', NULL, 'Cole',
            'AL-100003', 'E1003', 1, NULL, NULL)");

        // 4: NEW but no ALSDE ID -> held back (exception), not exported.
        $db->exec("INSERT INTO person VALUES (4, 'staff', 'active', 'Em', NULL, 'Dane',
            '', 'E1004', 1, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (4, 1, 'Aide', 1)");

        // 5: in PowerSchool once but the crosswalk row was deactivated -> NEW
        //    again. Home school has no ps_school_id -> rejected (exception).
        $db->exec("INSERT INTO person VALUES (5, 'staff', 'active', 'Blair', NULL, 'Ellis',
            'AL-100005', 'E1005', 3, 'bellis@example.org', 'bellis')");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (5, 3, 'Clerk', 1)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active)
                   VALUES (5, 'powerschool', '6666', 0)");

        // 6: CHANGED — married name + renamed username/email in IDM; the newest
        //    of two snapshots still has the old values. Current at school 2,
        //    ended assignment at school 1 (transfer).
        $db->exec("INSERT INTO person VALUES (6, 'faculty', 'active', 'Morgan', 'L', 'Foster-Hill',
            'AL-100006', 'E1006', 2, 'mfosterhill@example.org', 'mfosterhill')");
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
        //    rows -> falls back to primary school, STAFFSTATUS 4.
        $db->exec("INSERT INTO person VALUES (7, 'sub', 'active', 'Jesse', NULL, 'Irwin',
            NULL, 'E1007', 2, 'jirwin2@example.org', 'jirwin2')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (7, 'powerschool', '8888')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last, raw_json)
                   VALUES ('powerschool', 7, 'Jesse', 'Irwin',
                           '{\"fields\":{\"hr_email\":\"jirwin@example.org\"}}')");

        // 8: in PowerSchool, email differs but the snapshot predates email
        //    capture (no fields.hr_email) -> unknown, NOT exported.
        $db->exec("INSERT INTO person VALUES (8, 'staff', 'active', 'Kai', NULL, 'Jones',
            NULL, 'E1008', 1, 'kjones@example.org', 'kjones')");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key) VALUES (8, 'powerschool', '9999')");
        $db->exec("INSERT INTO staging_record (system, matched_person_id, n_first, n_last)
                   VALUES ('powerschool', 8, 'Kai', 'Jones')");

        // 9: in PowerSchool, never snapshotted -> skipped (nothing to compare).
        $db->exec("INSERT INTO person VALUES (9, 'staff', 'active', 'Sam', NULL, 'Hale',
            NULL, 'E1009', 1, NULL, NULL)");
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

        $exported = array_values(array_unique(array_column($res['rows'], 'TEACHERNUMBER')));
        sort($exported);
        self::assertSame(['E1001', 'E1006', 'E1007'], $exported,
            'new user Baker + changed Foster-Hill (name) + changed Irwin (email/username); '
            . 'unchanged, unknown-email, never-snapshotted, terminated, and held-back people excluded');
        self::assertSame(2, $res['summary']['new'], 'Baker + Ellis (reactivated crosswalk = new again)');
        self::assertSame(2, $res['summary']['changed']);
    }

    public function testNewUserWithoutAlsdeIdIsHeldBackAndLogged(): void
    {
        $res = $this->export();

        self::assertNotContains('E1004', array_column($res['rows'], 'TEACHERNUMBER'));
        self::assertStringContainsString(
            'Dane, Em (person 4) — new user without an ALSDE ID; held back',
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

    public function testRowsMapTeachersViewColumnsSortedBySchoolId(): void
    {
        $rows = $this->export()['rows'];

        self::assertSame(PowerSchoolStaffExporter::HEADERS, array_keys($rows[0]));
        self::assertSame(
            [
                ['E1006', '0220', '1', '1'], // Foster-Hill current at 0220
                ['E1007', '0220', '1', '4'], // Irwin (sub) falls back to primary school
                ['E1001', '0310', '1', '1'], // Baker new at 0310
                ['E1006', '0310', '2', '1'], // Foster-Hill's ended assignment -> No longer here
            ],
            array_map(static fn(array $r): array => [
                $r['TEACHERNUMBER'], $r['SCHOOLID'], $r['STATUS'], $r['STAFFSTATUS'],
            ], $rows),
            'sorted by SCHOOLID; ended assignment -> STATUS 2; sub -> STAFFSTATUS 4');
    }

    public function testUsersFieldsRepeatOnEveryRowOfAPerson(): void
    {
        $rows = $this->export()['rows'];
        $foster = array_values(array_filter($rows,
            static fn(array $r): bool => $r['TEACHERNUMBER'] === 'E1006'));

        self::assertCount(2, $foster, 'one row per school');
        foreach ($foster as $row) {
            self::assertSame('Foster-Hill', $row['LAST_NAME']);
            self::assertSame('Morgan', $row['FIRST_NAME']);
            self::assertSame('L', $row['MIDDLE_NAME']);
            self::assertSame('mfosterhill@example.org', $row['EMAIL_ADDR'], 'renamed email exported');
            self::assertSame('mfosterhill', $row['TEACHERLOGINID'], 'renamed username exported');
            self::assertSame('E1006', $row['SIF_STATEPRID'], 'district practice: StatePrid = employee id');
            self::assertSame('Teacher', $row['TITLE'], 'primary assignment title');
            self::assertSame('0220', $row['HOMESCHOOLID'], 'home school on both rows');
        }
    }

    public function testSchoolNumbersArePaddedToFourDigits(): void
    {
        $rows = $this->export()['rows'];
        $baker = array_values(array_filter($rows,
            static fn(array $r): bool => $r['TEACHERNUMBER'] === 'E1001'))[0];

        self::assertSame('0310', $baker['HOMESCHOOLID'], '3-digit IDM code padded for PS');
        self::assertSame('0310', $baker['SCHOOLID']);
    }

    public function testNoRaceEthnicityPasswordOrAddressColumns(): void
    {
        $headers = implode(' ', PowerSchoolStaffExporter::HEADERS);

        foreach (['ETHNICITY', 'FEDETHNICITY', 'FEDRACEDECLINE', 'PASSWORD', 'TEACHERLOGINPW',
                  'SSN', 'STREET', 'CITY', 'ZIP', 'HOME_PHONE'] as $banned) {
            self::assertStringNotContainsStringIgnoringCase($banned, $headers,
                "no {$banned} column — out of scope or no IDM source");
        }
    }

    public function testUnresolvableSchoolRejectsThePersonAndLogs(): void
    {
        $res = $this->export();

        self::assertNotContains('E1005', array_column($res['rows'], 'TEACHERNUMBER'));
        self::assertStringContainsString(
            'Ellis, Blair (person 5) — home school cannot be resolved',
            implode("\n", $res['exceptions']));
    }

    public function testMissingTeacherNumberIsRejected(): void
    {
        $db = $this->db();
        $db->exec("UPDATE person SET employee_id = '' WHERE person_id = 1");

        $res = $this->export($db);
        self::assertNotContains('Baker', array_column($res['rows'], 'LAST_NAME'));
        self::assertStringContainsString('Baker, Avery (person 1) — missing TEACHERNUMBER',
            implode("\n", $res['exceptions']));
    }

    public function testDuplicateTeacherNumberIsRejected(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (10, 'staff', 'active', 'Twin', NULL, 'Zed',
            'AL-100010', 'E1001', 2, NULL, NULL)"); // new user, same employee id as Baker

        $res = $this->export($db);
        $e1001 = array_filter($res['rows'], static fn(array $r): bool => $r['TEACHERNUMBER'] === 'E1001');
        self::assertSame(['0310'], array_column($e1001, 'SCHOOLID'), "only Baker's rows survive");
        self::assertStringContainsString("duplicate TEACHERNUMBER 'E1001'",
            implode("\n", $res['exceptions']));
    }

    public function testUnmappedPersonTypeDefaultsToStaffAndLogsOnce(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person VALUES (10, 'contractor', 'active', 'Rene', NULL, 'Gray',
            'AL-100010', 'E1010', 1, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (10, 1, 'Contractor', 1)");
        $db->exec("INSERT INTO person VALUES (11, 'contractor', 'active', 'Sam', NULL, 'Ives',
            'AL-100011', 'E1011', 1, NULL, NULL)");
        $db->exec("INSERT INTO assignment (person_id, school_id, title, is_primary) VALUES (11, 1, 'Contractor', 1)");

        $res = $this->export($db);
        $byKey = array_column($res['rows'], 'STAFFSTATUS', 'TEACHERNUMBER');
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
            'AL-100010', '" . str_repeat('9', 21) . "', 1, NULL, NULL)");

        $res = $this->export($db);
        $text = implode("\n", $res['exceptions']);

        $baker = array_values(array_filter($res['rows'],
            static fn(array $r): bool => $r['TEACHERNUMBER'] === 'E1001'))[0];
        self::assertSame(40, mb_strlen($baker['TITLE']), 'TITLE truncated to 40');
        self::assertStringContainsString('TITLE truncated to 40 chars', $text);

        self::assertNotContains(str_repeat('9', 20), array_column($res['rows'], 'TEACHERNUMBER'),
            'over-long match key is rejected, never truncated');
        self::assertStringContainsString('match key is never truncated', $text);
    }

    public function testTabsAndNewlinesAreStrippedFromValues(): void
    {
        $db = $this->db();
        $db->exec("UPDATE person SET last_name = 'O''Neal' || char(9) || 'Jr' WHERE person_id = 1");

        $row = array_values(array_filter($this->export($db)['rows'],
            static fn(array $r): bool => $r['TEACHERNUMBER'] === 'E1001'))[0];
        self::assertSame("O'Neal Jr", $row['LAST_NAME'], 'tab replaced with a space');
    }

    public function testRenderIsTabDelimitedWithHeaderAndTrailingNewline(): void
    {
        $res = $this->export();
        $txt = PowerSchoolStaffExporter::render(PowerSchoolStaffExporter::HEADERS, $res['rows']);

        $lines = explode("\r\n", rtrim($txt, "\r\n"));
        self::assertSame(implode("\t", PowerSchoolStaffExporter::HEADERS), $lines[0],
            'prefix-free Teachers-view headers');
        self::assertCount(1 + count($res['rows']), $lines, 'no blank rows');
        self::assertStringEndsWith("\r\n", $txt, 'one trailing newline');
        self::assertStringNotContainsString('"', $txt, 'no quoting');
    }

    public function testSampleIsHeaderPlusAtMostThreeRows(): void
    {
        $res = $this->export();
        $sample = PowerSchoolStaffExporter::sample(PowerSchoolStaffExporter::HEADERS, $res['rows']);
        self::assertCount(4, explode("\r\n", rtrim($sample, "\r\n")), 'header + 3 rows');
    }

    public function testSummaryCountsRowsExceptionsAndDistinctSchools(): void
    {
        $s = $this->export()['summary'];
        self::assertSame(2, $s['new']);
        self::assertSame(2, $s['changed']);
        self::assertSame(4, $s['rows']);
        self::assertSame(2, $s['schools'], '0220 and 0310');
        self::assertSame(2, $s['exceptions'], 'Dane held back + Ellis unresolvable school');
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
            PowerSchoolStaffExporter::render(PowerSchoolStaffExporter::HEADERS, $res['rows']),
            $dir, PowerSchoolStaffExporter::EXPORT_FILE);
        self::assertFileExists($file['path']);
        self::assertStringEndsWith('/ps_staff_teachers.txt', $file['path'], 'fixed file name');

        $sftp = new InMemorySftpClient();
        $remote = PowerSchoolStaffExporter::uploadFile($sftp, $file['path'], '/exports/powerschool');
        self::assertSame('/exports/powerschool/ps_staff_teachers.txt', $remote);
        self::assertSame(file_get_contents($file['path']), $sftp->uploaded($remote));

        unlink($file['path']);
        rmdir($dir);
    }
}
