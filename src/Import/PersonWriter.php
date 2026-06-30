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
