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
 * the district's PowerSchool build (no Data Import Manager, no direct
 * USERSCOREFIELDS/S_USR_X access) — and uploads it to the district SFTP
 * server. Column names are the exact Teachers-view field names from the
 * district's data dictionary; Users-sourced fields repeat on every row,
 * SchoolStaff-sourced fields (SCHOOLID/STATUS/STAFFSTATUS) vary per school.
 *
 * Only people who need a PowerSchool update are exported — NOT the full
 * roster. A person (active/pending) is included when they are:
 *
 *   NEW — no active person_source_id row with system='powerschool', i.e.
 *   PowerSchool has never reported them back through the nightly import.
 *   New users MUST have an ALSDE ID on the golden record to be created in
 *   PowerSchool; without one they are held back and logged. (The Teachers
 *   view has no ALSDE column — the ID itself is entered in PowerSchool
 *   demographics by hand — so the requirement is enforced here as a gate.)
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
 * One row per exported person PER school assignment (multi-school staff
 * repeat, with identical Users fields), sorted by SCHOOLID — the Teachers
 * import runs per school context, so grouping makes split-by-school trivial.
 *
 * Never exported: passwords, SSN, address/phone fields, FEDETHNICITY /
 * FEDRACEDECLINE (no IDM source — omitted entirely so an update never
 * mass-blanks them), and the deprecated ETHNICITY field.
 *
 * Validation — every problem lands in the exceptions list, nothing is
 * dropped silently:
 *   - new users without an ALSDE ID are held back;
 *   - rows missing TEACHERNUMBER, LAST_NAME, or FIRST_NAME are rejected;
 *   - a TEACHERNUMBER longer than 20 chars is rejected (truncating the match
 *     key could update the wrong record);
 *   - duplicate TEACHERNUMBERs across people are rejected;
 *   - HOMESCHOOLID / SCHOOLID that cannot be resolved to a PowerSchool
 *     School_Number (school.ps_school_id) reject the row;
 *   - over-long non-key values are truncated to the dictionary max and logged;
 *   - unmapped person types default to STAFFSTATUS 2 (Staff) and are logged.
 */
final class PowerSchoolStaffExporter
{
    public const EXPORT_FILE = 'ps_staff_teachers.txt';
    public const SAMPLE_FILE = 'ps_staff_teachers_sample.txt';
    public const EXCEPTIONS_FILE = 'ps_staff_exceptions.txt';

    /**
     * Output columns, in order — exact Teachers-view field names from the
     * district data dictionary (no Table.Field prefixes; the Teachers import
     * rejects them).
     */
    public const HEADERS = [
        'TEACHERNUMBER',   // Users: district staff number — the match key
        'LAST_NAME',       // Users: required
        'FIRST_NAME',      // Users: required
        'MIDDLE_NAME',     // Users
        'EMAIL_ADDR',      // Users: max 50
        'SIF_STATEPRID',   // Users: district practice — StatePrId = employee id
        'TITLE',           // Users: max 40, display/sort only
        'HOMESCHOOLID',    // Users: School_Number of the home school
        'TEACHERLOGINID',  // Users: PowerTeacher login, max 20
        'SCHOOLID',        // SchoolStaff: School_Number of this assignment
        'STATUS',          // SchoolStaff: 1 = Current, 2 = No longer here
        'STAFFSTATUS',     // SchoolStaff: 0..4, see STAFF_STATUS
    ];

    /** Data-dictionary max lengths, keyed by column. */
    private const MAX_LENGTHS = [
        'TEACHERNUMBER'  => 20,
        'LAST_NAME'      => 100,
        'FIRST_NAME'     => 100,
        'MIDDLE_NAME'    => 100,
        'EMAIL_ADDR'     => 50,
        'SIF_STATEPRID'  => 32,
        'TITLE'          => 40,
        'TEACHERLOGINID' => 20,
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
                'schools' => count(array_unique(array_column($rows, 'SCHOOLID'))),
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
                        . ' — new user without an ALSDE ID; held back'
                        . ' (an ALSDE ID is required to create a user in PowerSchool)';
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
     * One validated row per selected person per school assignment, keyed by
     * HEADERS and sorted by SCHOOLID. Ended assignments export STATUS 2
     * (No longer here) so transfers clear the old school; people with no
     * assignment rows fall back to their primary school. Duplicate
     * (TEACHERNUMBER, SCHOOLID) pairs collapse to one row, preferring Current.
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
                ['TEACHERNUMBER' => $teacherNumber, 'LAST_NAME' => $last, 'FIRST_NAME' => $first],
                static fn(string $v): bool => $v === ''
            ));
            if ($missing !== []) {
                $exceptions[] = "export: {$who} — missing " . implode(', ', $missing) . '; rejected';
                continue;
            }
            if (mb_strlen($teacherNumber) > self::MAX_LENGTHS['TEACHERNUMBER']) {
                $exceptions[] = "export: {$who} — TEACHERNUMBER '{$teacherNumber}' exceeds 20 chars"
                    . ' (match key is never truncated); rejected';
                continue;
            }
            if (isset($seen[$teacherNumber])) {
                $exceptions[] = "export: {$who} — duplicate TEACHERNUMBER '{$teacherNumber}'"
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
                    . ' — defaulted to STAFFSTATUS ' . self::STAFF_STATUS_DEFAULT . ' (Staff)';
            }
            $staffStatus = self::STAFF_STATUS[$type] ?? self::STAFF_STATUS_DEFAULT;

            $user = [
                'TEACHERNUMBER'  => $teacherNumber,
                'LAST_NAME'      => $this->limit('LAST_NAME', $last, $who, $exceptions),
                'FIRST_NAME'     => $this->limit('FIRST_NAME', $first, $who, $exceptions),
                'MIDDLE_NAME'    => $this->limit('MIDDLE_NAME', self::clean((string) ($p['middle_name'] ?? '')), $who, $exceptions),
                'EMAIL_ADDR'     => $this->limit('EMAIL_ADDR', self::clean((string) ($p['email'] ?? '')), $who, $exceptions),
                // District practice: the state personnel id IS the employee id.
                'SIF_STATEPRID'  => $this->limit('SIF_STATEPRID', $teacherNumber, $who, $exceptions),
                'TITLE'          => $this->limit('TITLE', self::clean((string) ($p['title'] ?? '')), $who, $exceptions),
                'HOMESCHOOLID'   => $homeSchool,
                'TEACHERLOGINID' => $this->limit('TEACHERLOGINID', self::clean((string) ($p['username'] ?? '')), $who, $exceptions),
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
                if (isset($byKey[$key]) && $byKey[$key]['STATUS'] === self::STATUS_CURRENT) {
                    continue; // already have a Current row for this person+school
                }
                $byKey[$key] = $user + [
                    'SCHOOLID'    => $school,
                    'STATUS'      => $status,
                    'STAFFSTATUS' => $staffStatus,
                ];
            }
        }

        $rows = [];
        foreach ($byKey as $row) {
            $rows[] = array_replace(array_fill_keys(self::HEADERS, ''), $row);
        }
        usort($rows, static fn(array $x, array $y): int =>
            [$x['SCHOOLID'], $x['TEACHERNUMBER']] <=> [$y['SCHOOLID'], $y['TEACHERNUMBER']]);
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
