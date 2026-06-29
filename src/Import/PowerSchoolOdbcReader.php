<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use PDO;

/**
 * Reads PowerSchool staff straight from PowerSchool's Oracle database over ODBC.
 * This is the direct replacement for the old SFTP CSV feed: instead of exporting
 * CSVs that we pull and parse, we query the tables in place.
 *
 * TEACHERS is the anchor (it carries the per-assignment school and the staff
 * identity in this district's schema): we pull every active row
 *   FROM teachers WHERE status = 1
 * — one row per (teacher, school) — so a teacher at N schools yields N rows / N
 * TEACHERS.IDs, all linked to the crosswalk. The school for each assignment is
 * TEACHERS.SchoolID; the primary is the row where SchoolID = HomeSchoolId.
 *
 * USERS (+ its extension tables) supplies only the fields that aren't on TEACHERS
 * — middle name, staff_classification, hire/exit dates — joined by users_dcid.
 *
 * read() returns three datasets keyed to the *exact* dotted header names the CSV
 * exports used ("USERS.dcid", "TEACHERS.ID", "SCHOOLSTAFF.SchoolID", …), so
 * PowerSchoolBundle::combine() consumes them unchanged. The SCHOOLSTAFF dataset
 * is derived from the TEACHERS rows (their SchoolID) — this district keeps the
 * assignment school on TEACHERS rather than a separate SCHOOLSTAFF table. Oracle
 * preserves the case of double-quoted aliases, so the keys come back as written.
 *
 * Adjust the SQL here (or set PS_ODBC_SCHEMA for a table-owner prefix) to match
 * the district's live PS schema. The connection is read-only intent — SELECT only.
 */
