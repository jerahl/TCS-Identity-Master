<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PowerSchoolBundle;
use App\Import\PowerSchoolOdbcReader;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The ODBC reader replaces the SFTP CSV feed: it must hand PowerSchoolBundle the
 * exact same row shape Csv::read() did — header-keyed maps of trimmed strings,
 * NULLs as ''. These tests pin that bridge (shapeRows) and prove the reader's
 * output drives combine() to the same PsUser as the CSV path, using a fake PDO so
 * no Oracle/ODBC driver is needed.
 */
final class PowerSchoolOdbcReaderTest extends TestCase
{
    public function testShapeRowsTrimsCastsAndNullsToEmpty(): void
    {
        // Oracle/ODBC hands back typed NUMBERs, NULLs and padded text.
        $raw = [
            ['USERS.dcid' => 1011, 'USERS.First_Name' => '  Darby ', 'USERS.Middle_Name' => null, 'USERS.HomeSchoolId' => 160],
        ];
        $shaped = PowerSchoolOdbcReader::shapeRows($raw);

        self::assertSame('1011', $shaped[0]['USERS.dcid'], 'NUMBER cast to string');
        self::assertSame('Darby', $shaped[0]['USERS.First_Name'], 'trimmed');
        self::assertSame('', $shaped[0]['USERS.Middle_Name'], 'NULL becomes empty string');
        self::assertSame('160', $shaped[0]['USERS.HomeSchoolId']);
    }

    public function testReadDerivesSchoolStaffFromTeachers(): void
    {
        // Two active TEACHERS rows for one user, each carrying its own SchoolID.
        $teachers = [
            ['TEACHERS.dcid' => '1011', 'TEACHERS.Users_DCID' => '1011', 'TEACHERS.SchoolID' => '160'],
            ['TEACHERS.dcid' => '2901', 'TEACHERS.Users_DCID' => '1011', 'TEACHERS.SchoolID' => '75'],
        ];
        $staff = PowerSchoolOdbcReader::schoolStaffFromTeachers($teachers);

        self::assertSame([
            ['SCHOOLSTAFF.dcid' => '1011', 'SCHOOLSTAFF.Users_DCID' => '1011', 'SCHOOLSTAFF.SchoolID' => '160'],
            ['SCHOOLSTAFF.dcid' => '2901', 'SCHOOLSTAFF.Users_DCID' => '1011', 'SCHOOLSTAFF.SchoolID' => '75'],
        ], $staff);
    }

    public function testReadReturnsThreeDatasetsThatCombineCorrectly(): void
    {
        $reader = new PowerSchoolOdbcReader($this->fakeDb());
        $data = $reader->read();

        self::assertArrayHasKey('users', $data);
        self::assertArrayHasKey('teachers', $data);
        self::assertArrayHasKey('schoolstaff', $data);
        // SCHOOLSTAFF is projected from the two TEACHERS rows.
        self::assertCount(2, $data['schoolstaff']);

        // The rows must be consumable by combine() exactly like CSV rows. Here the
        // home school / TeacherNumber / Title come from TEACHERS (this schema's
        // layout); USERS only adds the middle name + HR extras.
        $people = PowerSchoolBundle::combine($data['users'], $data['teachers'], $data['schoolstaff']);
        self::assertCount(1, $people);
        $ps = $people[0];
        self::assertSame('1011', $ps->usersDcid);
        self::assertSame('Darby', $ps->firstName);
        self::assertSame('K', $ps->middleName, 'middle name from USERS');
        self::assertSame('12924', $ps->employeeId, 'TeacherNumber falls back to TEACHERS');
        self::assertSame('Teacher', $ps->title, 'Title falls back to TEACHERS');
        self::assertSame(['1011', '2901'], $ps->teacherIds, 'every TEACHERS.ID collected');
        self::assertCount(2, $ps->schools, 'one assignment per school');
        self::assertSame('160', $ps->primarySchoolCode(), 'HomeSchoolId (from TEACHERS) is primary');
    }

