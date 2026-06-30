<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use PDO;

/**
 * Reads active students straight from PowerSchool's Oracle database over ODBC.
 *
 * Unlike the staff reader (PowerSchoolOdbcReader), students are a pure
 * passthrough to OneSync: there is no matching, no crosswalk and no join across
 * extension tables. We run ONE query against the STUDENTS table and stage the
 * rows verbatim for OneSync to pull (see StudentImporter + v_onesync_student_source).
 *
 *   SELECT State_StudentNumber, SchoolID, Grade_Level, First_Name, Last_Name,
 *          ID, DCID, EntryCode, ExitCode, ExitDate
 *   FROM students WHERE enroll_status = 0 OR enroll_status = 3
 *
 * enroll_status 0 = currently enrolled, 3 = future enrollment — both are pulled
 * so OneSync can provision ahead of an enrollment start. enroll_status is also
 * selected so the staged row records which bucket it came from. ExitDate is
 * TO_CHAR'd to a canonical Y-m-d so Normalizer::parseDate gets a stable format
 * regardless of the session's NLS_DATE_FORMAT. read() returns the rows in the
 * same trimmed-string, header-keyed shape the CSV path produces (NULLs as '').
 *
 * The connection is read-only intent — SELECT only. PS_ODBC_SCHEMA prefixes the
 * table owner when the STUDENTS table isn't in the connecting user's schema.
 */
final class StudentOdbcReader
{
    /** PowerSchool Students.Enroll_Status values we sync (enrolled + future). */
    private const ENROLL_STATUSES = [0, 3];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connectPowerSchoolSource();
    }

    /**
     * Query the active students from PowerSchool.
     *
     * @return array<int,array<string,string>> one row per enrollment, header-keyed
     */
    public function read(): array
    {
        return PowerSchoolOdbcReader::shapeRows($this->db->query($this->studentsSql()));
    }

    /**
     * Optional schema/owner prefix for the STUDENTS table (PS_ODBC_SCHEMA), so the
     * connecting user need not have it in its default schema.
     */
    private function table(string $name): string
    {
        $schema = trim((string) Config::get('PS_ODBC_SCHEMA', ''));
        return $schema !== '' ? $schema . '.' . $name : $name;
    }

    /**
     * Every active/future enrollment, one row per Students record. Keys are the
     * dotted-style aliases the importer reads; ExitDate is normalised to Y-m-d.
     */
    private function studentsSql(): string
    {
        return 'SELECT '
            . 's.state_studentnumber AS "Students.State_StudentNumber", '
            . 's.schoolid            AS "Students.SchoolID", '
            . 's.grade_level         AS "Students.Grade_Level", '
            . 's.first_name          AS "Students.First_Name", '
            . 's.last_name           AS "Students.Last_Name", '
            . 's.id                  AS "Students.ID", '
            . 's.dcid                AS "Students.DCID", '
            . 's.entrycode           AS "Students.EntryCode", '
            . 's.exitcode            AS "Students.ExitCode", '
            . "TO_CHAR(s.exitdate, 'YYYY-MM-DD') AS \"Students.ExitDate\", "
            . 's.enroll_status       AS "Students.Enroll_Status" '
            . 'FROM ' . $this->table('students') . ' s '
            . 'WHERE s.enroll_status = ' . self::ENROLL_STATUSES[0]
            . ' OR s.enroll_status = ' . self::ENROLL_STATUSES[1];
    }
}
