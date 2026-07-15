<?php

declare(strict_types=1);

namespace App\Export;

use App\Db;
use App\Sync\Sftp\SftpClient;
use PDO;
use RuntimeException;

/**
 * Exports the current staff roster as two tab-delimited files that load
 * cleanly into PowerSchool SIS (see docs/powerschool-staff-export.md and the
 * district import spec), and uploads them to the district SFTP server:
 *
 * Only people who need a PowerSchool update are exported — NOT the full
 * roster. A person (active/pending) is included when they are:
 *
 *   NEW — no active person_source_id row with system='powerschool', i.e.
 *   PowerSchool has never reported them back through the nightly import.
 *   New users without an ALSDE ID are held back and logged (district
 *   practice: PowerSchool staff demographics require it).
 *
 *   CHANGED — already in PowerSchool, but the golden-record first/last name
 *   OR district email differs from the latest PowerSchool import snapshot
 *   (the newest matched staging_record row). A username rename always moves
 *   the email with it, so the email comparison is how a username change is
 *   detected — the snapshot doesn't carry the login id. The email comparison
 *   only fires when the golden email is set AND the snapshot recorded a
 *   PowerSchool email (raw_json fields.hr_email); people never snapshotted
 *   are skipped (nothing to compare against).
 *
 * ps_staff_demographics.txt — Data Import Manager, target USERSCOREFIELDS.
 * One row per exported person, matched on USERS.TeacherNumber (= Employee
 * ID). Spec columns with no IDM source (USERS.FedEthnicity,
 * USERS.FedRaceDecline, S_USR_X.employmentstatus) are omitted entirely —
 * header and values — so a DIM update never mass-blanks them.
 *
 * ps_staff_assignments.txt — AutoComm/Quick Import into the Teachers view.
 * One row per exported person PER school assignment (multi-school staff
 * repeat), headers WITHOUT table prefixes (the Teachers import rejects
 * Table.Field). Sorted by SchoolID so a split-by-school is trivial.
 *
 * Individual race codes (TeacherRace.RaceCd) are NOT importable in this
 * PowerSchool build and are deliberately not exported. Users.Ethnicity is
 * deprecated and never populated. Passwords and SSNs are never exported.
 *
 * Validation (per the spec) — every problem lands in the exceptions list,
 * nothing is dropped silently:
 *   - rows missing TeacherNumber, Last_Name, or First_Name are rejected;
 *   - a TeacherNumber longer than 20 chars is rejected (truncating the match
 *     key could collide with or update the wrong record);
 *   - duplicate TeacherNumbers within the demographics file are rejected;
 *   - HomeSchoolId / SchoolID that cannot be resolved to a PowerSchool
 *     School_Number (school.ps_school_id) reject the row;
 *   - over-long non-key values are truncated to the spec max and logged;
 *   - unmapped person types default to StaffStatus 2 (Staff) and are logged.
 */
final class PowerSchoolStaffExporter
{
    public const DEMOGRAPHICS_FILE = 'ps_staff_demographics.txt';
    public const ASSIGNMENTS_FILE = 'ps_staff_assignments.txt';
    public const DEMOGRAPHICS_SAMPLE_FILE = 'ps_staff_demographics_sample.txt';
    public const ASSIGNMENTS_SAMPLE_FILE = 'ps_staff_assignments_sample.txt';
    public const EXCEPTIONS_FILE = 'ps_staff_exceptions.txt';

    /**
     * Demographics headers, in output order — exact names from the district
     * spec. Spec columns 10 (USERS.FedEthnicity), 11 (USERS.FedRaceDecline)
     * and 13 (S_USR_X.employmentstatus) have no IDM source and are omitted.
     */
    public const DEMOGRAPHIC_HEADERS = [
        'USERS.TeacherNumber',
        'USERS.Last_Name',
        'USERS.First_Name',
        'USERS.Middle_Name',
        'USERS.Email_Addr',
        'USERS.SIF_StatePrid',
        'USERS.Title',
        'USERS.HomeSchoolId',
        'USERS.TeacherLoginID',
        'S_USR_X.hiredate',
        'S_USR_X.state_staffnumber',
    ];

    /** Assignment headers — NO table prefix (the Teachers import rejects them). */
    public const ASSIGNMENT_HEADERS = [
        'TeacherNumber',
        'SchoolID',
        'Status',
        'StaffStatus',
    ];

