<?php

declare(strict_types=1);

namespace App\Export;

use App\Db;
use PDO;
use RuntimeException;

/**
 * Builds the three fixed-name staff files PowerSchool consumes nightly from the
 * district SFTP server (bin/export_ps_staff.php):
 *
 *   ps_staff_create.txt  AutoComm full sync — creates/updates staff records.
 *                        16 positional columns (CREATE_FIELDS), NO header row:
 *                        AutoComm field mapping is positional.
 *   ps_staff_sso.txt     AutoComm minimal update — aligns LoginID /
 *                        TeacherLoginID / Email_Addr to AD for SSO. ONLY people
 *                        with an active PowerSchool source id (they exist in PS;
 *                        the AutoComm job matches on TeacherNumber and must
 *                        never create). 4 positional columns, NO header row.
 *   ps_staff_race.txt    Data Import Manager file for the TeacherRace child
 *                        table, one row per person per declared race. Header
 *                        row with data-dictionary Table.Field names, per the
 *                        existing DIM export (PowerSchoolStaffExporter).
 *
 * Population scope follows the existing DIM export: person.status IN
 * ('active','pending'). Golden-record sources per column are documented in
 * docs/powerschool-staff-autocomm.md.
 *
 * StaffStatus mapping (PowerSchool numeric codes) from person.person_type:
 *   faculty    -> 1 (Teacher)
 *   staff      -> 2 (Staff)
 *   contractor -> 2 (Staff)
 *   intern     -> 2 (Staff)
 *   sub        -> 4 (Substitute)
 *   other      -> 0 (Not Assigned)
 * IDM has no role that distinguishes 3 (Lunch Staff); it is never emitted.
 *
 * Coupling rule: FedRaceDecline in the create file is '0' ONLY when the person
 * also has at least one row in the race file. It is enforced structurally —
 * both datasets are computed from the same pass, and FedRaceDecline is derived
 * from the race rows actually emitted. IDM has no "declined to answer" flag,
 * so FedRaceDecline is never '1'; people with no usable race data get an empty
 * string plus a data-quality warning in the run report.
 */
final class PowerSchoolAutoCommExporter
{
    /**
     * Fixed deliverable filenames — CONTRACTUAL with the PowerSchool AutoComm
     * setup, which picks each file up from the SFTP server by exact name.
     * Renaming one here requires changing the AutoComm job in lockstep.
     */
    public const FILE_CREATE = 'ps_staff_create.txt';
    public const FILE_SSO = 'ps_staff_sso.txt';
    public const FILE_RACE = 'ps_staff_race.txt';

    /** Field delimiter. Tab avoids quoting problems; switch to ',' only if the AutoComm setup expects CSV. */
    public const DELIMITER = "\t";
    /** AutoComm/DIM want CRLF line endings. */
    public const EOL = "\r\n";

    /**
     * AutoComm create/full-sync field list, IN ORDER. The order is contractual:
     * the AutoComm field mapping on the PowerSchool side is positional.
     */
    public const CREATE_FIELDS = [
        'TeacherNumber',
        'Last_Name',
        'First_Name',
        'HomeSchoolId',
        'SchoolID',
        'Title',
        'S_USR_X.HireDate',
        'Sched_Gender',
        'StaffStatus',
        'Status',
        'SIF_StatePrid',
        'FedEthnicity',
        'FedRaceDecline',
        'LoginID',
        'TeacherLoginID',
        'Email_Addr',
    ];

    /** AutoComm SSO-update field list, IN ORDER (positional, like CREATE_FIELDS). */
    public const SSO_FIELDS = [
        'TeacherNumber',
        'LoginID',
        'TeacherLoginID',
        'Email_Addr',
    ];

    /** DIM race-file header row — data-dictionary Table.Field names, per the existing DIM export. */
    public const RACE_HEADERS = [
        'Users.TeacherNumber',
        'TeacherRace.RaceCd',
    ];

    /** SchoolStaff.Status: always 1 (Current) — deactivation is out of scope here. */
    private const STATUS_CURRENT = '1';

