<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use App\Service\AuditService;
use PDO;
use RuntimeException;

/**
 * ONE-TIME reconciliation: link existing AD usernames to the golden record.
 *
 * Before OneSync was authoritative, accounts already existed in AD. This imports
 * an AD export and stamps each person's current sAMAccountName as their (locked)
 * username + email, so OneSync sees them as already-provisioned instead of
 * minting new names.
 *
 * Match key: the AD `uniqueId` is TEACHERS.ID with a leading "T"
 * (e.g. T8422 -> PowerSchool source_key 8422), and TEACHERS.ID is what we key the
 * powerschool crosswalk on — so we strip the T and resolve directly, falling back
 * to the NextGen Employee ID column (TEACHERS.TeacherNumber, also "T"-prefixed).
 * Also records the AD uniqueId in person_source_id (system 'ad') for traceability.
 *
 * Same guardrails as the OneSync write-back: a locked username is never
 * overwritten with a different value; re-runs are idempotent. Writes nothing on
 * --dry-run. Runs as the MIGRATE role — a trusted one-time ops step (it also
 * writes the person_source_id 'ad' crosswalk row, beyond the write-back grants).
 *
 * Accepts ANY of these files (format auto-detected from the header row):
 *  - AD directory export: uniqueId, mail, sAMAccountName, Employee ID, ...
 *  - PowerSchool TEACHERS export (same file used for the PowerSchool import):
 *    TEACHERS.ID, TEACHERS.Email_Addr, TEACHERS.TeacherLoginID, TEACHERS.TeacherNumber
 *    (here the AD uniqueId recorded in the crosswalk is "T" + TEACHERS.ID).
 *  - Adaxes "Employee List" export: First/Last name, Email, Logon Name (UPN),
 *    Logon Name (pre-Windows 2000) (= sAMAccountName), Employee ID, Object GUID,
 *    Department, Parent (OU), Name. No PowerSchool/uniqueId key, so it matches
 *    each person by Employee ID, then Email, then username, sets + LOCKS the
 *    sAMAccountName as the username (refreshing email + UPN), and records the
 *    real objectGUID in the crosswalk.
 */
final class AdUsernameImporter
{
    private PDO $db;
    private AuditService $audit;

    /** AD directory export columns. */
    private const MAP_AD = [
        'id'          => 'uniqueId',          // "T" + TEACHERS.ID
        'mail'        => 'mail',
        'username'    => 'sAMAccountName',
        'employee_id' => 'Employee ID',
    ];

    /** PowerSchool TEACHERS export columns (same file we import for PowerSchool). */
    private const MAP_TEACHERS = [
        'id'          => 'TEACHERS.ID',       // the PS crosswalk key (no "T")
        'mail'        => 'TEACHERS.Email_Addr',
        'username'    => 'TEACHERS.TeacherLoginID',
        'employee_id' => 'TEACHERS.TeacherNumber',
    ];