    /** Spec max lengths, keyed by demographics column. */
    private const MAX_LENGTHS = [
        'USERS.TeacherNumber'  => 20,
        'USERS.Last_Name'      => 100,
        'USERS.First_Name'     => 100,
        'USERS.Middle_Name'    => 100,
        'USERS.Email_Addr'     => 50,
        'USERS.SIF_StatePrid'  => 32,
        'USERS.Title'          => 40,
        'USERS.TeacherLoginID' => 20,
    ];

    /** Status: 1 = Current, 2 = No longer here. */
    private const STATUS_CURRENT = '1';
    private const STATUS_ENDED = '2';

    /** StaffStatus: 0 Not Assigned, 1 Teacher, 2 Staff, 3 Lunch Staff, 4 Substitute. */
    private const STAFF_STATUS = ['faculty' => '1', 'staff' => '2', 'sub' => '4'];
    private const STAFF_STATUS_DEFAULT = '2';

    private PDO $db;
    private string $today;

    public function __construct(?PDO $db = null, ?string $today = null)
    {
        $this->db = $db ?? Db::connect();
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * Build both files' rows plus the exception log and run summary.
     *
     * @return array{
     *     demographics: array<int,array<string,string>>,
     *     assignments: array<int,array<string,string>>,
     *     new: array<int,string>,
     *     changed: array<int,string>,
     *     exceptions: array<int,string>,
     *     summary: array{new:int,changed:int,demographics:int,assignments:int,exceptions:int,schools:int}
     * }
     */
    public function export(): array
    {
        $exceptions = [];
        $people = $this->selectPeople($exceptions);
        $demographics = $this->demographicRows($people, $exceptions);
        $assignments = $this->assignmentRows(
            array_column($people, 'person_id', 'person_id'), $exceptions);

        $schools = array_unique(array_merge(
            array_column($demographics, 'USERS.HomeSchoolId'),
            array_column($assignments, 'SchoolID'),
        ));
        $new = array_values(array_filter($people, static fn(array $p): bool => $p['_reason'] === 'new'));
        $changed = array_values(array_filter($people, static fn(array $p): bool => $p['_reason'] === 'changed'));

        return [
            'demographics' => $demographics,
            'assignments' => $assignments,
            'new' => array_map($this->label(...), $new),
            'changed' => array_map(
                fn(array $p): string => $this->label($p)
                    . ' — was ' . trim((string) $p['ps_last'] . ', ' . (string) $p['ps_first'], ', ')
                    . (self::emailChanged((string) ($p['email'] ?? ''), $p['ps_email'])
                        ? ', email was ' . $p['ps_email'] : ''),
                $changed),
            'exceptions' => $exceptions,
            'summary' => [
                'new' => count($new),
                'changed' => count($changed),
                'demographics' => count($demographics),
                'assignments' => count($assignments),
                'exceptions' => count($exceptions),
                'schools' => count($schools),
            ],
        ];
    }

    /**
     * The people this run exports: NEW (not in PowerSchool, ALSDE ID set) and
     * CHANGED (in PowerSchool, name or district email moved since the latest
     * import snapshot). Each returned row carries '_reason' = new|changed,
     * plus ps_first/ps_last/ps_email from the snapshot for reporting.
     *
     * @param array<int,string> $exceptions
     * @return array<int,array<string,mixed>>
     */
    private function selectPeople(array &$exceptions): array
    {
        $sql = "SELECT p.person_id, p.first_name, p.middle_name, p.last_name,
                       p.email, p.username, p.employee_id, p.alsde_id, p.hire_date,
                       s.ps_school_id,
                       (SELECT a.title FROM assignment a
                         WHERE a.person_id = p.person_id AND a.is_primary = 1
                         ORDER BY a.id LIMIT 1) AS title,
                       EXISTS (SELECT 1 FROM person_source_id psi
                                WHERE psi.person_id = p.person_id
                                  AND psi.system = 'powerschool'
                                  AND psi.is_active = 1) AS in_ps,
                       (SELECT sr.n_first FROM staging_record sr
                         WHERE sr.matched_person_id = p.person_id AND sr.system = 'powerschool'
                         ORDER BY sr.id DESC LIMIT 1) AS ps_first,
                       (SELECT sr.n_last FROM staging_record sr
                         WHERE sr.matched_person_id = p.person_id AND sr.system = 'powerschool'
                         ORDER BY sr.id DESC LIMIT 1) AS ps_last,
                       (SELECT sr.raw_json FROM staging_record sr
                         WHERE sr.matched_person_id = p.person_id AND sr.system = 'powerschool'
                         ORDER BY sr.id DESC LIMIT 1) AS ps_raw
                  FROM person p
                  LEFT JOIN school s ON s.school_id = p.primary_school_id
                 WHERE p.status IN ('active','pending')
                 ORDER BY p.last_name, p.first_name, p.person_id";

        $people = [];
        foreach ($this->db->query($sql)->fetchAll() as $p) {
            $p['ps_email'] = self::snapshotEmail($p['ps_raw'] ?? null);
            unset($p['ps_raw']);

            if (!(bool) $p['in_ps']) {
                if (trim((string) ($p['alsde_id'] ?? '')) === '') {
                    $exceptions[] = 'selection: ' . $this->label($p)
                        . ' — new user without an ALSDE ID; held back';
                    continue;
                }
                $p['_reason'] = 'new';
                $people[] = $p;
                continue;
            }

            $psFirst = trim((string) ($p['ps_first'] ?? ''));
            $psLast = trim((string) ($p['ps_last'] ?? ''));
            if ($psFirst === '' && $psLast === '') {
                continue; // never snapshotted — nothing to compare against
            }
            $nameChanged = !self::sameValue((string) $p['first_name'], $psFirst)
                || !self::sameValue((string) $p['last_name'], $psLast);
            if (!$nameChanged && !self::emailChanged((string) ($p['email'] ?? ''), $p['ps_email'])) {
                continue; // unchanged — nothing to export
            }
            $p['_reason'] = 'changed';
            $people[] = $p;
        }
        return $people;
    }

    /**
     * One validated demographics row per selected person, keyed by
     * DEMOGRAPHIC_HEADERS.
     *
     * @param array<int,array<string,mixed>> $people selectPeople() rows
     * @param array<int,string> $exceptions
     * @return array<int,array<string,string>>
     */
    private function demographicRows(array $people, array &$exceptions): array
    {
        $rows = [];
        $seen = [];
        foreach ($people as $p) {
            $who = $this->label($p);
            $teacherNumber = self::clean((string) ($p['employee_id'] ?? ''));
            $last = self::clean((string) ($p['last_name'] ?? ''));
            $first = self::clean((string) ($p['first_name'] ?? ''));

            $missing = array_keys(array_filter(
                ['TeacherNumber' => $teacherNumber, 'Last_Name' => $last, 'First_Name' => $first],
                static fn(string $v): bool => $v === ''
            ));
            if ($missing !== []) {
                $exceptions[] = "demographics: {$who} — missing " . implode(', ', $missing) . '; row rejected';
                continue;
            }
            if (mb_strlen($teacherNumber) > self::MAX_LENGTHS['USERS.TeacherNumber']) {
                $exceptions[] = "demographics: {$who} — TeacherNumber '{$teacherNumber}' exceeds 20 chars"
                    . ' (match key is never truncated); row rejected';
                continue;
            }
            if (isset($seen[$teacherNumber])) {
                $exceptions[] = "demographics: {$who} — duplicate TeacherNumber '{$teacherNumber}'"
                    . " (already used by {$seen[$teacherNumber]}); row rejected";
                continue;
            }
            $school = self::psSchoolId(self::clean((string) ($p['ps_school_id'] ?? '')));
            if ($school === '') {
                $exceptions[] = "demographics: {$who} — home school cannot be resolved to a"
                    . ' PowerSchool School_Number; row rejected';
                continue;
            }
            $seen[$teacherNumber] = $who;

            $rows[] = [
                'USERS.TeacherNumber'     => $teacherNumber,
                'USERS.Last_Name'         => $this->limit('USERS.Last_Name', $last, $who, $exceptions),
                'USERS.First_Name'        => $this->limit('USERS.First_Name', $first, $who, $exceptions),
                'USERS.Middle_Name'       => $this->limit('USERS.Middle_Name', self::clean((string) ($p['middle_name'] ?? '')), $who, $exceptions),
                'USERS.Email_Addr'        => $this->limit('USERS.Email_Addr', self::clean((string) ($p['email'] ?? '')), $who, $exceptions),
                // District practice: the state personnel id IS the employee id.
                'USERS.SIF_StatePrid'     => $this->limit('USERS.SIF_StatePrid', $teacherNumber, $who, $exceptions),
                'USERS.Title'             => $this->limit('USERS.Title', self::clean((string) ($p['title'] ?? '')), $who, $exceptions),
                'USERS.HomeSchoolId'      => $school,
                'USERS.TeacherLoginID'    => $this->limit('USERS.TeacherLoginID', self::clean((string) ($p['username'] ?? '')), $who, $exceptions),
                'S_USR_X.hiredate'        => self::usDate((string) ($p['hire_date'] ?? '')),
                'S_USR_X.state_staffnumber' => self::clean((string) ($p['alsde_id'] ?? '')),
            ];
        }
        return $rows;
    }

    /**
     * One validated assignment row per exported person per school, keyed by
     * ASSIGNMENT_HEADERS and sorted by SchoolID. Ended assignments export
     * Status 2 (No longer here) so transfers clear the old school; people
     * with no assignment rows fall back to their primary school. Duplicate
     * (TeacherNumber, SchoolID) pairs collapse to one row, preferring Current.
     *
     * @param array<int,int|string> $personIds person_ids selected for export
     * @param array<int,string> $exceptions
     * @return array<int,array<string,string>>
     */
    private function assignmentRows(array $personIds, array &$exceptions): array
    {
        $sql = "SELECT p.person_id, p.first_name, p.last_name, p.employee_id, p.person_type,
                       s.ps_school_id, a.end_date
                  FROM assignment a
                  JOIN person p ON p.person_id = a.person_id
                  LEFT JOIN school s ON s.school_id = a.school_id
                 WHERE p.status IN ('active','pending')
                 ORDER BY p.person_id, a.id";
        $fallback = "SELECT p.person_id, p.first_name, p.last_name, p.employee_id, p.person_type,
                            s.ps_school_id, NULL AS end_date
                       FROM person p
                       LEFT JOIN school s ON s.school_id = p.primary_school_id
                      WHERE p.status IN ('active','pending')
                        AND NOT EXISTS (SELECT 1 FROM assignment a WHERE a.person_id = p.person_id)
                      ORDER BY p.person_id";

        $byKey = [];
        $unmappedTypes = [];
        $source = array_merge($this->db->query($sql)->fetchAll(), $this->db->query($fallback)->fetchAll());
        foreach ($source as $a) {
            if (!isset($personIds[(int) $a['person_id']])) {
                continue; // not part of this export (unchanged / held back / terminated)
            }
            $who = $this->label($a);
            $teacherNumber = self::clean((string) ($a['employee_id'] ?? ''));
            if ($teacherNumber === '' || mb_strlen($teacherNumber) > self::MAX_LENGTHS['USERS.TeacherNumber']) {
                $exceptions[] = "assignments: {$who} — missing or over-long TeacherNumber; row rejected";
                continue;
            }
            $school = self::psSchoolId(self::clean((string) ($a['ps_school_id'] ?? '')));
            if ($school === '') {
                $exceptions[] = "assignments: {$who} — school cannot be resolved to a"
                    . ' PowerSchool School_Number; row rejected';
                continue;
            }
            $type = (string) ($a['person_type'] ?? '');
            if (!isset(self::STAFF_STATUS[$type]) && !isset($unmappedTypes[$type])) {
                $unmappedTypes[$type] = true;
                $exceptions[] = "assignments: unmapped person type '{$type}' (first seen on {$who})"
                    . ' — defaulted to StaffStatus ' . self::STAFF_STATUS_DEFAULT . ' (Staff)';
            }
            $endDate = trim((string) ($a['end_date'] ?? ''));
            $status = ($endDate !== '' && $endDate <= $this->today) ? self::STATUS_ENDED : self::STATUS_CURRENT;

            $key = "{$teacherNumber}\t{$school}";
            if (isset($byKey[$key]) && $byKey[$key]['Status'] === self::STATUS_CURRENT) {
                continue; // already have a Current row for this person+school
            }
            $byKey[$key] = [
                'TeacherNumber' => $teacherNumber,
                'SchoolID'      => $school,
                'Status'        => $status,
                'StaffStatus'   => self::STAFF_STATUS[$type] ?? self::STAFF_STATUS_DEFAULT,
            ];
        }

        $rows = array_values($byKey);
        usort($rows, static fn(array $x, array $y): int =>
            [$x['SchoolID'], $x['TeacherNumber']] <=> [$y['SchoolID'], $y['TeacherNumber']]);
        return $rows;
    }

    /**
     * Render a full file: tab-delimited, header row first, CRLF line endings,
     * one trailing newline, no quoting (values are already tab/newline-free).
     *
     * @param array<int,string> $headers
     * @param array<int,array<string,string>> $rows keyed by $headers
     */
    public static function render(array $headers, array $rows): string
    {
        $lines = [implode("\t", $headers)];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_values($row));
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The 3-row sample of a file for a manual test import before the full
     * file is used.
     *
     * @param array<int,string> $headers
     * @param array<int,array<string,string>> $rows
     */
    public static function sample(array $headers, array $rows): string
    {
        return self::render($headers, array_slice($rows, 0, 3));
    }

    /** @param array<int,string> $exceptions one line each; empty -> empty file */
    public static function exceptionsFile(array $exceptions): string
    {
        return $exceptions === [] ? '' : implode("\r\n", $exceptions) . "\r\n";
    }

    /**
     * Write a rendered file into $dir under a FIXED name — every run
     * overwrites the last, so PowerSchool's scheduled imports can point at a
     * constant file name.
     *
     * @return array{path:string,bytes:int}
     */
    public static function writeFile(string $content, string $dir, string $fileName): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create export directory: {$dir}");
        }
        $path = rtrim($dir, '/') . '/' . $fileName;
        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            throw new RuntimeException("Cannot write export file: {$path}");
        }
        return ['path' => $path, 'bytes' => $bytes];
    }

    /** Upload an export file to the SFTP drop directory; returns the remote path. */
    public static function uploadFile(SftpClient $client, string $localPath, string $remoteDir): string
    {
        $remotePath = rtrim($remoteDir, '/') . '/' . basename($localPath);
        $client->connect();
        $client->upload($localPath, $remotePath);
        return $remotePath;
    }

    /**
     * PowerSchool location codes are 4 digits — left-pad shorter numeric IDM
     * codes with zeros ("130" -> "0130"). Empty and non-numeric pass through.
     */
    public static function psSchoolId(string $code): string
    {
        $code = trim($code);
        return preg_match('/^\d{1,3}$/', $code) ? str_pad($code, 4, '0', STR_PAD_LEFT) : $code;
    }

    /** Y-m-d (DB) -> MM/DD/YYYY (PowerSchool import format); anything else passes through. */
    public static function usDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return "{$m[2]}/{$m[3]}/{$m[1]}";
        }
        return $date;
    }

    /** Tab-delimited output: tabs/newlines become spaces, then trim. */
    private static function clean(string $value): string
    {
        return trim(str_replace(["\t", "\r\n", "\r", "\n"], ' ', $value));
    }

    /** Case-insensitive equality (mirrors FieldMap::valuesEqual's default). */
    private static function sameValue(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * The PowerSchool email from a snapshot's raw_json (fields.hr_email), or
     * null when the snapshot predates email capture — null means "unknown",
     * not "blank in PowerSchool".
     */
    private static function snapshotEmail(?string $rawJson): ?string
    {
        if ($rawJson === null || $rawJson === '') {
            return null;
        }
        $decoded = json_decode($rawJson, true);
        $fields = is_array($decoded) ? ($decoded['fields'] ?? null) : null;
        if (!is_array($fields) || !array_key_exists('hr_email', $fields)) {
            return null;
        }
        return trim((string) ($fields['hr_email'] ?? ''));
    }

    /** Golden email is set, the snapshot email is known, and they differ. */
    private static function emailChanged(string $golden, ?string $snapshot): bool
    {
        return trim($golden) !== '' && $snapshot !== null && !self::sameValue($golden, $snapshot);
    }

    /** Enforce the spec max length: truncate over-long values and log it. */
    private function limit(string $column, string $value, string $who, array &$exceptions): string
    {
        $max = self::MAX_LENGTHS[$column];
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        $truncated = mb_substr($value, 0, $max);
        $exceptions[] = "demographics: {$who} — {$column} truncated to {$max} chars"
            . " ('{$value}' -> '{$truncated}')";
        return $truncated;
    }

    /** "Last, First (person 42)" for exception messages. */
    private function label(array $p): string
    {
        $name = trim((string) ($p['last_name'] ?? '') . ', ' . (string) ($p['first_name'] ?? ''), ', ');
        return ($name !== '' ? $name : '(unnamed)') . ' (person ' . (string) ($p['person_id'] ?? '?') . ')';
    }
}