    /** person_type -> PowerSchool StaffStatus (see class doc block). */
    private const STAFF_STATUS = [
        'faculty'    => '1',
        'staff'      => '2',
        'contractor' => '2',
        'intern'     => '2',
        'sub'        => '4',
        'other'      => '0',
    ];

    /** person.ethnicity_code value that means Hispanic/Latino (ethnicity, not a race). */
    private const HISPANIC_CODE = '4';

    /**
     * IDM resolved race code (person.ethnicity_code, the ALSDE code minted by
     * ethnicity_map) -> PowerSchool district race code (Gen table, cat='Race').
     *
     * PLACEHOLDER VALUES — the right-hand side currently mirrors the ALSDE
     * codes and MUST be verified against the district's PowerSchool Gen table
     * (District Setup > Races) before the first live upload. Any race value
     * that reaches the exporter without an entry here is a HARD FAILURE: the
     * run exits non-zero and nothing is uploaded — unmapped races are never
     * silently dropped or passed through.
     *
     * '4' (Hispanic/Latino) is deliberately absent: it is a federal ethnicity,
     * not a race, and produces no TeacherRace row (it feeds FedEthnicity).
     *
     * '7' (Two or More Races): IDM stores a single resolved value per person,
     * so the individual races are unknown. If the district Gen table has no
     * multi-race code, remove this entry — affected people will then fail
     * loudly and need their races entered in PowerSchool by hand.
     */
    public const PS_RACE_MAP = [
        '1' => '1', // American Indian or Alaska Native
        '2' => '2', // Asian
        '3' => '3', // Black or African American
        '5' => '5', // White
        '6' => '6', // Native Hawaiian or Other Pacific Islander
        '7' => '7', // Two or More Races (see note above)
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect();
    }