    /**
     * A PDO whose query() ignores the (Oracle) SQL and returns canned rows per
     * dataset — matched on the dotted alias in the SQL — with typed/NULL values
     * like a real driver. It backs them with a real SQLite statement (a literal
     * SELECT) so query() honours PDO's PDOStatement return contract.
     */
    private function fakeDb(): PDO
    {
        return new class ('sqlite::memory:') extends PDO {
            public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false
            {
                $rows = match (true) {
                    // USERS query adds only middle name + HR extras (joined by users_dcid).
                    str_contains($query, '"USERS.dcid"') => [
                        ['USERS.dcid' => 1011, 'USERS.First_Name' => 'Darby', 'USERS.Middle_Name' => 'K',
                         'USERS.Last_Name' => 'Allen', 'U_DEF_EXT_USERS.staff_classification' => 'Certified',
                         'S_USR_X.hiredate' => null, 'S_AL_USR_X.exit_date' => null],
                    ],
                    // TEACHERS carries school / home / title in this schema; two active rows.
                    str_contains($query, '"TEACHERS.ID"') => [
                        ['TEACHERS.ID' => 1011, 'TEACHERS.dcid' => 1011, 'TEACHERS.Users_DCID' => 1011,
                         'TEACHERS.TeacherNumber' => 12924, 'TEACHERS.First_Name' => 'Darby', 'TEACHERS.Last_Name' => 'Allen',
                         'TEACHERS.HomeSchoolId' => 160, 'TEACHERS.SchoolID' => 160, 'TEACHERS.Title' => 'Teacher'],
                        ['TEACHERS.ID' => 2901, 'TEACHERS.dcid' => 2901, 'TEACHERS.Users_DCID' => 1011,
                         'TEACHERS.TeacherNumber' => 12924, 'TEACHERS.First_Name' => 'Darby', 'TEACHERS.Last_Name' => 'Allen',
                         'TEACHERS.HomeSchoolId' => 160, 'TEACHERS.SchoolID' => 75, 'TEACHERS.Title' => 'Teacher'],
                    ],
                    default => [],
                };
                $stmt = parent::query($this->toSqlite($rows));
                $stmt->setFetchMode(PDO::FETCH_ASSOC);
                return $stmt;
            }

            /** Build a literal `SELECT ... UNION ALL SELECT ...` from canned rows. */
            private function toSqlite(array $rows): string
            {
                if ($rows === []) {
                    // Empty result: a SELECT that returns zero rows.
                    return 'SELECT 1 AS "x" WHERE 1 = 0';
                }
                $selects = [];
                foreach ($rows as $i => $row) {
                    $cols = [];
                    foreach ($row as $k => $v) {
                        $lit = $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->quote((string) $v));
                        // Alias only on the first SELECT; UNION ALL takes the rest positionally.
                        $cols[] = $i === 0 ? $lit . ' AS "' . $k . '"' : $lit;
                    }
                    $selects[] = 'SELECT ' . implode(', ', $cols);
                }
                return implode(' UNION ALL ', $selects);
            }
        };
    }

    public function testMergesExtendedDemographicGroupsByDcid(): void
    {
        putenv('PS_STAFF_DOB_COLUMN=dob'); // enable the optional DOB group
        try {
            $reader = new PowerSchoolOdbcReader($this->fakeDbWithDemographics(''));
            $data = $reader->read();
        } finally {
            putenv('PS_STAFF_DOB_COLUMN');
        }

        // Each best-effort group is merged onto the core USERS row by dcid.
        self::assertSame('darby@tcs.k12.al.us', $data['users'][0]['USERS.Email_Addr']);
        self::assertSame('F', $data['users'][0]['TEACHERS.SCHED_GENDER']);
        self::assertSame('1985-03-09', $data['users'][0]['S_AL_USR_X.dob']);
        self::assertSame('AL-552201', $data['users'][0]['S_USR_X.state_staffnumber']);

        $ps = PowerSchoolBundle::combine($data['users'], $data['teachers'], $data['schoolstaff'])[0];
        self::assertSame('darby@tcs.k12.al.us', $ps->email, 'email surfaced for verification');
        self::assertSame('F', $ps->gender, 'gender from TEACHERS.SCHED_GENDER');
        self::assertSame('1985-03-09', $ps->dob);
        self::assertSame('AL-552201', $ps->alsdeId, 'ALSID from S_USR_X.state_staffnumber');
    }

    public function testOneFailingGroupDoesNotSinkTheOthers(): void
    {
        // The contact group's SQL errors (a column the schema lacks); the gender,
        // ALSID and DOB groups are independent queries and must still come through,
        // and the core import must not throw.
        putenv('PS_STAFF_DOB_COLUMN=dob');
        try {
            $reader = new PowerSchoolOdbcReader($this->fakeDbWithDemographics('contact'));
            $data = $reader->read();
        } finally {
            putenv('PS_STAFF_DOB_COLUMN');
        }

        $ps = PowerSchoolBundle::combine($data['users'], $data['teachers'], $data['schoolstaff'])[0];
        self::assertSame('Darby', $ps->firstName, 'core fields intact');
        self::assertNull($ps->email, 'failed contact group -> email blank');
        self::assertSame('F', $ps->gender, 'gender group unaffected');
        self::assertSame('1985-03-09', $ps->dob, 'dob group unaffected');
        self::assertSame('AL-552201', $ps->alsdeId, 'staff_number group unaffected');
    }

    public function testDobGroupSkippedWhenColumnNotConfigured(): void
    {
        putenv('PS_STAFF_DOB_COLUMN'); // ensure unset
        $reader = new PowerSchoolOdbcReader($this->fakeDbWithDemographics(''));
        $data = $reader->read();

        // No DOB column configured -> no DOB query runs, no error, dob stays blank.
        self::assertArrayNotHasKey('S_AL_USR_X.dob', $data['users'][0]);
        $ps = PowerSchoolBundle::combine($data['users'], $data['teachers'], $data['schoolstaff'])[0];
        self::assertNull($ps->dob);
        self::assertSame('AL-552201', $ps->alsdeId, 'ALSID still pulled');
    }

    /**
     * Like fakeDb(), but answers each extended-demographics group query by its
     * distinctive alias. Pass a group label ('contact' | 'gender' | 'staff_number'
     * | 'dob') to make that group's query throw, exercising the best-effort merge.
     */
    private function fakeDbWithDemographics(string $throwGroup): PDO
    {
        return new class ('sqlite::memory:', $throwGroup) extends PDO {
            public function __construct(string $dsn, private string $throwGroup)
            {
                parent::__construct($dsn);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false
            {
                $group = match (true) {
                    str_contains($query, '"USERS.Email_Addr"')         => 'contact',
                    str_contains($query, '"TEACHERS.SCHED_GENDER"')    => 'gender',
                    str_contains($query, '"S_USR_X.state_staffnumber"') => 'staff_number',
                    str_contains($query, '"S_AL_USR_X.dob"')           => 'dob',
                    default                                            => '',
                };
                if ($group !== '' && $group === $this->throwGroup) {
                    throw new \PDOException("ORA-00904: invalid identifier ({$group})");
                }

                $rows = match (true) {
                    $group === 'contact' => [['USERS.dcid' => 1011, 'USERS.Email_Addr' => 'darby@tcs.k12.al.us']],
                    $group === 'gender' => [['USERS.dcid' => 1011, 'TEACHERS.SCHED_GENDER' => 'F']],
                    $group === 'staff_number' => [['USERS.dcid' => 1011, 'S_USR_X.state_staffnumber' => 'AL-552201']],
                    $group === 'dob' => [['USERS.dcid' => 1011, 'S_AL_USR_X.dob' => '1985-03-09']],
                    str_contains($query, '"USERS.dcid"') => [
                        ['USERS.dcid' => 1011, 'USERS.First_Name' => 'Darby', 'USERS.Middle_Name' => 'K', 'USERS.Last_Name' => 'Allen'],
                    ],
                    str_contains($query, '"TEACHERS.ID"') => [
                        ['TEACHERS.ID' => 1011, 'TEACHERS.Users_DCID' => 1011, 'TEACHERS.TeacherNumber' => 12924,
                         'TEACHERS.First_Name' => 'Darby', 'TEACHERS.Last_Name' => 'Allen',
                         'TEACHERS.HomeSchoolId' => 160, 'TEACHERS.SchoolID' => 160, 'TEACHERS.Title' => 'Teacher'],
                    ],
                    default => [],
                };

                if ($rows === []) {
                    $stmt = parent::query('SELECT 1 AS "x" WHERE 1 = 0');
                    $stmt->setFetchMode(PDO::FETCH_ASSOC);
                    return $stmt;
                }
                $selects = [];
                foreach ($rows as $i => $row) {
                    $cols = [];
                    foreach ($row as $k => $v) {
                        $lit = $v === null ? 'NULL' : (is_int($v) ? (string) $v : $this->quote((string) $v));
                        $cols[] = $i === 0 ? $lit . ' AS "' . $k . '"' : $lit;
                    }
                    $selects[] = 'SELECT ' . implode(', ', $cols);
                }
                $stmt = parent::query(implode(' UNION ALL ', $selects));
                $stmt->setFetchMode(PDO::FETCH_ASSOC);
                return $stmt;
            }
        };
    }
}
