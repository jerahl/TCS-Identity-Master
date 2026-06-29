<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use PDO;

/**
 * Reads the three PowerSchool staff datasets — USERS, TEACHERS, SCHOOLSTAFF —
 * straight from PowerSchool's Oracle database over ODBC. This is the direct
 * replacement for the old SFTP CSV feed: instead of PowerSchool exporting three
 * CSVs that we pull and parse, we query the same tables in place.
 *
 * Each query aliases its columns to the *exact* dotted header names the CSV
 * exports used (e.g. "USERS.dcid", "TEACHERS.ID"), so the rows this returns are
 * shape-compatible with what Csv::read() produced and PowerSchoolBundle::combine()
 * can consume them unchanged. Oracle preserves the case of double-quoted column
 * aliases, so the keys come back exactly as written.
 *
 * The SQL mirrors the columns the export selected; adjust the constants here (or
 * set PS_ODBC_SCHEMA for a table-owner prefix) to match the district's live PS
 * schema. The connection is read-only intent — grant SELECT only.
 */
final class PowerSchoolOdbcReader
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connectPowerSchoolSource();
    }

    /**
     * Query all three datasets.
     *
     * @return array{users:array<int,array<string,string>>,teachers:array<int,array<string,string>>,schoolstaff:array<int,array<string,string>>}
     */
    public function read(): array
    {
        return [
            'users'       => $this->query($this->usersSql()),
            'teachers'    => $this->query($this->teachersSql()),
            'schoolstaff' => $this->query($this->schoolStaffSql()),
        ];
    }

    /**
     * Run one SELECT and shape its rows like Csv::read() would.
     *
     * @return array<int,array<string,string>>
     */
    private function query(string $sql): array
    {
        return self::shapeRows($this->db->query($sql));
    }

    /**
     * Normalize raw driver rows into the trimmed-string, header-keyed maps the
     * CSV path produces: every value cast to a trimmed string, NULLs become ''.
     * This is the bridge that lets PowerSchoolBundle::combine() consume ODBC rows
     * unchanged (it expects strings, never typed Oracle NUMBER/DATE values).
     *
     * @param iterable<array<string,mixed>> $rows
     * @return array<int,array<string,string>>
     */
    public static function shapeRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $row = [];
            foreach ($r as $k => $v) {
                $row[(string) $k] = $v === null ? '' : trim((string) $v);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Optional schema/owner prefix for the PS tables (PS_ODBC_SCHEMA), so the
     * connecting user need not have them in its default schema.
     */
    private function table(string $name): string
    {
        $schema = trim((string) Config::get('PS_ODBC_SCHEMA', ''));
        return $schema !== '' ? $schema . '.' . $name : $name;
    }

    /**
     * USERS + its extension tables (one row per user). Demographics, HomeSchoolId,
     * Title, classification, TeacherNumber, hire/exit. Dates are TO_CHAR'd to a
     * canonical Y-m-d so Normalizer::parseDate gets a stable format regardless of
     * the session's NLS_DATE_FORMAT.
     */
    private function usersSql(): string
    {
        return 'SELECT '
            . 'u.dcid           AS "USERS.dcid", '
            . 'u.first_name     AS "USERS.First_Name", '
            . 'u.middle_name    AS "USERS.Middle_Name", '
            . 'u.last_name      AS "USERS.Last_Name", '
            . 'u.homeschoolid   AS "USERS.HomeSchoolId", '
            . 'u.teachernumber  AS "USERS.TeacherNumber", '
            . 'u.title          AS "USERS.Title", '
            . 'ext.staff_classification              AS "U_DEF_EXT_USERS.staff_classification", '
            . "TO_CHAR(sx.hiredate, 'YYYY-MM-DD')    AS \"S_USR_X.hiredate\", "
            . "TO_CHAR(alx.exit_date, 'YYYY-MM-DD')  AS \"S_AL_USR_X.exit_date\" "
            . 'FROM ' . $this->table('users') . ' u '
            . 'LEFT JOIN ' . $this->table('u_def_ext_users') . ' ext ON ext.usersdcid = u.dcid '
            . 'LEFT JOIN ' . $this->table('s_usr_x') . ' sx ON sx.usersdcid = u.dcid '
            . 'LEFT JOIN ' . $this->table('s_al_usr_x') . ' alx ON alx.usersdcid = u.dcid';
    }

    /**
     * TEACHERS (one row per user/school assignment). TEACHERS.ID is the
     * per-assignment PS id AD mirrors as "T"+ID; TEACHERS.Users_DCID = USERS.dcid.
     */
    private function teachersSql(): string
    {
        return 'SELECT '
            . 't.id            AS "TEACHERS.ID", '
            . 't.dcid          AS "TEACHERS.dcid", '
            . 't.users_dcid    AS "TEACHERS.Users_DCID", '
            . 't.teachernumber AS "TEACHERS.TeacherNumber", '
            . 't.first_name    AS "TEACHERS.First_Name", '
            . 't.last_name     AS "TEACHERS.Last_Name" '
            . 'FROM ' . $this->table('teachers') . ' t';
    }

    /**
     * SCHOOLSTAFF (one row per assignment). SCHOOLSTAFF.SchoolID is that
     * assignment's school code; SCHOOLSTAFF.Users_DCID = USERS.dcid.
     */
    private function schoolStaffSql(): string
    {
        return 'SELECT '
            . 'ss.dcid       AS "SCHOOLSTAFF.dcid", '
            . 'ss.users_dcid AS "SCHOOLSTAFF.Users_DCID", '
            . 'ss.schoolid   AS "SCHOOLSTAFF.SchoolID" '
            . 'FROM ' . $this->table('schoolstaff') . ' ss';
    }
}