final class PowerSchoolOdbcReader
{
    /** PowerSchool TEACHERS.Status for an active staff record. */
    private const STATUS_ACTIVE = 1;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connectPowerSchoolSource();
    }

    /**
     * Query the datasets PowerSchoolBundle::combine() needs. SCHOOLSTAFF is
     * derived from the TEACHERS rows (assignment school = TEACHERS.SchoolID).
     *
     * @return array{users:array<int,array<string,string>>,teachers:array<int,array<string,string>>,schoolstaff:array<int,array<string,string>>}
     */
    public function read(): array
    {
        $teachers = $this->query($this->teachersSql());
        $users = $this->query($this->usersSql());
        // The DOB / ALSID / contact columns are newer + district-specific, so a
        // schema that lacks one must not break the core import. Pull them in a
        // separate best-effort query and merge in; on failure they're just blank.
        $this->mergeExtendedDemographics($users);
        return [
            'users'       => $users,
            'teachers'    => $teachers,
            'schoolstaff' => self::schoolStaffFromTeachers($teachers),
        ];
    }

    /**
     * Merge the optional demographic/verification columns (DOB, ALSID, e-mail,
     * gender, phone, address) into the USERS rows by dcid. Each group is its OWN
     * best-effort query so one missing column can't blank the others: if a query
     * fails (a column the schema doesn't have), it's logged and skipped, and the
     * core import plus the other groups still succeed.
     *
     * @param array<int,array<string,string>> $users by-ref; extended columns added
     */
    private function mergeExtendedDemographics(array &$users): void
    {
        $index = [];
        foreach ($users as $i => $u) {
            $index[trim((string) ($u['USERS.dcid'] ?? ''))] = $i;
        }
        foreach ($this->extendedQueries() as $label => $sql) {
            try {
                $rows = $this->query($sql);
            } catch (\Throwable $e) {
                error_log("[idm] PowerSchool extended demographics ({$label}) skipped: " . $e->getMessage());
                continue;
            }
            foreach ($rows as $row) {
                $dcid = trim((string) ($row['USERS.dcid'] ?? ''));
                if (isset($index[$dcid])) {
                    $users[$index[$dcid]] = array_merge($users[$index[$dcid]], $row);
                }
            }
        }
    }

    /**
     * Project the TEACHERS rows into the SCHOOLSTAFF shape combine() expects (one
     * assignment per teacher row, school from TEACHERS.SchoolID). Keeps the school
     * source consistent with TEACHERS without assuming a separate SCHOOLSTAFF table.
     *
     * @param array<int,array<string,string>> $teachers
     * @return array<int,array<string,string>>
     */
    public static function schoolStaffFromTeachers(array $teachers): array
    {
        $out = [];
        foreach ($teachers as $t) {
            $out[] = [
                'SCHOOLSTAFF.dcid'       => $t['TEACHERS.dcid'] ?? '',
                'SCHOOLSTAFF.Users_DCID' => $t['TEACHERS.Users_DCID'] ?? '',
                'SCHOOLSTAFF.SchoolID'   => $t['TEACHERS.SchoolID'] ?? '',
            ];
        }
        return $out;
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
     * Every active staff assignment (one row per teacher/school). HomeSchoolId,
     * SchoolID and Title come from TEACHERS in this schema; combine() uses
     * SchoolID for the assignment, HomeSchoolId to pick the primary, and TEACHERS
     * values as the fallback when the USERS row lacks them.
     */
    private function teachersSql(): string
    {
        return 'SELECT '
            . 't.id            AS "TEACHERS.ID", '
            . 't.dcid          AS "TEACHERS.dcid", '
            . 't.users_dcid    AS "TEACHERS.Users_DCID", '
            . 't.teachernumber AS "TEACHERS.TeacherNumber", '
            . 't.first_name    AS "TEACHERS.First_Name", '
            . 't.last_name     AS "TEACHERS.Last_Name", '
            . 't.homeschoolid  AS "TEACHERS.HomeSchoolId", '
            . 't.schoolid      AS "TEACHERS.SchoolID", '
            . 't.title         AS "TEACHERS.Title" '
            . 'FROM ' . $this->table('teachers') . ' t '
            . 'WHERE t.status = ' . self::STATUS_ACTIVE;
    }

    /**
     * USERS + extension tables — only the fields not on TEACHERS (middle name,
     * staff_classification, hire/exit dates), one row per active staff user.
     * Dates are TO_CHAR'd to a canonical Y-m-d so Normalizer::parseDate gets a
     * stable format regardless of the session's NLS_DATE_FORMAT. This is the
     * core, schema-stable set; DOB/ALSID/contact extras come from
     * extendedUsersSql() (best-effort, merged in read()).
     */
    private function usersSql(): string
    {
        return 'SELECT '
            . 'u.dcid        AS "USERS.dcid", '
            . 'u.first_name  AS "USERS.First_Name", '
            . 'u.middle_name AS "USERS.Middle_Name", '
            . 'u.last_name   AS "USERS.Last_Name", '
            . 'ext.staff_classification              AS "U_DEF_EXT_USERS.staff_classification", '
            . "TO_CHAR(sx.hiredate, 'YYYY-MM-DD')    AS \"S_USR_X.hiredate\", "
            . "TO_CHAR(alx.exit_date, 'YYYY-MM-DD')  AS \"S_AL_USR_X.exit_date\" "
            . 'FROM ' . $this->table('users') . ' u '
            . 'LEFT JOIN ' . $this->table('u_def_ext_users') . ' ext ON ext.usersdcid = u.dcid '
            . 'LEFT JOIN ' . $this->table('s_usr_x') . ' sx ON sx.usersdcid = u.dcid '
            . 'LEFT JOIN ' . $this->table('s_al_usr_x') . ' alx ON alx.usersdcid = u.dcid '
            . 'WHERE EXISTS (SELECT 1 FROM ' . $this->table('teachers') . ' t '
            . 'WHERE t.users_dcid = u.dcid AND t.status = ' . self::STATUS_ACTIVE . ')';
    }

    /**
     * Optional value groups pulled to VERIFY against NextGen (never written to the
     * golden record), plus the two PowerSchool-sourced fields NextGen lacks — date
     * of birth and the Alabama State ID (ALSID). Each group is a SEPARATE query so
     * a column one district lacks/renames only loses that group, not the rest:
     *   - contact      USERS demographics (e-mail, gender, phone, address)
     *   - staff_number ALSID = S_USR_X.state_staffnumber (joined on usersdcid)
     *   - dob          date of birth from the Alabama extension S_AL_USR_X.dob
     * Adjust the column names / joins to match your live PS schema.
     *
     * @return array<string,string> label => SQL (each selects USERS.dcid + columns)
     */
    private function extendedQueries(): array
    {
        $users = $this->table('users');
        $active = 'WHERE EXISTS (SELECT 1 FROM ' . $this->table('teachers')
            . ' t WHERE t.users_dcid = u.dcid AND t.status = ' . self::STATUS_ACTIVE . ')';

        $queries = [
            // USERS address/contact block. NOTE: USERS has no gender column — staff
            // gender is on the TEACHERS view (SCHED_GENDER), pulled separately below.
            'contact' => 'SELECT '
                . 'u.dcid       AS "USERS.dcid", '
                . 'u.email_addr AS "USERS.Email_Addr", '
                . 'u.home_phone AS "USERS.Home_Phone", '
                . 'u.street     AS "USERS.Street", '
                . 'u.city       AS "USERS.City", '
                . 'u.state      AS "USERS.State", '
                . 'u.zip        AS "USERS.Zip" '
                . 'FROM ' . $users . ' u ' . $active,

            // Staff gender = TEACHERS.SCHED_GENDER (M/F on the Teachers view), keyed
            // to the user via users_dcid so it merges onto the USERS row.
            'gender' => 'SELECT '
                . 't.users_dcid   AS "USERS.dcid", '
                . 't.sched_gender AS "TEACHERS.SCHED_GENDER" '
                . 'FROM ' . $this->table('teachers') . ' t WHERE t.status = ' . self::STATUS_ACTIVE,

            // ALSID lives on S_USR_X (state_staffnumber), joined by usersdcid — the
            // same join the core query already uses for S_USR_X.hiredate.
            'staff_number' => 'SELECT '
                . 'u.dcid              AS "USERS.dcid", '
                . 'sx.state_staffnumber AS "S_USR_X.state_staffnumber" '
                . 'FROM ' . $users . ' u '
                . 'LEFT JOIN ' . $this->table('s_usr_x') . ' sx ON sx.usersdcid = u.dcid ' . $active,
        ];

        // Staff DOB is not a standard PowerSchool field, so its column varies by
        // district. Enable it by naming the column on the Alabama extension
        // S_AL_USR_X via PS_STAFF_DOB_COLUMN (e.g. "dob"); when unset the query is
        // skipped entirely so no invalid-identifier error is raised.
        $dobCol = trim((string) Config::get('PS_STAFF_DOB_COLUMN', ''));
        if ($dobCol !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $dobCol) === 1) {
            $queries['dob'] = 'SELECT '
                . 'u.dcid AS "USERS.dcid", '
                . "TO_CHAR(alx.{$dobCol}, 'YYYY-MM-DD') AS \"S_AL_USR_X.dob\" "
                . 'FROM ' . $users . ' u '
                . 'LEFT JOIN ' . $this->table('s_al_usr_x') . ' alx ON alx.usersdcid = u.dcid ' . $active;
        }

        return $queries;
    }
}
