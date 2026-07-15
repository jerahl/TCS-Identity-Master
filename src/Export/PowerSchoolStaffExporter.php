<?php

declare(strict_types=1);

namespace App\Export;

use App\Db;
use App\Sync\Sftp\SftpClient;
use PDO;
use RuntimeException;

/**
 * Exports staff changes as CSVs ready for the PowerSchool SIS staff import,
 * and uploads them to the district SFTP server. Two files:
 *
 * NEW STAFF — an active/pending person with an ALSDE ID on the golden record
 * but NO active person_source_id row with system='powerschool' — i.e. HR has
 * hired them and the ALSDE ID has been entered, but PowerSchool has never
 * reported them back through the nightly import. People without an ALSDE ID
 * are deliberately excluded (PowerSchool demographics require it); the CLI
 * surfaces them so the operator knows who is being held back.
 *
 * NAME UPDATES — people already in PowerSchool whose golden-record first/last
 * name OR district email no longer matches the latest PowerSchool import
 * snapshot (the newest matched staging_record row), e.g. a marriage-related
 * last-name change made in NextGen/IDM and the username/email rename that
 * follows it. Rows are keyed by Users.TeacherNumber (= Employee ID, the
 * district's staff match key) and carry the full current name plus the current
 * email (Users.Email_Addr) and username (Users.TeacherLoginID), so a rename
 * cutover reaches PowerSchool in the same file as the name change.
 *
 * Column headers use the exact table.field names from the district's
 * PowerSchool data dictionary (/ws/schema/table metadata), so the file maps
 * 1:1 in Data Import Manager:
 *   Users.*                    core staff record (name, email, TeacherNumber)
 *   Users.SIF_StatePrid        Employee ID (district practice: StatePrId = employee id)
 *   UsersCoreFields.gender/dob staff gender + date of birth extension
 *   S_USR_X.State_StaffNumber  the ALSDE ID
 *   S_USR_X.HireDate           hire date
 *   SchoolStaff.*              school association (Status 1 = Current)
 *   TeacherRace.RaceCd         resolved ALSDE race code
 */
final class PowerSchoolStaffExporter
{
    /** SchoolStaff.Status: 1 = Current. */
    private const STAFF_CURRENT = '1';
    /** SchoolStaff.StaffStatus: 1 = Teacher, 2 = Staff. */
    private const STAFF_STATUS = ['faculty' => '1', 'staff' => '2'];

    /** CSV headers, in output order — exact data-dictionary table.field names. */
    public const HEADERS = [
        'Users.Last_Name',
        'Users.First_Name',
        'Users.Middle_Name',
        'Users.Email_Addr',
        'Users.TeacherNumber',
        'Users.SIF_StatePrid',
        'Users.Title',
        'Users.HomeSchoolId',
        'Users.TeacherLoginID',
        'UsersCoreFields.gender',
        'UsersCoreFields.dob',
        'S_USR_X.State_StaffNumber',
        'S_USR_X.HireDate',
        'SchoolStaff.SchoolID',
        'SchoolStaff.Status',
        'SchoolStaff.StaffStatus',
        'TeacherRace.RaceCd',
    ];

    /** Name-update CSV headers: match key first, then the current name + login. */
    public const UPDATE_HEADERS = [
        'Users.TeacherNumber',
        'Users.First_Name',
        'Users.Middle_Name',
        'Users.Last_Name',
        'Users.Email_Addr',
        'Users.TeacherLoginID',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect();
    }

