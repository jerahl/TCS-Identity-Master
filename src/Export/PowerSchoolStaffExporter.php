<?php

declare(strict_types=1);

namespace App\Export;

use App\Db;
use App\Sync\Sftp\SftpClient;
use PDO;
use RuntimeException;

/**
 * Exports staff changes as ONE tab-delimited file for PowerSchool's
 * AutoComm import into the Teachers view — the only import path exposed in
 * the district's PowerSchool build (no Data Import Manager) — and uploads it
 * to the district SFTP server. Column names are the exact field list the
 * district's AutoComm template accepts, including the UsersCoreFields /
 * S_USR_X extension fields (gender, dob, ALSDE ID, hire date). Users-sourced
 * fields repeat on every row; SchoolStaff-sourced fields
 * (SchoolID/Status/StaffStatus) vary per school.
 *
 * Only people who need a PowerSchool update are exported — NOT the full
 * roster. A person (active/pending) is included when they are:
 *
 *   NEW — no active person_source_id row with system='powerschool', i.e.
 *   PowerSchool has never reported them back through the nightly import.
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
 * Everyone exported — new or changed — MUST have an ALSDE ID on the golden
 * record; without one the person is held back and logged. The ID travels in
 * S_USR_X.State_StaffNumber.
 *
 * One row per exported person PER school assignment (multi-school staff
 * repeat, with identical Users fields), sorted by SchoolID — the Teachers
 * import runs per school context, so grouping makes split-by-school trivial.
 *
 * Never exported: passwords, SSN, address/phone fields, FEDETHNICITY /
 * FEDRACEDECLINE (no IDM source — omitted entirely so an update never
 * mass-blanks them), and the deprecated ETHNICITY field.
 *
 * Validation — every problem lands in the exceptions list, nothing is
 * dropped silently:
 *   - new users without an ALSDE ID are held back;
 *   - rows missing TeacherNumber, Last_Name, or First_Name are rejected;
 *   - a TeacherNumber longer than 20 chars is rejected (truncating the match
 *     key could update the wrong record);
 *   - duplicate TeacherNumbers across people are rejected;
 *   - HomeSchoolId / SchoolID values that cannot be resolved to a PowerSchool
 *     School_Number (school.ps_school_id) reject the row;
 *   - over-long non-key values are truncated to the dictionary max and logged;
 *   - unmapped person types default to StaffStatus 2 (Staff) and are logged.
 */
final class PowerSchoolStaffExporter
{
    public const EXPORT_FILE = 'ps_staff_teachers.txt';
    public const SAMPLE_FILE = 'ps_staff_teachers_sample.txt';
    public const EXCEPTIONS_FILE = 'ps_staff_exceptions.txt';

    /**
     * Output columns, in order — the exact field list the district's AutoComm
     * Teachers import accepts (including the UsersCoreFields / S_USR_X
     * extension fields it exposes).
     */
    public const HEADERS = [
        'TeacherNumber',              // Users: district staff number — the match key
        'Last_Name',                  // Users: required
        'First_Name',                 // Users: required
        'Middle_Name',                // Users
        'Email_Addr',                 // Users: max 50
        'SIF_StatePrid',              // Users: district practice — StatePrId = employee id
        'Title',                      // Users: max 40, display/sort only
        'HomeSchoolId',               // Users: School_Number of the home school
        'TeacherLoginID',             // Users: PowerTeacher login, max 20
        'UsersCoreFields.gender',     // extension: M/F initial
        'UsersCoreFields.dob',        // extension: MM/DD/YYYY
        'S_USR_X.State_StaffNumber',  // extension: the ALSDE ID
        'S_USR_X.HireDate',           // extension: MM/DD/YYYY
        'SchoolID',                   // SchoolStaff: School_Number of this assignment
        'Status',                     // SchoolStaff: 1 = Current, 2 = No longer here
        'StaffStatus',                // SchoolStaff: 0..4, see STAFF_STATUS
    ];

    /** Data-dictionary max lengths, keyed by column. */
    private const MAX_LENGTHS = [
        'TeacherNumber'  => 20,
        'Last_Name'      => 100,
        'First_Name'     => 100,
        'Middle_Name'    => 100,
        'Email_Addr'     => 50,
        'SIF_StatePrid'  => 32,
        'Title'          => 40,
        'TeacherLoginID' => 20,
    ];