    /**
     * Adaxes "Employee List" export columns. Carries the authoritative AD
     * identity: sAMAccountName (the pre-Windows-2000 logon), the real objectGUID,
     * the UPN, email and employee id. No PowerSchool/uniqueId key, so it matches
     * on employee id / email / username instead.
     */
    private const MAP_EMPLOYEE_LIST = [
        'username'    => 'Logon Name (pre-Windows 2000)',  // sAMAccountName
        'upn'         => 'Logon Name',                      // userPrincipalName
        'mail'        => 'Email',
        'employee_id' => 'Employee ID',
        'guid'        => 'Object GUID',                     // AD objectGUID
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_MIGRATE);
        $this->audit = new AuditService($this->db);
    }

    /** Pick the format from the header row: TEACHERS / AD / Employee List. */
    public static function detectFormat(array $row): string
    {
        if (array_key_exists('TEACHERS.ID', $row)) {
            return 'teachers';
        }
        if (array_key_exists('sAMAccountName', $row) || array_key_exists('uniqueId', $row)) {
            return 'ad';
        }
        // Adaxes "Employee List" export: pre-Win2000 logon / Object GUID columns.
        if (array_key_exists('Object GUID', $row) || array_key_exists('Logon Name (pre-Windows 2000)', $row)) {
            return 'employee_list';
        }
        return 'ad';
    }

    /**
     * Strip a single leading "T"/"t" prefix (both the AD uniqueId and the
     * Employee ID carry it: T14774 -> 14774). Unchanged if there's no T prefix.
     */
    public static function stripLeadingT(string $value): string
    {
        $v = trim($value);
        if ($v !== '' && ($v[0] === 'T' || $v[0] === 't')) {
            return substr($v, 1);
        }
        return $v;
    }

    /** @return array<string,mixed> summary */
    public function run(string $file, bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:import_ad_usernames';
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("AD export not found or unreadable: {$file}");
        }

        $rows = Csv::read($file);
        $format = $rows === [] ? 'ad' : self::detectFormat($rows[0]);
        $counts = ['total' => 0, 'applied' => 0, 'noop' => 0, 'conflict' => 0, 'skipped' => 0, 'no_person' => 0, 'errors' => 0];
        $outcomes = [];

        if ($format === 'employee_list') {
            $m = self::MAP_EMPLOYEE_LIST;
            foreach ($rows as $raw) {
                $counts['total']++;
                $username = trim((string) ($raw[$m['username']] ?? ''));
                $upn = trim((string) ($raw[$m['upn']] ?? ''));
                $email = trim((string) ($raw[$m['mail']] ?? ''));
                $employeeId = trim((string) ($raw[$m['employee_id']] ?? ''));
                $guid = trim((string) ($raw[$m['guid']] ?? ''));
                try {
                    $o = $this->processEmployeeListRow($username, $upn, $email, $employeeId, $guid, $dryRun, $actor);
                } catch (\Throwable $e) {
                    $counts['errors']++;
                    $outcomes[] = ['uniqueId' => $guid, 'username' => $username, 'outcome' => 'error', 'detail' => $e->getMessage()];
                    continue;
                }
                $counts[$o['key']]++;
                $outcomes[] = ['uniqueId' => $guid, 'username' => $username, 'outcome' => $o['key'], 'detail' => $o['detail']];
            }
            return ['dry_run' => $dryRun, 'format' => $format, 'counts' => $counts, 'outcomes' => $outcomes];
        }

        $map = $format === 'teachers' ? self::MAP_TEACHERS : self::MAP_AD;
        $teachers = $format === 'teachers';

        foreach ($rows as $raw) {
            $counts['total']++;
            $idField = trim((string) ($raw[$map['id']] ?? ''));
            $username = trim((string) ($raw[$map['username']] ?? ''));
            $email = trim((string) ($raw[$map['mail']] ?? ''));
            $employeeId = trim((string) ($raw[$map['employee_id']] ?? ''));
            // AD account uniqueId for the crosswalk: the raw uniqueId (AD export)
            // or "T" + TEACHERS.ID (TEACHERS export), so both record the same id.
            $adUniqueId = $teachers ? ($idField !== '' ? 'T' . $idField : '') : $idField;

            try {
                $outcome = $this->processRow($idField, $adUniqueId, $username, $email, $employeeId, $dryRun, $actor);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['uniqueId' => $adUniqueId, 'username' => $username, 'outcome' => 'error', 'detail' => $e->getMessage()];
                continue;
            }
            $counts[$outcome['key']]++;
            $outcomes[] = ['uniqueId' => $adUniqueId, 'username' => $username, 'outcome' => $outcome['key'], 'detail' => $outcome['detail']];
        }

        return ['dry_run' => $dryRun, 'format' => $format, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    /**
     * Employee List row: match the person (employee id, then email, then
     * username), set + LOCK the authoritative AD sAMAccountName as their username
     * (refreshing email + UPN), and record the real objectGUID in the crosswalk.
     * Same guardrail as the write-back: a locked username is never overwritten
     * with a different value.
     *
     * @return array{key:string,detail:string}
     */
    private function processEmployeeListRow(string $username, string $upn, string $email, string $employeeId, string $guid, bool $dryRun, string $actor): array
    {
        if ($username === '') {
            return ['key' => 'skipped', 'detail' => 'blank logon name (sAMAccountName)'];
        }

        $person = $this->findEmployeeListPerson($employeeId, $email, $username);
        if ($person === null) {
            return ['key' => 'no_person', 'detail' => "no person for employee id '{$employeeId}'"
                . ($email !== '' ? " / email '{$email}'" : '') . " / username '{$username}'"];
        }
        $pid = (int) $person['person_id'];

        $decision = WritebackImporter::decide($person['username'], (int) $person['username_locked'] === 1, $username);
        if ($decision === 'conflict') {
            return ['key' => 'conflict', 'detail' => "locked username '{$person['username']}' != '{$username}' — left unchanged"];
        }

        if ($decision === 'noop') {
            // Username already correct — still record the GUID + activate if needed.
            if (!$dryRun) {
                $this->recordAdSourceId($pid, $guid, $actor);
                $this->activateIfPending($pid, (string) $person['status'], $actor);
            }
            return ['key' => 'noop', 'detail' => "username '{$username}' already set" . ($guid !== '' ? "; GUID linked" : '')];
        }

        // decision === 'apply' (or 'skip', impossible here since username !== '')
        if ($dryRun) {
            return ['key' => 'applied', 'detail' => "would set username '{$username}'"
                . ($email !== '' ? " + email '{$email}'" : '') . ($guid !== '' ? " + GUID" : '')];
        }

        try {
            $before = ['username' => $person['username'], 'email' => $person['email'], 'username_locked' => $person['username_locked']];
            $sql = 'UPDATE person SET username = :u, username_assigned_at = CURRENT_TIMESTAMP, username_locked = 1';
            $params = [':u' => $username, ':id' => $pid];
            if ($email !== '') {
                $sql .= ', email = :e';
                $params[':e'] = $email;
            }
            if ($upn !== '') {
                $sql .= ', upn = :p';
                $params[':p'] = $upn;
            }
            $sql .= ' WHERE person_id = :id';
            $this->db->prepare($sql)->execute($params);

            $this->recordAdSourceId($pid, $guid, $actor);
            $this->audit->log('person', $pid, 'update', $before,
                ['username' => $username, 'email' => $email ?: $person['email'], 'username_locked' => 1], $actor);
            $this->audit->lifecycle($pid, 'username_assigned',
                ['summary' => "AD account {$username} linked (Employee List import) and locked."], $actor);
            $this->activateIfPending($pid, (string) $person['status'], $actor);

            return ['key' => 'applied', 'detail' => "username '{$username}' set + locked" . ($guid !== '' ? "; GUID linked" : '')];
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                return ['key' => 'conflict', 'detail' => "unique conflict applying '{$username}' (already used)"];
            }
            throw $e;
        }
    }

    /** Resolve a person by employee id (exact), then email, then username. */
    private function findEmployeeListPerson(string $employeeId, string $email, string $username): ?array
    {
        $sel = 'SELECT person_id, username, email, username_locked, status FROM person ';
        if ($employeeId !== '') {
            $stmt = $this->db->prepare($sel . "WHERE employee_id = :e AND employee_id <> '' LIMIT 1");
            $stmt->execute([':e' => $employeeId]);
            if (($row = $stmt->fetch()) !== false) {
                return $row;
            }
        }
        if ($email !== '') {
            $stmt = $this->db->prepare($sel . "WHERE email = :m AND email <> '' LIMIT 1");
            $stmt->execute([':m' => $email]);
            if (($row = $stmt->fetch()) !== false) {
                return $row;
            }
        }
        if ($username !== '') {
            $stmt = $this->db->prepare($sel . "WHERE username = :u AND username <> '' LIMIT 1");
            $stmt->execute([':u' => $username]);
            if (($row = $stmt->fetch()) !== false) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param string $idField    the PS id field (AD uniqueId "T8422" or TEACHERS.ID "8422")
     * @param string $adUniqueId the AD account id to record in the crosswalk ("T8422")
     * @return array{key:string,detail:string}
     */
    private function processRow(string $idField, string $adUniqueId, string $username, string $email, string $employeeId, bool $dryRun, string $actor): array
    {
        if ($username === '') {
            return ['key' => 'skipped', 'detail' => 'blank username'];
        }

        // PS crosswalk key = TEACHERS.ID (= AD uniqueId minus the leading "T").
        $psId = self::stripLeadingT($idField);
        $employeeId = self::stripLeadingT($employeeId);
        $person = $this->findPerson($psId, $employeeId);
        if ($person === null) {
            return ['key' => 'no_person', 'detail' => "no person for PS id '{$psId}'"
                . ($employeeId !== '' ? " / employee '{$employeeId}'" : '')];
        }

        $decision = WritebackImporter::decide($person['username'], (int) $person['username_locked'] === 1, $username);
        if ($decision === 'conflict') {
            return ['key' => 'conflict', 'detail' => "locked username '{$person['username']}' != '{$username}' — left unchanged"];
        }
        if ($decision === 'noop') {
            if (!$dryRun) {
                $this->recordAdSourceId((int) $person['person_id'], $adUniqueId, $actor);
                $this->activateIfPending((int) $person['person_id'], (string) $person['status'], $actor);
            }
            return ['key' => 'noop', 'detail' => 'already set'];
        }
        // apply
        if ($dryRun) {
            return ['key' => 'applied', 'detail' => "would set username '{$username}'" . ($email !== '' ? " + email '{$email}'" : '')];
        }

        try {
            $before = ['username' => $person['username'], 'email' => $person['email'], 'username_locked' => $person['username_locked']];
            $sql = 'UPDATE person SET username = :u, username_assigned_at = CURRENT_TIMESTAMP, username_locked = 1';
            $params = [':u' => $username, ':id' => $person['person_id']];
            if ($email !== '') {
                $sql .= ', email = :e';
                $params[':e'] = $email;
            }
            $sql .= ' WHERE person_id = :id';
            $this->db->prepare($sql)->execute($params);

            $this->recordAdSourceId((int) $person['person_id'], $adUniqueId, $actor);

            $this->audit->log('person', (int) $person['person_id'], 'update', $before,
                ['username' => $username, 'email' => $email ?: $person['email'], 'username_locked' => 1], $actor);
            $this->audit->lifecycle((int) $person['person_id'], 'username_assigned',
                ['summary' => "Existing AD username {$username} linked (one-time import) and locked."], $actor);
            $this->activateIfPending((int) $person['person_id'], (string) $person['status'], $actor);

            return ['key' => 'applied', 'detail' => "username '{$username}' set + locked"];
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                return ['key' => 'conflict', 'detail' => "unique conflict applying '{$username}' (already used)"];
            }
            throw $e;
        }
    }

    /** Resolve a person by PowerSchool crosswalk id, then by NextGen employee id. */
    private function findPerson(string $psId, string $employeeId): ?array
    {
        if ($psId !== '') {
            $stmt = $this->db->prepare(
                'SELECT p.person_id, p.username, p.email, p.username_locked, p.status
                 FROM person p JOIN person_source_id s ON s.person_id = p.person_id
                 WHERE s.system = \'powerschool\' AND s.source_key = :k LIMIT 1'
            );
            $stmt->execute([':k' => $psId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }
        if ($employeeId !== '') {
            $stmt = $this->db->prepare(
                "SELECT person_id, username, email, username_locked, status
                 FROM person WHERE employee_id = :e AND employee_id <> '' LIMIT 1"
            );
            $stmt->execute([':e' => $employeeId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }
        return null;
    }

    /** Flip a provisioned person from 'pending' to 'active' (locked username = live). */
    private function activateIfPending(int $personId, string $currentStatus, string $actor): void
    {
        if ($currentStatus !== 'pending') {
            return;
        }
        $this->db->prepare("UPDATE person SET status = 'active' WHERE person_id = :id AND status = 'pending'")
            ->execute([':id' => $personId]);
        $this->audit->log('person', $personId, 'update', ['status' => 'pending'], ['status' => 'active'], $actor);
        $this->audit->lifecycle($personId, 'enable', ['summary' => 'Activated — AD account linked (one-time import).'], $actor);
    }

    /** Record the AD uniqueId in the crosswalk (idempotent on system+source_key). */
    private function recordAdSourceId(int $personId, string $uniqueId, string $actor): void
    {
        if ($uniqueId === '') {
            return;
        }
        $this->db->prepare(
            'INSERT INTO person_source_id (person_id, system, source_key)
             VALUES (:pid, \'ad\', :k)
             ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP, is_active = 1'
        )->execute([':pid' => $personId, ':k' => $uniqueId]);
    }
}
