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
}