    /** SchoolStaff.Status: 1 = Current, 2 = No longer here. */
    private const STATUS_CURRENT = '1';
    private const STATUS_ENDED = '2';

    /** SchoolStaff.StaffStatus: 0 Not Assigned, 1 Teacher, 2 Staff, 3 Lunch Staff, 4 Substitute. */
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
     * Build the file's rows plus the exception log and run summary.
     *
     * @return array{
     *     rows: array<int,array<string,string>>,
     *     new: array<int,string>,
     *     changed: array<int,string>,
     *     exceptions: array<int,string>,
     *     summary: array{new:int,changed:int,rows:int,exceptions:int,schools:int}
     * }
     */
    public function export(): array
    {
        $exceptions = [];
        $people = $this->selectPeople($exceptions);
        $rows = $this->rows($people, $exceptions);

        $new = array_values(array_filter($people, static fn(array $p): bool => $p['_reason'] === 'new'));
        $changed = array_values(array_filter($people, static fn(array $p): bool => $p['_reason'] === 'changed'));

        return [
            'rows' => $rows,
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
                'rows' => count($rows),
                'exceptions' => count($exceptions),
                'schools' => count(array_unique(array_column($rows, 'SchoolID'))),
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
                       p.email, p.username, p.employee_id, p.alsde_id, p.person_type,
                       p.gender, p.dob, p.hire_date,
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
                $p['_reason'] = 'new';
            } else {
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
            }

            // The ALSDE ID gate applies to EVERY exported person, new or changed.
            if (trim((string) ($p['alsde_id'] ?? '')) === '') {
                $exceptions[] = 'selection: ' . $this->label($p)
                    . " — {$p['_reason']} user without an ALSDE ID; held back"
                    . ' (an ALSDE ID is required to export to PowerSchool)';
                continue;
            }
            $people[] = $p;
        }
        return $people;
    }

    /**
     * One validated row per selected person per school assignment, keyed by
     * HEADERS and sorted by SchoolID. Ended assignments export Status 2
     * (No longer here) so transfers clear the old school; people with no
     * assignment rows fall back to their primary school. Duplicate
     * (TeacherNumber, SchoolID) pairs collapse to one row, preferring Current.
     *
     * @param array<int,array<string,mixed>> $people selectPeople() rows
     * @param array<int,string> $exceptions
     * @return array<int,array<string,string>>
     */
    private function rows(array $people, array &$exceptions): array
    {
        $assignments = $this->assignmentsByPerson();

        $byKey = [];
        $seen = [];
        $unmappedTypes = [];
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
                $exceptions[] = "export: {$who} — missing " . implode(', ', $missing) . '; rejected';
                continue;
            }
            if (mb_strlen($teacherNumber) > self::MAX_LENGTHS['TeacherNumber']) {
                $exceptions[] = "export: {$who} — TeacherNumber '{$teacherNumber}' exceeds 20 chars"
                    . ' (match key is never truncated); rejected';
                continue;
            }
            if (isset($seen[$teacherNumber])) {
                $exceptions[] = "export: {$who} — duplicate TeacherNumber '{$teacherNumber}'"
                    . " (already used by {$seen[$teacherNumber]}); rejected";
                continue;
            }
            $homeSchool = self::psSchoolId(self::clean((string) ($p['ps_school_id'] ?? '')));
            if ($homeSchool === '') {
                $exceptions[] = "export: {$who} — home school cannot be resolved to a"
                    . ' PowerSchool School_Number; rejected';
                continue;
            }
            $seen[$teacherNumber] = $who;

            $type = (string) ($p['person_type'] ?? '');
            if (!isset(self::STAFF_STATUS[$type]) && !isset($unmappedTypes[$type])) {
                $unmappedTypes[$type] = true;
                $exceptions[] = "export: unmapped person type '{$type}' (first seen on {$who})"
                    . ' — defaulted to StaffStatus ' . self::STAFF_STATUS_DEFAULT . ' (Staff)';
            }
            $staffStatus = self::STAFF_STATUS[$type] ?? self::STAFF_STATUS_DEFAULT;

            $user = [
                'TeacherNumber'  => $teacherNumber,
                'Last_Name'      => $this->limit('Last_Name', $last, $who, $exceptions),
                'First_Name'     => $this->limit('First_Name', $first, $who, $exceptions),
                'Middle_Name'    => $this->limit('Middle_Name', self::clean((string) ($p['middle_name'] ?? '')), $who, $exceptions),
                'Email_Addr'     => $this->limit('Email_Addr', self::clean((string) ($p['email'] ?? '')), $who, $exceptions),
                // District practice: the state personnel id IS the employee id.
                'SIF_StatePrid'  => $this->limit('SIF_StatePrid', $teacherNumber, $who, $exceptions),
                'Title'          => $this->limit('Title', self::clean((string) ($p['title'] ?? '')), $who, $exceptions),
                'HomeSchoolId'   => $homeSchool,
                'TeacherLoginID' => $this->limit('TeacherLoginID', self::clean((string) ($p['username'] ?? '')), $who, $exceptions),
                'UsersCoreFields.gender'    => self::genderInitial((string) ($p['gender'] ?? '')),
                'UsersCoreFields.dob'       => self::usDate((string) ($p['dob'] ?? '')),
                'S_USR_X.State_StaffNumber' => self::clean((string) ($p['alsde_id'] ?? '')),
                'S_USR_X.HireDate'          => self::usDate((string) ($p['hire_date'] ?? '')),
            ];

            // Fall back to the primary school when there are no assignment rows.
            $schools = $assignments[(int) $p['person_id']]
                ?? [['ps_school_id' => (string) ($p['ps_school_id'] ?? ''), 'end_date' => null]];
            foreach ($schools as $a) {
                $school = self::psSchoolId(self::clean((string) ($a['ps_school_id'] ?? '')));
                if ($school === '') {
                    $exceptions[] = "export: {$who} — assignment school cannot be resolved to a"
                        . ' PowerSchool School_Number; row rejected';
                    continue;
                }
                $endDate = trim((string) ($a['end_date'] ?? ''));
                $status = ($endDate !== '' && $endDate <= $this->today) ? self::STATUS_ENDED : self::STATUS_CURRENT;

                $key = "{$teacherNumber}\t{$school}";
                if (isset($byKey[$key]) && $byKey[$key]['Status'] === self::STATUS_CURRENT) {
                    continue; // already have a Current row for this person+school
                }
                $byKey[$key] = $user + [
                    'SchoolID'    => $school,
                    'Status'      => $status,
                    'StaffStatus' => $staffStatus,
                ];
            }
        }

        $rows = [];
        foreach ($byKey as $row) {
            $rows[] = array_replace(array_fill_keys(self::HEADERS, ''), $row);
        }
        usort($rows, static fn(array $x, array $y): int =>
            [$x['SchoolID'], $x['TeacherNumber']] <=> [$y['SchoolID'], $y['TeacherNumber']]);
        return $rows;
    }

    /**
     * All assignments for active/pending people, grouped by person_id.
     *
     * @return array<int,array<int,array{ps_school_id:?string,end_date:?string}>>
     */
    private function assignmentsByPerson(): array
    {
        $sql = "SELECT a.person_id, s.ps_school_id, a.end_date
                  FROM assignment a
                  JOIN person p ON p.person_id = a.person_id
                  LEFT JOIN school s ON s.school_id = a.school_id
                 WHERE p.status IN ('active','pending')
                 ORDER BY a.person_id, a.id";
        $byPerson = [];
        foreach ($this->db->query($sql)->fetchAll() as $a) {
            $byPerson[(int) $a['person_id']][] = $a;
        }
        return $byPerson;
    }

    /**
     * Render the file: tab-delimited, header row first, CRLF line endings,
     * one trailing newline, no blank rows, no quoting (values are already
     * tab/newline-free).
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
     * The 3-row sample of the file for a manual test import before the full
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
     * overwrites the last, so PowerSchool's scheduled AutoComm import can
     * point at a constant file name.
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

    /** "Male"/"female"/"M" -> "M"; empty stays empty (PowerSchool stores M/F). */
    public static function genderInitial(string $gender): string
    {
        $g = ltrim($gender);
        return $g === '' ? '' : mb_strtoupper(mb_substr($g, 0, 1));
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

    /** Enforce the dictionary max length: truncate over-long values and log it. */
    private function limit(string $column, string $value, string $who, array &$exceptions): string
    {
        $max = self::MAX_LENGTHS[$column];
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        $truncated = mb_substr($value, 0, $max);
        $exceptions[] = "export: {$who} — {$column} truncated to {$max} chars"
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
