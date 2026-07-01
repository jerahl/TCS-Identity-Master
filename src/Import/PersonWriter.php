<?php

declare(strict_types=1);

namespace App\Import;

use App\Service\AuditService;
use App\Support\Uuid;
use PDO;

/**
 * Applies a match decision to the database: create/update the golden record,
 * maintain the source-ID crosswalk and assignments, or queue a match candidate.
 * Every mutation is audited (audit_log) and, where it affects a person's story,
 * recorded as a lifecycle_event. Importers never touch username/email/locked —
 * those belong to OneSync.
 */
final class PersonWriter
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuditService $audit,
    ) {
    }

    /** Create a new pending person from an incoming row. Returns person_id. */
    public function createPerson(NormalizedRow $row, string $actor): int
    {
        $uuid = Uuid::v4();
        $stmt = $this->db->prepare(
            'INSERT INTO person
               (person_uuid, person_type, status, first_name, middle_name, last_name, preferred_name,
                dob, gender, ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id,
                hire_date, position_start_date, end_date,
                hr_email, position_number, cctr_description,
                phone, address1, address2, city, state_code, zip_code,
                source_of_record, created_by, updated_by)
             VALUES
               (:uuid, :type, :status, :first, :middle, :last, :preferred,
                :dob, :gender, :eth_src, :eth_code, :alsde, :emp, :school_id,
                :hire, :pos_start, :end,
                :hr_email, :pos_num, :cctr,
                :phone, :addr1, :addr2, :city, :state, :zip,
                :sor, :created_by, :updated_by)'
        );
        $stmt->execute([
            ':uuid' => $uuid,
            ':type' => $row->personType ?? 'staff',
            ':status' => 'pending',
            ':first' => $row->firstName,
            ':middle' => $row->middleName,
            ':last' => $row->lastName,
            ':preferred' => $row->preferredName,
            ':dob' => $row->dob,
            ':gender' => $row->gender,
            ':eth_src' => $row->ethnicitySource,
            ':eth_code' => $row->ethnicityCode,
            ':alsde' => $row->alsdeId,
            ':emp' => $row->employeeId,
            ':school_id' => $row->schoolId,
            ':hire' => $row->hireDate,
            ':pos_start' => $row->positionStartDate,
            ':end' => $row->endDate,
            ':hr_email' => $row->hrEmail,
            ':pos_num' => $row->positionNumber,
            ':cctr' => $row->cctrDescription,
            ':phone' => $row->phone,
            ':addr1' => $row->address1,
            ':addr2' => $row->address2,
            ':city' => $row->city,
            ':state' => $row->stateCode,
            ':zip' => $row->zipCode,
            ':sor' => self::sourceOfRecord($row->system),
            ':created_by' => $actor,
            ':updated_by' => $actor,
        ]);
        $personId = (int) $this->db->lastInsertId();

        $this->audit->log('person', $personId, 'insert', null, $this->snapshot($personId), $actor);
        $this->audit->lifecycle($personId, 'create', ['summary' => "Created from {$row->system} feed (source {$row->sourceKey})"], $actor);

        return $personId;
    }

    /** Ensure the (system, source_key) crosswalk points at this person. */
    public function attachSourceId(int $personId, string $system, string $sourceKey, string $actor): void
    {
        $before = $this->findSourceId($system, $sourceKey);
        $stmt = $this->db->prepare(
            'INSERT INTO person_source_id (person_id, system, source_key, is_active, last_seen)
             VALUES (:pid, :system, :key, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), is_active = 1, last_seen = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':pid' => $personId, ':system' => $system, ':key' => $sourceKey]);

        if ($before === null) {
            $this->audit->log('source_id', (int) $this->db->lastInsertId(), 'insert', null,
                ['person_id' => $personId, 'system' => $system, 'source_key' => $sourceKey], $actor);
        }
    }

    /**
     * Drop-out tracking for a full feed. Flags the `$system` crosswalk IDs that
     * are currently active but were NOT present in this run (`$seenKeys`) as
     * inactive — the person is no longer in that source. Mirrors the student
     * drop-out logic (StudentImporter), for staff.
     *
     * It does NOT change person.status: leaving the feed is not, by itself, a
     * disable — that stays a human decision. Deactivating the crosswalk id is what
     * makes the person show up in the dashboard "Not in NextGen — past exit date"
     * review panel (DashboardService::disableCandidates). Each change is audited
     * and put on the person's timeline.
     *
     * Safety valve: a truncated/partial feed would otherwise mark real employees
     * as departed. When the active population is at least `$guardMinActive` and the
     * share that would be deactivated exceeds `$maxRatio`, the step is BLOCKED
     * (nothing is written) so an operator can investigate. Set `$apply` false to
     * compute the counts without writing (dry-run).
     *
     * @param array<string,bool> $seenKeys source keys present in this run (as keys)
     * @return array{active:int,candidates:int,deactivated:int,blocked:bool}
     */
    public function deactivateMissingSourceIds(
        string $system,
        array $seenKeys,
        string $actor,
        bool $apply = true,
        float $maxRatio = 0.2,
        int $guardMinActive = 20
    ): array {
        $rows = $this->db->prepare(
            'SELECT id, person_id, source_key FROM person_source_id WHERE system = :s AND is_active = 1'
        );
        $rows->execute([':s' => $system]);
        $all = $rows->fetchAll();

        $stale = [];
        foreach ($all as $r) {
            if (!isset($seenKeys[(string) $r['source_key']])) {
                $stale[] = $r;
            }
        }

        $active = count($all);
        $candidates = count($stale);
        $result = ['active' => $active, 'candidates' => $candidates, 'deactivated' => 0, 'blocked' => false];

        if ($candidates === 0) {
            return $result;
        }
        // Guard against a partial feed nuking everyone.
        if ($active >= $guardMinActive && ($candidates / $active) > $maxRatio) {
            $result['blocked'] = true;
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        $update = $this->db->prepare('UPDATE person_source_id SET is_active = 0 WHERE id = :id');
        foreach ($stale as $r) {
            $update->execute([':id' => (int) $r['id']]);
            $this->audit->log('source_id', (int) $r['id'], 'update',
                ['is_active' => 1],
                ['is_active' => 0, 'source_key' => $r['source_key'], 'reason' => "absent from {$system} feed"],
                $actor);
            $this->audit->lifecycle((int) $r['person_id'], 'update',
                ['summary' => "Dropped from the {$system} feed — {$system} crosswalk id deactivated (review to disable)."],
                $actor);
        }
        $result['deactivated'] = $candidates;
        return $result;
    }

    /**
     * Backfill a person's golden record from a matched AD account (live
     * verification): record the objectGUID in the crosswalk and fill the
     * username (set + LOCKED), email and UPN — but ONLY where the golden record
     * is currently empty, so an existing value is never overwritten. Setting a
     * username activates a pending person. Idempotent: a fully-populated record
     * yields no writes. Unique clashes (username/email already used) leave the
     * golden record untouched; the GUID link still stands. Returns change notes.
     *
     * @param array{guid?:?string, username?:?string, email?:?string, upn?:?string} $ad
     * @return list<string>
     */
    public function linkAdAccount(int $personId, array $ad, string $actor): array
    {
        $stmt = $this->db->prepare('SELECT username, email, upn, status FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $p = $stmt->fetch();
        if ($p === false) {
            return [];
        }

        $username = trim((string) ($ad['username'] ?? ''));
        $email    = trim((string) ($ad['email'] ?? ''));
        $upn      = trim((string) ($ad['upn'] ?? ''));
        $guid     = trim((string) ($ad['guid'] ?? ''));
        $notes = [];

        // The stable link first (idempotent; audited only when new).
        if ($guid !== '') {
            $this->attachSourceId($personId, 'ad', $guid, $actor);
        }

        $sets = [];
        $params = [':id' => $personId];
        $before = [];
        $after = [];
        $setUsername = false;

        if ($username !== '' && trim((string) $p['username']) === '') {
            $sets[] = 'username = :u';
            $sets[] = 'username_assigned_at = CURRENT_TIMESTAMP';
            $sets[] = 'username_locked = 1';
            $params[':u'] = $username;
            $before['username'] = $p['username'];
            $after['username'] = $username;
            $notes[] = "username set to {$username} (locked)";
            $setUsername = true;
        }
        if ($email !== '' && trim((string) $p['email']) === '') {
            $sets[] = 'email = :e';
            $params[':e'] = $email;
            $before['email'] = $p['email'];
            $after['email'] = $email;
            $notes[] = "email set to {$email}";
        }
        if ($upn !== '' && trim((string) $p['upn']) === '') {
            $sets[] = 'upn = :pn';
            $params[':pn'] = $upn;
            $before['upn'] = $p['upn'];
            $after['upn'] = $upn;
            $notes[] = "UPN set to {$upn}";
        }

        if ($sets !== []) {
            try {
                $this->db->prepare('UPDATE person SET ' . implode(', ', $sets) . ' WHERE person_id = :id')->execute($params);
                $this->audit->log('person', $personId, 'update', $before, $after, $actor);
            } catch (\PDOException $e) {
                if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                    // username/email already used by another record — leave the
                    // golden record as-is; the GUID link above still stands.
                    return ['AD username/email already in use by another record — golden record left unchanged'];
                }
                throw $e;
            }
        }

        if ($setUsername && ($p['status'] ?? '') === 'pending') {
            $this->db->prepare("UPDATE person SET status = 'active' WHERE person_id = :id AND status = 'pending'")
                ->execute([':id' => $personId]);
            $this->audit->log('person', $personId, 'update', ['status' => 'pending'], ['status' => 'active'], $actor);
            $notes[] = 'activated';
        }

        if ($notes !== []) {
            $this->audit->lifecycle($personId, 'username_assigned',
                ['summary' => 'AD account linked via live verification: ' . implode('; ', $notes) . '.'], $actor);
        }

        return $notes;
    }

    /**
     * Update HR-owned fields on an existing person from an incoming row. Only
     * fields the feed actually provides are written (never blank out existing
     * data); username/email/status are left untouched. Returns true if changed.
     */
    public function updateHrFields(int $personId, NormalizedRow $row, string $actor): bool
    {
        $before = $this->snapshot($personId);
        if ($before === null) {
            return false;
        }

        // logical column => incoming value (null = leave as-is unless required).
        $candidates = [
            'first_name' => $row->firstName,
            'last_name' => $row->lastName,
            'middle_name' => $row->middleName,
            'preferred_name' => $row->preferredName,
            'dob' => $row->dob,
            'gender' => $row->gender,
            'ethnicity_source' => $row->ethnicitySource,
            'ethnicity_code' => $row->ethnicityCode,
            'alsde_id' => $row->alsdeId,
            'employee_id' => $row->employeeId,
            'primary_school_id' => $row->schoolId,
            'hire_date' => $row->hireDate,
            'position_start_date' => $row->positionStartDate,
            'end_date' => $row->endDate,
            'hr_email' => $row->hrEmail,
            'position_number' => $row->positionNumber,
            'cctr_description' => $row->cctrDescription,
            'phone' => $row->phone,
            'address1' => $row->address1,
            'address2' => $row->address2,
            'city' => $row->city,
            'state_code' => $row->stateCode,
            'zip_code' => $row->zipCode,
            'person_type' => $row->personType,
        ];
        $required = ['first_name', 'last_name'];

        $set = [];
        $params = [];
        foreach ($candidates as $col => $val) {
            if ($val === null && !in_array($col, $required, true)) {
                continue;
            }
            if ((string) ($before[$col] ?? '') === (string) ($val ?? '')) {
                continue; // unchanged
            }
            $set[] = "{$col} = :{$col}";
            $params[":{$col}"] = $val;
        }
        if ($set === []) {
            return false;
        }

        $params[':id'] = $personId;
        $params[':actor'] = $actor;
        $sql = 'UPDATE person SET ' . implode(', ', $set) . ', updated_by = :actor WHERE person_id = :id';
        $this->db->prepare($sql)->execute($params);

        $after = $this->snapshot($personId);
        $this->audit->log('person', $personId, 'update', $before, $after, $actor);
        $this->audit->lifecycle($personId, 'update', ['summary' => 'Demographics updated from ' . $row->system . ' feed'], $actor);
        return true;
    }

    /**
     * Update human-owned profile fields from the dashboard's Edit form
     * (demographics, primary location, status, notes). Never touches
     * username/email/upn/username_locked — those belong to OneSync. Audits
     * before/after and emits a lifecycle event (status-aware). Returns changed.
     *
     * @param array<string,?string> $fields whitelisted; only present keys are written
     */
    public function updateProfile(int $personId, array $fields, string $actor): bool
    {
        $before = $this->profileSnapshot($personId);
        if ($before === null) {
            return false;
        }

        $set = [];
        $params = [];
        foreach (array_keys($before) as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "{$col} = :{$col}";
                $params[":{$col}"] = ($fields[$col] === '' ? null : $fields[$col]);
            }
        }
        if ($set === []) {
            return false;
        }
        $params[':id'] = $personId;
        $params[':actor'] = $actor;
        $this->db->prepare('UPDATE person SET ' . implode(', ', $set) . ', updated_by = :actor WHERE person_id = :id')
            ->execute($params);

        $after = $this->profileSnapshot($personId);
        $this->audit->log('person', $personId, 'update', $before, $after, $actor);

        $newStatus = (string) ($fields['status'] ?? $before['status']);
        $event = self::statusEventType((string) $before['status'], $newStatus);
        $changed = [];
        foreach ((array) $after as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        $summary = $changed === [] ? 'Record edited' : 'Edited ' . implode(', ', $changed);
        $this->audit->lifecycle($personId, $event, ['summary' => $summary], $actor);

        return true;
    }

    /** Lifecycle event type for a status transition. */
    public static function statusEventType(string $old, string $new): string
    {
        if ($old === $new) {
            return 'update';
        }
        return match ($new) {
            'disabled'   => 'disable',
            'terminated' => 'terminate',
            'active'     => 'enable',
            default      => 'update',
        };
    }

    private function profileSnapshot(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_type, status, first_name, middle_name, last_name, preferred_name, dob, gender,
                    ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id, notes
             FROM person WHERE person_id = :id'
        );
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Insert or update the person's assignment for this row's school/source, and
     * keep exactly one primary. No-op if the row has no resolved school.
     */
    public function upsertAssignment(int $personId, NormalizedRow $row, string $actor): void
    {
        if ($row->schoolId === null) {
            return;
        }
        $source = self::assignmentSource($row->system);

        $find = $this->db->prepare(
            'SELECT id FROM assignment WHERE person_id = :pid AND school_id = :sid AND source = :src LIMIT 1'
        );
        $find->execute([':pid' => $personId, ':sid' => $row->schoolId, ':src' => $source]);
        $existingId = $find->fetchColumn();

        if ($existingId !== false) {
            $this->db->prepare(
                'UPDATE assignment SET title = :title, job_code = :jc, fte = :fte, is_primary = :pri,
                        effective_date = :eff, end_date = :end WHERE id = :id'
            )->execute([
                ':title' => $row->title, ':jc' => $row->jobCode, ':fte' => $row->fte,
                ':pri' => $row->isPrimary ? 1 : 0, ':eff' => $row->hireDate, ':end' => $row->endDate,
                ':id' => (int) $existingId,
            ]);
            $assignmentId = (int) $existingId;
        } else {
            $this->db->prepare(
                'INSERT INTO assignment (person_id, school_id, title, job_code, fte, is_primary, effective_date, end_date, source)
                 VALUES (:pid, :sid, :title, :jc, :fte, :pri, :eff, :end, :src)'
            )->execute([
                ':pid' => $personId, ':sid' => $row->schoolId, ':title' => $row->title, ':jc' => $row->jobCode,
                ':fte' => $row->fte, ':pri' => $row->isPrimary ? 1 : 0, ':eff' => $row->hireDate,
                ':end' => $row->endDate, ':src' => $source,
            ]);
            $assignmentId = (int) $this->db->lastInsertId();
            $this->audit->log('assignment', $assignmentId, 'insert', null,
                ['person_id' => $personId, 'school_id' => $row->schoolId, 'source' => $source], $actor);
        }

        // Maintain a single primary + the person's primary_school_id.
        if ($row->isPrimary) {
            $this->db->prepare('UPDATE assignment SET is_primary = 0 WHERE person_id = :pid AND id <> :id')
                ->execute([':pid' => $personId, ':id' => $assignmentId]);
            $this->db->prepare('UPDATE person SET primary_school_id = :sid WHERE person_id = :pid')
                ->execute([':sid' => $row->schoolId, ':pid' => $personId]);
        }
    }

    /** Queue a candidate for human review (no person change). */
    public function createMatchCandidate(int $stagingId, int $candidatePersonId, float $score, string $basis): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO match_candidate (staging_id, candidate_person_id, score, match_basis, status)
             VALUES (:sid, :pid, :score, :basis, :status)'
        );
        $stmt->execute([
            ':sid' => $stagingId, ':pid' => $candidatePersonId,
            ':score' => $score, ':basis' => $basis, ':status' => 'pending',
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function snapshot(int $personId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_type, status, first_name, middle_name, last_name, preferred_name, dob, gender,
                    ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id,
                    hire_date, position_start_date, end_date,
                    hr_email, position_number, cctr_description,
                    phone, address1, address2, city, state_code, zip_code
             FROM person WHERE person_id = :id'
        );
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function findSourceId(string $system, string $sourceKey): ?array
    {
        $stmt = $this->db->prepare('SELECT id, person_id FROM person_source_id WHERE system = :s AND source_key = :k');
        $stmt->execute([':s' => $system, ':k' => $sourceKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private const FEED_SYSTEMS = ['nextgen', 'powerschool', 'intern', 'sub', 'contractor'];

    private static function sourceOfRecord(string $system): string
    {
        return in_array($system, self::FEED_SYSTEMS, true) ? $system : 'manual';
    }

    private static function assignmentSource(string $system): string
    {
        return in_array($system, self::FEED_SYSTEMS, true) ? $system : 'manual';
    }
}
