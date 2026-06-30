<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\StudentOdbcReader;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The student reader runs ONE query against PowerSchool's STUDENTS table and
 * hands the rows back in the same trimmed-string, header-keyed shape the staff
 * reader produces. These tests pin the SQL (the columns + the enroll_status
 * filter the district asked for) and the row shaping, using a fake PDO so no
 * Oracle/ODBC driver is needed.
 */
final class StudentOdbcReaderTest extends TestCase
{
    public function testQueriesActiveAndFutureEnrollments(): void
    {
        $db = $this->fakeDb();
        (new StudentOdbcReader($db))->read();
        $sql = $db->lastQuery;

        // Both enrollment buckets the district syncs: 0 = enrolled, 3 = future.
        self::assertStringContainsString('enroll_status = 0', $sql);
        self::assertStringContainsString('enroll_status = 3', $sql);
        self::assertStringContainsStringIgnoringCase('FROM students', $sql);

        // Every column the district named, by its dotted alias.
        foreach ([
            'Students.State_StudentNumber', 'Students.SchoolID', 'Students.Grade_Level',
            'Students.First_Name', 'Students.Last_Name', 'Students.ID', 'Students.DCID',
            'Students.EntryCode', 'Students.ExitCode', 'Students.ExitDate',
        ] as $alias) {
            self::assertStringContainsString('"' . $alias . '"', $sql);
        }
    }

    public function testReadShapesRowsLikeTheCsvPath(): void
    {
        $rows = (new StudentOdbcReader($this->fakeDb()))->read();

        self::assertCount(2, $rows);
        self::assertSame('1001', $rows[0]['Students.DCID'], 'NUMBER cast to string');
        self::assertSame('Ada', $rows[0]['Students.First_Name'], 'trimmed');
        self::assertSame('', $rows[0]['Students.ExitDate'], 'NULL becomes empty string');
        self::assertSame('0', $rows[0]['Students.Enroll_Status']);
        self::assertSame('3', $rows[1]['Students.Enroll_Status']);
    }

    /** A PDO whose query() records the SQL and returns canned, typed student rows. */
    private function fakeDb(): PDO
    {
        return new class ('sqlite::memory:') extends PDO {
            public string $lastQuery = '';

            public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false
            {
                $this->lastQuery = $query;
                $rows = [
                    ['Students.State_StudentNumber' => 7001, 'Students.SchoolID' => 160,
                     'Students.Grade_Level' => 9, 'Students.First_Name' => '  Ada ', 'Students.Last_Name' => 'Lovelace',
                     'Students.ID' => 501, 'Students.DCID' => 1001, 'Students.EntryCode' => 'E1',
                     'Students.ExitCode' => null, 'Students.ExitDate' => null, 'Students.Enroll_Status' => 0],
                    ['Students.State_StudentNumber' => 7002, 'Students.SchoolID' => 75,
                     'Students.Grade_Level' => 10, 'Students.First_Name' => 'Grace', 'Students.Last_Name' => 'Hopper',
                     'Students.ID' => 502, 'Students.DCID' => 1002, 'Students.EntryCode' => 'E1',
                     'Students.ExitCode' => null, 'Students.ExitDate' => null, 'Students.Enroll_Status' => 3],
                ];
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
