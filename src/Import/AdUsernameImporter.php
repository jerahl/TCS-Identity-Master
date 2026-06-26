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
 * Expected headers (tab- or comma-delimited; auto-detected):
 *   uniqueId, mail, surname, givenName, sAMAccountName, Employee ID,
 *   department, title, ADTitle
 */
final class AdUsernameImporter
{
    private PDO $db;
    private AuditService $audit;

    private const MAP = [
        'uniqueId'    => 'uniqueId',
        'mail'        => 'mail',
        'username'    => 'sAMAccountName',
        'employee_id' => 'Employee ID',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_MIGRATE);
        $this->audit = new AuditService($this->db);
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
        $counts = ['total' => 0, 'applied' => 0, 'noop' => 0, 'conflict' => 0, 'skipped' => 0, 'no_person' => 0, 'errors' => 0];
        $outcomes = [];

        foreach ($rows as $raw) {
            $counts['total']++;
            $uniqueId = trim((string) ($raw[self::MAP['uniqueId']] ?? ''));
            $username = trim((string) ($raw[self::MAP['username']] ?? ''));
            $email = trim((string) ($raw[self::MAP['mail']] ?? ''));
            $employeeId = trim((string) ($raw[self::MAP['employee_id']] ?? ''));

            try {
                $outcome = $this->processRow($uniqueId, $username, $email, $employeeId, $dryRun, $actor);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['uniqueId' => $uniqueId, 'username' => $username, 'outcome' => 'error', 'detail' => $e->getMessage()];
                continue;
            }
            $counts[$outcome['key']]++;
            $outcomes[] = ['uniqueId' => $uniqueId, 'username' => $username, 'outcome' => $outcome['key'], 'detail' => $outcome['detail']];
        }

        return ['dry_run' => $dryRun, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    /** @return array{key:string,detail:string} */
    private function processRow(string $uniqueId, string $username, string $email, string $employeeId, bool $dryRun, string $actor): array
    {
        if ($username === '') {
            return ['key' => 'skipped', 'detail' => 'blank sAMAccountName'];
        }

        // AD uniqueId = "T" + TEACHERS.ID, which is our PowerSchool crosswalk key.
        $psId = self::stripLeadingT($uniqueId);
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
                $this->recordAdSourceId((int) $person['person_id'], $uniqueId, $actor);
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

            $this->recordAdSourceId((int) $person['person_id'], $uniqueId, $actor);

            $this->audit->log('person', (int) $person['person_id'], 'update', $before,
                ['username' => $username, 'email' => $email ?: $person['email'], 'username_locked' => 1], $actor);
            $this->audit->lifecycle((int) $person['person_id'], 'username_assigned',
                ['summary' => "Existing AD username {$username} linked (one-time import) and locked."], $actor);

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
                'SELECT p.person_id, p.username, p.email, p.username_locked
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
                "SELECT person_id, username, email, username_locked
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