    /**
     * Everyone in scope (active/pending, same definition as the existing DIM
     * export), with the primary school's PowerSchool number, the primary
     * assignment title, and whether PowerSchool has reported them back
     * (an active person_source_id row with system='powerschool').
     *
     * @return array<int,array<string,mixed>>
     */
    public function people(): array
    {
        $sql = "SELECT p.person_id, p.person_type, p.first_name, p.last_name,
                       p.gender, p.ethnicity_source, p.ethnicity_code, p.alsde_id,
                       p.employee_id, p.hire_date, p.email, p.username,
                       p.primary_school_id,
                       s.ps_school_id,
                       (SELECT a.title FROM assignment a
                         WHERE a.person_id = p.person_id AND a.is_primary = 1
                         ORDER BY a.id LIMIT 1) AS title,
                       CASE WHEN EXISTS (SELECT 1 FROM person_source_id psi
                                          WHERE psi.person_id = p.person_id
                                            AND psi.system = 'powerschool'
                                            AND psi.is_active = 1)
                            THEN 1 ELSE 0 END AS in_powerschool
                  FROM person p
                  LEFT JOIN school s ON s.school_id = p.primary_school_id
                 WHERE p.status IN ('active','pending')
                 ORDER BY p.last_name, p.first_name, p.person_id";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Compute all three datasets in one pass (the create file's FedRaceDecline
     * depends on the race dataset — the coupling rule — so they are never built
     * independently) plus the run report.
     *
     * @param array<int,array<string,mixed>> $people rows from people()
     * @return array{
     *   create: array<int,array<int,string>>,
     *   sso: array<int,array<int,string>>,
     *   race: array<int,array<int,string>>,
     *   report: array{
     *     errors: array<string,list<string>>,
     *     warnings: list<string>,
     *     skipped: list<array{mode:string,who:string,reason:string}>,
     *     sanitized: list<string>,
     *     coupling_flags: list<string>
     *   }
     * }
     */
    public function buildAll(array $people): array
    {
        $report = [
            'errors'         => ['create' => [], 'sso' => [], 'race' => []],
            'warnings'       => [],
            'skipped'        => [],
            'sanitized'      => [],
            'coupling_flags' => [],
        ];

        // Pass 1 — resolve each person's race rows (currently at most one: IDM
        // holds a single resolved code). Unmapped race values are hard failures.
        $raceByPerson = [];   // person_id => PS RaceCd
        foreach ($people as $p) {
            $pid = (int) $p['person_id'];
            $code = trim((string) ($p['ethnicity_code'] ?? ''));
            if ($code === '' || $code === self::HISPANIC_CODE) {
                continue;
            }
            if (!isset(self::PS_RACE_MAP[$code])) {
                $report['errors']['race'][] = sprintf(
                    'unmapped race code %s for %s — add it to PowerSchoolAutoCommExporter::PS_RACE_MAP',
                    $code, self::who($p)
                );
                continue;
            }
            $raceByPerson[$pid] = self::PS_RACE_MAP[$code];
        }

        $create = [];
        $sso = [];
        $race = [];

        foreach ($people as $p) {
            $pid = (int) $p['person_id'];
            $who = self::who($p);
            $empId = trim((string) ($p['employee_id'] ?? ''));

            // ---- create file -------------------------------------------------
            if ($empId === '') {
                // TeacherNumber is the AutoComm match key — never blank.
                $report['errors']['create'][] = "missing TeacherNumber (employee id) for {$who} — record skipped";
                $report['skipped'][] = ['mode' => 'create', 'who' => $who, 'reason' => 'no employee id (TeacherNumber match key)'];
            } elseif (trim((string) ($p['ps_school_id'] ?? '')) === '') {
                // School must resolve to a PowerSchool School_Number — fail loudly.
                $reason = $p['primary_school_id'] === null
                    ? 'no primary school on the golden record'
                    : 'primary school has no PowerSchool School_Number (school.ps_school_id)';
                $report['errors']['create'][] = "unmapped location for {$who} — {$reason}";
                $report['skipped'][] = ['mode' => 'create', 'who' => $who, 'reason' => $reason];
            } else {
                $school = trim((string) $p['ps_school_id']);
                $hasRaceRows = isset($raceByPerson[$pid]);
                if ($hasRaceRows) {
                    $fedRaceDecline = '0';
                } else {
                    // Coupling rule: never '0' without race rows in the race file.
                    // IDM has no "declined" flag, so '1' is never emitted either.
                    $fedRaceDecline = '';
                    $report['coupling_flags'][] = $who . ' — ' . self::noRaceReason($p);
                }
                $create[] = [
                    $this->clean($empId, 'TeacherNumber', $who, $report),
                    $this->clean((string) ($p['last_name'] ?? ''), 'Last_Name', $who, $report),
                    $this->clean((string) ($p['first_name'] ?? ''), 'First_Name', $who, $report),
                    $school,
                    $school,
                    $this->clean(trim((string) ($p['title'] ?? '')), 'Title', $who, $report),
                    $this->usDate((string) ($p['hire_date'] ?? ''), $who, $report),
                    $this->schedGender((string) ($p['gender'] ?? ''), $who, $report),
                    self::STAFF_STATUS[(string) ($p['person_type'] ?? '')] ?? self::STAFF_STATUS['other'],
                    self::STATUS_CURRENT,
                    $this->clean(trim((string) ($p['alsde_id'] ?? '')), 'SIF_StatePrid', $who, $report),
                    $this->fedEthnicity($p, $who, $report),
                    $fedRaceDecline,
                    $this->clean(trim((string) ($p['username'] ?? '')), 'LoginID', $who, $report),
                    $this->clean(trim((string) ($p['username'] ?? '')), 'TeacherLoginID', $who, $report),
                    $this->clean(trim((string) ($p['email'] ?? '')), 'Email_Addr', $who, $report),
                ];
            }

            // ---- sso file (only people PowerSchool already knows) ------------
            if ((int) ($p['in_powerschool'] ?? 0) === 1) {
                $username = trim((string) ($p['username'] ?? ''));
                $email = trim((string) ($p['email'] ?? ''));
                if ($empId === '') {
                    $report['errors']['sso'][] = "missing TeacherNumber (employee id) for {$who} — SSO row skipped";
                    $report['skipped'][] = ['mode' => 'sso', 'who' => $who, 'reason' => 'no employee id (TeacherNumber match key)'];
                } elseif ($username === '' || $email === '') {
                    // Not a hard failure: the person simply isn't SSO-ready yet.
                    // Exporting a blank would WIPE the LoginID/e-mail in PS.
                    $missing = $username === '' ? 'no AD username' : 'no e-mail';
                    $report['skipped'][] = ['mode' => 'sso', 'who' => $who, 'reason' => $missing . ' on the golden record — would blank the PS field'];
                } else {
                    $sso[] = [
                        $this->clean($empId, 'TeacherNumber', $who, $report),
                        $this->clean($username, 'LoginID', $who, $report),
                        $this->clean($username, 'TeacherLoginID', $who, $report),
                        $this->clean($email, 'Email_Addr', $who, $report),
                    ];
                }
            }

            // ---- race file ----------------------------------------------------
            if (isset($raceByPerson[$pid])) {
                if ($empId === '') {
                    $report['errors']['race'][] = "missing TeacherNumber (employee id) for {$who} — race row skipped";
                    $report['skipped'][] = ['mode' => 'race', 'who' => $who, 'reason' => 'no employee id (TeacherNumber match key)'];
                    // The create row (if any) claimed FedRaceDecline=0 based on
                    // this row — it cannot have one (no employee id means no
                    // create row either), so no coupling repair is needed.
                } else {
                    $race[] = [
                        $this->clean($empId, 'TeacherNumber', $who, $report),
                        $raceByPerson[$pid],
                    ];
                }
            }
        }

        return ['create' => $create, 'sso' => $sso, 'race' => $race, 'report' => $report];
    }

