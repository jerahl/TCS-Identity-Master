<?php

declare(strict_types=1);

namespace App\Export;

use App\Db;
use App\Sync\Sftp\SftpClient;
use PDO;
use RuntimeException;

/**
 * Exports NEW staff (people not yet in PowerSchool) as a CSV ready for the
 * PowerSchool SIS staff import, and uploads it to the district SFTP server.
 *
 * "New" means: an active/pending person with an ALSDE ID on the golden record
 * but NO active person_source_id row with system='powerschool' — i.e. HR has
 * hired them and the ALSDE ID has been entered, but PowerSchool has never
 * reported them back through the nightly import. People without an ALSDE ID
 * are deliberately excluded (PowerSchool demographics require it); the CLI
 * surfaces them so the operator knows who is being held back.
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
     * Render the export CSV (header + one line per candidate). CRLF line ends
     * and RFC-4180 quoting, matching what PowerSchool's importer accepts.
     *
     * @param array<int,array<string,mixed>> $candidates raw candidates() rows
     */
    public static function csv(array $candidates): string
    {
        $lines = [self::csvLine(self::HEADERS)];
        foreach ($candidates as $p) {
            $lines[] = self::csvLine(array_values(self::row($p)));
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Write the CSV into $dir with a timestamped name.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array{path:string,bytes:int}
     */
    public static function writeFile(array $candidates, string $dir, ?string $fileName = null): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create export directory: {$dir}");
        }
        $fileName ??= 'ps_new_staff_' . date('Ymd_His') . '.csv';
        $path = rtrim($dir, '/') . '/' . $fileName;
        $bytes = file_put_contents($path, self::csv($candidates));
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