    /**
     * People ready to be created in PowerSchool: active/pending, ALSDE ID set,
     * no active PowerSchool source id yet.
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates(): array
    {
        $sql = "SELECT p.person_id, p.person_type, p.first_name, p.middle_name, p.last_name,
                       p.dob, p.gender, p.ethnicity_code, p.alsde_id, p.employee_id,
                       p.hire_date, p.email, p.username,
                       s.ps_school_id,
                       (SELECT a.title FROM assignment a
                         WHERE a.person_id = p.person_id AND a.is_primary = 1
                         ORDER BY a.id LIMIT 1) AS title
                  FROM person p
                  LEFT JOIN school s ON s.school_id = p.primary_school_id
                 WHERE p.status IN ('active','pending')
                   AND p.alsde_id IS NOT NULL AND TRIM(p.alsde_id) <> ''
                   AND NOT EXISTS (SELECT 1 FROM person_source_id psi
                                    WHERE psi.person_id = p.person_id
                                      AND psi.system = 'powerschool'
                                      AND psi.is_active = 1)
                 ORDER BY p.last_name, p.first_name, p.person_id";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * People who WOULD be exported but are missing the ALSDE ID — reported by
     * the CLI so held-back hires are visible, never silently dropped.
     *
     * @return array<int,array<string,mixed>>
     */
    public function missingAlsdeId(): array
    {
        $sql = "SELECT p.person_id, p.first_name, p.last_name, p.employee_id
                  FROM person p
                 WHERE p.status IN ('active','pending')
                   AND (p.alsde_id IS NULL OR TRIM(p.alsde_id) = '')
                   AND NOT EXISTS (SELECT 1 FROM person_source_id psi
                                    WHERE psi.person_id = p.person_id
                                      AND psi.system = 'powerschool'
                                      AND psi.is_active = 1)
                 ORDER BY p.last_name, p.first_name, p.person_id";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * People already in PowerSchool whose golden-record name OR district email
     * differs from the latest PowerSchool import snapshot — the rows for the
     * name-update CSV. Name comparison is on first + last (case-insensitive);
     * middle name isn't in the snapshot, but the exported row always carries
     * the full current name, email, and username. The email comparison only
     * fires when the golden email is set AND the snapshot actually recorded a
     * PowerSchool email (raw_json fields.hr_email) — older snapshots without
     * it never trigger a false update. People with no snapshot yet are skipped
     * (nothing to compare), and people without an employee id land in `held`
     * (no match key to update by).
     *
     * @return array{updates:array<int,array<string,mixed>>,held:array<int,array<string,mixed>>}
     */
    public function nameUpdates(): array
    {
        $sql = "SELECT p.person_id, p.first_name, p.middle_name, p.last_name, p.employee_id,
                       p.email, p.username,
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
                 WHERE p.status IN ('active','pending')
                   AND EXISTS (SELECT 1 FROM person_source_id psi
                                WHERE psi.person_id = p.person_id
                                  AND psi.system = 'powerschool'
                                  AND psi.is_active = 1)
                 ORDER BY p.last_name, p.first_name, p.person_id";

        $updates = [];
        $held = [];
        foreach ($this->db->query($sql)->fetchAll() as $p) {
            $psFirst = trim((string) ($p['ps_first'] ?? ''));
            $psLast = trim((string) ($p['ps_last'] ?? ''));
            if ($psFirst === '' && $psLast === '') {
                continue; // never snapshotted — nothing to compare against
            }
            $p['ps_email'] = self::snapshotEmail($p['ps_raw'] ?? null);
            unset($p['ps_raw']);
            $nameChanged = !self::sameName((string) $p['first_name'], $psFirst)
                || !self::sameName((string) $p['last_name'], $psLast);
            $emailChanged = self::emailChanged((string) ($p['email'] ?? ''), $p['ps_email']);
            if (!$nameChanged && !$emailChanged) {
                continue;
            }
            if (trim((string) ($p['employee_id'] ?? '')) === '') {
                $held[] = $p;
                continue;
            }
            $updates[] = $p;
        }
        return ['updates' => $updates, 'held' => $held];
    }

    /**
     * Project one candidate onto the CSV columns (keyed by HEADERS).
     *
     * @param array<string,mixed> $p
     * @return array<string,string>
     */
    public static function row(array $p): array
    {
        $school = trim((string) ($p['ps_school_id'] ?? ''));
        return [
            'Users.Last_Name'           => trim((string) ($p['last_name'] ?? '')),
            'Users.First_Name'          => trim((string) ($p['first_name'] ?? '')),
            'Users.Middle_Name'         => trim((string) ($p['middle_name'] ?? '')),
            'Users.Email_Addr'          => trim((string) ($p['email'] ?? '')),
            'Users.TeacherNumber'       => trim((string) ($p['employee_id'] ?? '')),
            'Users.SIF_StatePrid'       => trim((string) ($p['employee_id'] ?? '')),
            'Users.Title'               => trim((string) ($p['title'] ?? '')),
            'Users.HomeSchoolId'        => $school,
            'Users.TeacherLoginID'      => trim((string) ($p['username'] ?? '')),
            'UsersCoreFields.gender'    => self::genderInitial((string) ($p['gender'] ?? '')),
            'UsersCoreFields.dob'       => self::usDate((string) ($p['dob'] ?? '')),
            'S_USR_X.State_StaffNumber' => trim((string) ($p['alsde_id'] ?? '')),
            'S_USR_X.HireDate'          => self::usDate((string) ($p['hire_date'] ?? '')),
            'SchoolStaff.SchoolID'      => $school,
            'SchoolStaff.Status'        => self::STAFF_CURRENT,
            'SchoolStaff.StaffStatus'   => self::STAFF_STATUS[(string) ($p['person_type'] ?? '')] ?? self::STAFF_STATUS['staff'],
            'TeacherRace.RaceCd'        => trim((string) ($p['ethnicity_code'] ?? '')),
        ];
    }

    /**
     * Project one name-update row onto the update CSV columns (UPDATE_HEADERS).
     *
     * @param array<string,mixed> $p
     * @return array<string,string>
     */
    public static function updateRow(array $p): array
    {
        return [
            'Users.TeacherNumber'  => trim((string) ($p['employee_id'] ?? '')),
            'Users.First_Name'     => trim((string) ($p['first_name'] ?? '')),
            'Users.Middle_Name'    => trim((string) ($p['middle_name'] ?? '')),
            'Users.Last_Name'      => trim((string) ($p['last_name'] ?? '')),
            'Users.Email_Addr'     => trim((string) ($p['email'] ?? '')),
            'Users.TeacherLoginID' => trim((string) ($p['username'] ?? '')),
        ];
    }

    /**
     * Render the new-staff CSV (header + one line per candidate).
     *
     * @param array<int,array<string,mixed>> $candidates raw candidates() rows
     */
    public static function csv(array $candidates): string
    {
        return self::render(self::HEADERS, array_map(self::row(...), $candidates));
    }

    /**
     * Render the name-update CSV (header + one line per changed person).
     *
     * @param array<int,array<string,mixed>> $updates nameUpdates()['updates'] rows
     */
    public static function updatesCsv(array $updates): string
    {
        return self::render(self::UPDATE_HEADERS, array_map(self::updateRow(...), $updates));
    }

    /**
     * Write a rendered CSV into $dir. CRLF line ends and RFC-4180 quoting,
     * matching what PowerSchool's importer accepts.
     *
     * @return array{path:string,bytes:int}
     */
    public static function writeFile(string $csv, string $dir, string $fileName): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create export directory: {$dir}");
        }
        $path = rtrim($dir, '/') . '/' . $fileName;
        $bytes = file_put_contents($path, $csv);
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

    /** Case-insensitive name equality (mirrors FieldMap::valuesEqual's default). */
    private static function sameName(string $a, string $b): bool
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
        return trim($golden) !== '' && $snapshot !== null && !self::sameName($golden, $snapshot);
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,array<string,string>> $rows keyed by $headers
     */
    private static function render(array $headers, array $rows): string
    {
        $lines = [self::csvLine($headers)];
        foreach ($rows as $row) {
            $lines[] = self::csvLine(array_values($row));
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param array<int,string> $values */
    private static function csvLine(array $values): string
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = preg_match('/[",\r\n]/', $v)
                ? '"' . str_replace('"', '""', $v) . '"'
                : $v;
        }
        return implode(',', $out);
    }
}