    // ------------------------------------------------------------------ render

    /**
     * Render rows into the file body: DELIMITER-separated, CRLF, UTF-8, with an
     * optional header row (the DIM race file has one; the AutoComm files are
     * positional and must NOT).
     *
     * @param array<int,array<int,string>> $rows
     * @param array<int,string>|null $headers
     */
    public static function render(array $rows, ?array $headers = null): string
    {
        $lines = [];
        if ($headers !== null) {
            $lines[] = implode(self::DELIMITER, $headers);
        }
        foreach ($rows as $row) {
            $lines[] = implode(self::DELIMITER, $row);
        }
        return $lines === [] ? '' : implode(self::EOL, $lines) . self::EOL;
    }

    // -------------------------------------------------------------- file paths

    /** Fixed local path for a deliverable. */
    public static function fixedPath(string $dir, string $fileName): string
    {
        return rtrim($dir, '/') . '/' . $fileName;
    }

    /** Timestamped audit copy path under <dir>/archive/. */
    public static function archivePath(string $dir, string $fileName, string $stamp): string
    {
        $base = preg_replace('/\.txt$/', '', $fileName);
        return rtrim($dir, '/') . "/archive/{$base}_{$stamp}.txt";
    }

    /**
     * Write $content to $path atomically (temp file + rename) so a reader never
     * sees a half-written file, creating parent directories as needed.
     *
     * @return int bytes written
     */
    public static function writeFileAtomic(string $path, string $content): int
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create export directory: {$dir}");
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Cannot write export file: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot move export file into place: {$path}");
        }
        return strlen($content);
    }

    /**
     * Data-row count of a previously written deliverable (basis for the
     * empty-file guard), or null if the file doesn't exist yet. $hasHeader
     * discounts the DIM header row.
     */
    public static function countDataRows(string $path, bool $hasHeader): ?int
    {
        if (!is_file($path)) {
            return null;
        }
        $content = (string) file_get_contents($path);
        if (trim($content) === '') {
            return 0;
        }
        $n = count(preg_split('/\r\n|\n/', trim($content)));
        return max(0, $hasHeader ? $n - 1 : $n);
    }

    /**
     * Empty-file guard: true when the new row count collapsed versus the
     * previous run (dropped below $minRatio of it). A broken IDM query must
     * never blank out staff data in PowerSchool.
     */
    public static function guardTrips(?int $previous, int $new, float $minRatio): bool
    {
        if ($previous === null || $previous === 0) {
            return false; // first run, or previous file was already empty
        }
        return $new < $previous * $minRatio;
    }

    /**
     * Delete archive copies older than $days. Returns the deleted file names.
     *
     * @return list<string>
     */
    public static function sweepArchive(string $dir, int $days): array
    {
        $cutoff = time() - $days * 86400;
        $deleted = [];
        foreach (glob(rtrim($dir, '/') . '/archive/ps_staff_*_*.txt') ?: [] as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                $deleted[] = basename($file);
            }
        }
        return $deleted;
    }

    // ------------------------------------------------------------ value rules

    /** "Last, First (emp 123 / #45)" — the label used in every report line. */
    private static function who(array $p): string
    {
        $emp = trim((string) ($p['employee_id'] ?? ''));
        return sprintf('%s, %s (%s#%d)',
            (string) ($p['last_name'] ?? ''), (string) ($p['first_name'] ?? ''),
            $emp !== '' ? "emp {$emp} / " : '', (int) $p['person_id']);
    }

    /** Why a person has no race rows (for the coupling-flag report line). */
    private static function noRaceReason(array $p): string
    {
        $code = trim((string) ($p['ethnicity_code'] ?? ''));
        if ($code === self::HISPANIC_CODE) {
            return 'Hispanic/Latino ethnicity only — IDM holds no separate race; FedRaceDecline left empty';
        }
        if ($code !== '') {
            return "race code {$code} is unmapped; FedRaceDecline left empty";
        }
        if (trim((string) ($p['ethnicity_source'] ?? '')) !== '') {
            return 'raw ethnicity value never resolved to a code (see ethnicity_map); FedRaceDecline left empty';
        }
        return 'no race/ethnicity data on the golden record; FedRaceDecline left empty';
    }

    /**
     * Strip tabs/newlines out of a field value (they would corrupt the
     * tab-delimited file) and log the fix in the run report.
     *
     * @param array{sanitized:list<string>} $report (by ref)
     */
    private function clean(string $value, string $field, string $who, array &$report): string
    {
        if (!preg_match('/[\t\r\n]/', $value)) {
            return $value;
        }
        $cleaned = trim((string) preg_replace('/\s+/', ' ', str_replace(["\t", "\r", "\n"], ' ', $value)));
        $report['sanitized'][] = "{$field} for {$who} contained tab/newline — sanitized to \"{$cleaned}\"";
        return $cleaned;
    }

    /** Y-m-d -> MM/DD/YYYY; unknown/unparseable -> '' (never a fake date). */
    private function usDate(string $date, string $who, array &$report): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return "{$m[2]}/{$m[3]}/{$m[1]}";
        }
        $report['warnings'][] = "unparseable hire date \"{$date}\" for {$who} — exported empty";
        return '';
    }

    /** Sched_Gender accepts M or F only; anything else -> '' + warning. */
    private function schedGender(string $gender, string $who, array &$report): string
    {
        $g = strtoupper(trim($gender));
        if ($g === '') {
            return '';
        }
        if ($g === 'M' || str_starts_with($g, 'MALE')) {
            return 'M';
        }
        if ($g === 'F' || str_starts_with($g, 'FEMALE')) {
            return 'F';
        }
        $report['warnings'][] = "gender \"{$gender}\" for {$who} is not M/F — exported empty";
        return '';
    }

    /**
     * FedEthnicity: 1 = Hispanic/Latino, 0 = not Hispanic, -1 = unknown.
     * IDM stores ONE combined race/ethnicity value, so "not Hispanic" is
     * inferred from having a resolved non-Hispanic race code.
     */
    private function fedEthnicity(array $p, string $who, array &$report): string
    {
        $code = trim((string) ($p['ethnicity_code'] ?? ''));
        if ($code === self::HISPANIC_CODE) {
            return '1';
        }
        if ($code !== '' && isset(self::PS_RACE_MAP[$code])) {
            return '0';
        }
        if (trim((string) ($p['ethnicity_source'] ?? '')) !== '' && $code === '') {
            $report['warnings'][] = 'ethnicity source "' . trim((string) $p['ethnicity_source'])
                . "\" for {$who} has no ethnicity_map entry — FedEthnicity exported as -1";
        }
        return '-1';
    }
}
