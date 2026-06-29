<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Combines the three PowerSchool exports into one record per person — pure and
 * unit-testable (no DB).
 *
 * The PS data model:
 *   - USERS        — one row per user (USERS.dcid). Demographics, HomeSchoolId,
 *                    Title, staff_classification, TeacherNumber, hire/exit.
 *   - TEACHERS     — one row per (user, school) assignment. TEACHERS.Users_DCID
 *                    = USERS.dcid; TEACHERS.ID is the per-assignment PS id that
 *                    AD mirrors as its uniqueId ("T" + TEACHERS.ID). A user with
 *                    N schools has N TEACHERS rows / N TEACHERS.IDs.
 *   - SCHOOLSTAFF  — one row per assignment too; SCHOOLSTAFF.dcid = TEACHERS.dcid,
 *                    SCHOOLSTAFF.SchoolID is that assignment's school code,
 *                    SCHOOLSTAFF.Users_DCID = USERS.dcid.
 *
 * Anchored on the users that appear in TEACHERS (the staff who get accounts).
 * USERS enriches demographics; SCHOOLSTAFF supplies the school assignments.
 */
final class PowerSchoolBundle
{
    /**
     * @param array<int,array<string,string>> $users       USERS export rows
     * @param array<int,array<string,string>> $teachers    TEACHERS export rows
     * @param array<int,array<string,string>> $schoolStaff SCHOOLSTAFF export rows
     * @return PsUser[]
     */
    public static function combine(array $users, array $teachers, array $schoolStaff): array
    {
        $usersByDcid = [];
        foreach ($users as $u) {
            $usersByDcid[trim((string) ($u['USERS.dcid'] ?? ''))] = $u;
        }

        // Group TEACHERS rows and SCHOOLSTAFF rows by the canonical user dcid.
        $teachersByUser = [];
        foreach ($teachers as $t) {
            $ud = trim((string) ($t['TEACHERS.Users_DCID'] ?? ''));
            if ($ud !== '') {
                $teachersByUser[$ud][] = $t;
            }
        }
        $staffByUser = [];
        foreach ($schoolStaff as $s) {
            $ud = trim((string) ($s['SCHOOLSTAFF.Users_DCID'] ?? ''));
            if ($ud !== '') {
                $staffByUser[$ud][] = $s;
            }
        }

        $out = [];
        foreach ($teachersByUser as $ud => $trows) {
            $u = $usersByDcid[$ud] ?? [];

            $first = self::pick($u, 'USERS.First_Name', $trows[0], 'TEACHERS.First_Name');
            $last = self::pick($u, 'USERS.Last_Name', $trows[0], 'TEACHERS.Last_Name');
            $middle = trim((string) ($u['USERS.Middle_Name'] ?? ''));
            // HomeSchoolId / Title / TeacherNumber may live on USERS or TEACHERS
            // depending on the schema; prefer USERS, fall back to TEACHERS.
            $homeCode = self::pick($u, 'USERS.HomeSchoolId', $trows[0], 'TEACHERS.HomeSchoolId');
            $employeeId = self::pick($u, 'USERS.TeacherNumber', $trows[0], 'TEACHERS.TeacherNumber');

            // All distinct TEACHERS.IDs for this user (each = one AD-able PS id).
            $teacherIds = [];
            foreach ($trows as $t) {
                $id = trim((string) ($t['TEACHERS.ID'] ?? ''));
                if ($id !== '') {
                    $teacherIds[$id] = true;
                }
            }

            // School assignments from SCHOOLSTAFF (distinct SchoolIDs); primary is
            // the one matching USERS.HomeSchoolId, else the first.
            $codes = [];
            foreach ($staffByUser[$ud] ?? [] as $s) {
                $c = trim((string) ($s['SCHOOLSTAFF.SchoolID'] ?? ''));
                if ($c !== '') {
                    $codes[$c] = true;
                }
            }
            if ($codes === [] && $homeCode !== '') {
                $codes[$homeCode] = true;
            }
            $schools = [];
            foreach (array_keys($codes) as $c) {
                $c = (string) $c; // PHP casts numeric-string array keys to int
                $schools[] = ['code' => $c, 'primary' => $c === $homeCode];
            }
            if ($schools !== [] && array_filter($schools, static fn($x) => $x['primary']) === []) {
                $schools[0]['primary'] = true; // no HomeSchool match -> first is primary
            }

            $out[] = new PsUser(
                usersDcid: (string) $ud,
                employeeId: $employeeId,
                firstName: $first,
                middleName: $middle,
                lastName: $last,
                title: self::nz(self::pick($u, 'USERS.Title', $trows[0], 'TEACHERS.Title')),
                classification: self::nz($u['U_DEF_EXT_USERS.staff_classification'] ?? ''),
                hireDate: self::nz($u['S_USR_X.hiredate'] ?? ''),
                endDate: self::nz($u['S_AL_USR_X.exit_date'] ?? ''),
                teacherIds: array_map('strval', array_keys($teacherIds)),
                schools: $schools,
                dob: self::nz($u['S_AL_USR_X.dob'] ?? ''),
                alsdeId: self::nz($u['S_AL_USR_X.StaffStateID'] ?? ''),
            );
        }
        return $out;
    }

    /** Identify which PowerSchool export a row (its headers) belongs to. */
    public static function classify(array $row): ?string
    {
        $keys = array_keys($row);
        return match (true) {
            in_array('USERS.dcid', $keys, true) => 'users',
            in_array('TEACHERS.ID', $keys, true) => 'teachers',
            in_array('SCHOOLSTAFF.dcid', $keys, true) => 'schoolstaff',
            default => null,
        };
    }

    /** @param array<string,string> $a @param array<string,string> $b */
    private static function pick(array $a, string $ak, array $b, string $bk): string
    {
        $v = trim((string) ($a[$ak] ?? ''));
        return $v !== '' ? $v : trim((string) ($b[$bk] ?? ''));
    }

    private static function nz(string $v): ?string
    {
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
