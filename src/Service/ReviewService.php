<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Import\ColumnMap;
use App\Import\ImportSource;
use App\Import\NormalizedRow;
use App\Import\Normalizer;
use App\Import\PersonWriter;
use PDO;
use RuntimeException;

/**
 * The review queue (hero feature). Turns pending match_candidate rows into human
 * decisions:
 *   - confirm = "same person": attach the incoming source id(s) to the existing
 *     person and fold in HR fields/assignment (the intern→employee link).
 *   - reject  = "different people": create a new pending person from the staged row.
 * Every decision is audited and resolves the case's candidates.
 */
final class ReviewService
{
    private PDO $db;
    private AuditService $audit;
    private PersonWriter $writer;
    private ?Normalizer $normalizer = null;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->audit = $audit ?? new AuditService($this->db);
        $this->writer = new PersonWriter($this->db, $this->audit);
    }

    /** Pending cases, oldest first; one entry per staged row (its top candidate). */
    public function pendingCases(): array
    {
        $sql = "SELECT mc.id AS mc_id, mc.staging_id, mc.candidate_person_id, mc.score, mc.match_basis,
                       s.n_first, s.n_last, s.system, s.n_source_key, s.loaded_at
                FROM match_candidate mc
                JOIN staging_record s ON s.id = mc.staging_id
                WHERE mc.status = 'pending'
                ORDER BY s.loaded_at ASC, mc.score DESC, mc.id ASC";
        $rows = $this->db->query($sql)->fetchAll();

        $cases = [];
        foreach ($rows as $r) {
            $sid = (int) $r['staging_id'];
            if (!isset($cases[$sid])) {
                $cases[$sid] = [
                    'staging_id'  => $sid,
                    'name'        => trim($r['n_first'] . ' ' . $r['n_last']),
                    'system'      => $r['system'],
                    'source_key'  => $r['n_source_key'],
                    'loaded_at'   => $r['loaded_at'],
                    'top_score'   => (float) $r['score'],
                    'top_basis'   => $r['match_basis'],
                    'top_person'  => (int) $r['candidate_person_id'],
                    'candidates'  => 0,
                ];
            }
            $cases[$sid]['candidates']++;
        }
        return array_values($cases);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM match_candidate WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Full detail for one case: the incoming staged row, the candidate person(s),
     * and the field-by-field comparison against the top candidate.
     */
    public function caseDetail(int $stagingId): ?array
    {
        $staging = $this->loadStaging($stagingId);
        if ($staging === null) {
            return null;
        }
        $cands = $this->db->prepare(
            "SELECT mc.id AS mc_id, mc.candidate_person_id, mc.score, mc.match_basis,
                    p.person_uuid, p.first_name, p.last_name, p.dob, p.gender, p.employee_id,
                    p.person_type, p.email, sch.name AS primary_school
             FROM match_candidate mc
             JOIN person p ON p.person_id = mc.candidate_person_id
             LEFT JOIN school sch ON sch.school_id = p.primary_school_id
             WHERE mc.staging_id = :sid AND mc.status = 'pending'
             ORDER BY mc.score DESC, mc.id ASC"
        );
        $cands->execute([':sid' => $stagingId]);
        $candidates = $cands->fetchAll();
        if ($candidates === []) {
            return null;
        }

        $row = $this->rebuildRow($staging);
        $top = $candidates[0];

        $incoming = [
            'first' => $row->firstName, 'last' => $row->lastName, 'dob' => $row->dob,
            'gender' => $row->gender, 'employee_id' => $row->employeeId,
            'type' => $row->personType, 'school' => $this->schoolName($row->schoolId, $row->schoolCode),
            'account' => '(none)',
        ];
        $candidate = [
            'first' => $top['first_name'], 'last' => $top['last_name'], 'dob' => $top['dob'],
            'gender' => $top['gender'], 'employee_id' => $top['employee_id'],
            'type' => $top['person_type'], 'school' => $top['primary_school'],
            'account' => $top['email'] ?: '(none)',
        ];

        $score = (float) $top['score'];
        $basis = (string) $top['match_basis'];

        return [
            'staging_id'  => $stagingId,
            'incoming'    => ['name' => trim($row->firstName . ' ' . $row->lastName), 'source' => $this->sourceLabel($row->system, $row->sourceKey)],
            'candidate'   => ['person_id' => (int) $top['candidate_person_id'], 'uuid' => $top['person_uuid']],
            'score'       => $score,
            'basis'       => $basis,
            'weak'        => $basis === 'name_only' || $score < 70.0,
            'rows'        => self::compareRows($incoming, $candidate),
            'others'      => array_slice($candidates, 1), // additional candidates, if any
            'loaded_at'   => $staging['loaded_at'],
        ];
    }

    /**
     * Build the aligned, field-by-field comparison. Pure + static so it's unit
     * testable. Each row: label, incoming (a), candidate (b), and a match flag
     * ('match' | 'diff' | 'info').
     *
     * @param array<string,?string> $in
     * @param array<string,?string> $cand
     * @return array<int,array{label:string,a:string,b:string,match:string}>
     */
    public static function compareRows(array $in, array $cand): array
    {
        $fields = [
            ['key' => 'first', 'label' => 'First name', 'kind' => 'cmp'],
            ['key' => 'last', 'label' => 'Last name', 'kind' => 'cmp'],
            ['key' => 'dob', 'label' => 'Date of birth', 'kind' => 'cmp'],
            ['key' => 'gender', 'label' => 'Gender', 'kind' => 'cmp'],
            ['key' => 'employee_id', 'label' => 'Employee ID', 'kind' => 'cmp'],
            ['key' => 'type', 'label' => 'Type', 'kind' => 'cmp'],
            ['key' => 'school', 'label' => 'Primary school', 'kind' => 'cmp'],
            ['key' => 'account', 'label' => 'Existing account', 'kind' => 'info'],
        ];

        $out = [];
        foreach ($fields as $f) {
            $a = trim((string) ($in[$f['key']] ?? ''));
            $b = trim((string) ($cand[$f['key']] ?? ''));
            if ($f['kind'] === 'info') {
                $match = 'info';
            } else {
                $na = mb_strtolower($a);
                $nb = mb_strtolower($b);
                $match = ($na === $nb) ? 'match' : 'diff';
            }
            $out[] = [
                'label' => $f['label'],
                'a' => $a === '' ? '—' : $a,
                'b' => $b === '' ? '—' : $b,
                'match' => $match,
            ];
        }
        return $out;
    }

    /** Confirm: same person. Attach the staged source to the existing person. */
    public function confirm(int $stagingId, int $candidatePersonId, string $actor): string
    {
        $staging = $this->loadStaging($stagingId);
        if ($staging === null) {
            throw new RuntimeException('Staged row not found.');
        }
        $row = $this->rebuildRow($staging);

        $this->db->beginTransaction();
        try {
            $this->writer->attachSourceId($candidatePersonId, $row->sourceSystem(), $row->sourceKey, $actor);
            $this->writer->updateHrFields($candidatePersonId, $row, $actor);
            $this->writer->upsertAssignment($candidatePersonId, $row, $actor);

            // Resolve candidates for this staged row.
            $this->db->prepare(
                "UPDATE match_candidate SET status = 'confirmed', decided_by = :actor, decided_at = CURRENT_TIMESTAMP
                 WHERE staging_id = :sid AND candidate_person_id = :pid AND status = 'pending'"
            )->execute([':actor' => $actor, ':sid' => $stagingId, ':pid' => $candidatePersonId]);
            $this->db->prepare(
                "UPDATE match_candidate SET status = 'rejected', decided_by = :actor, decided_at = CURRENT_TIMESTAMP
                 WHERE staging_id = :sid AND candidate_person_id <> :pid AND status = 'pending'"
            )->execute([':actor' => $actor, ':sid' => $stagingId, ':pid' => $candidatePersonId]);

            $this->db->prepare(
                "UPDATE staging_record SET match_status = 'merged', matched_person_id = :pid WHERE id = :sid"
            )->execute([':pid' => $candidatePersonId, ':sid' => $stagingId]);

            $this->audit->log('match', $stagingId, 'merge', null,
                ['staging_id' => $stagingId, 'linked_person_id' => $candidatePersonId, 'system' => $row->system, 'source_key' => $row->sourceKey], $actor);
            $this->audit->lifecycle($candidatePersonId, 'convert',
                ['summary' => "Linked incoming {$row->system} source {$row->sourceKey} to this person (review confirm)."], $actor);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->personName($candidatePersonId);
    }

    /** Reject: different people. Create a new pending person from the staged row. */
    public function reject(int $stagingId, string $actor): string
    {
        $staging = $this->loadStaging($stagingId);
        if ($staging === null) {
            throw new RuntimeException('Staged row not found.');
        }
        $row = $this->rebuildRow($staging);

        $this->db->beginTransaction();
        try {
            $pid = $this->writer->createPerson($row, $actor);
            $this->writer->attachSourceId($pid, $row->sourceSystem(), $row->sourceKey, $actor);
            $this->writer->upsertAssignment($pid, $row, $actor);

            $this->db->prepare(
                "UPDATE match_candidate SET status = 'rejected', decided_by = :actor, decided_at = CURRENT_TIMESTAMP
                 WHERE staging_id = :sid AND status = 'pending'"
            )->execute([':actor' => $actor, ':sid' => $stagingId]);

            $this->db->prepare(
                "UPDATE staging_record SET match_status = 'new', matched_person_id = :pid WHERE id = :sid"
            )->execute([':pid' => $pid, ':sid' => $stagingId]);

            $this->audit->log('match', $stagingId, 'update', null,
                ['staging_id' => $stagingId, 'decision' => 'reject', 'new_person_id' => $pid], $actor);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->personName($pid);
    }

    // ---- internals ----

    private function loadStaging(int $stagingId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM staging_record WHERE id = :id');
        $stmt->execute([':id' => $stagingId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Reconstruct a full NormalizedRow from a staged row (re-normalizing raw_json). */
    private function rebuildRow(array $staging): NormalizedRow
    {
        $system = (string) $staging['system'];
        $raw = $staging['raw_json'] ? (json_decode((string) $staging['raw_json'], true) ?: []) : [];
        $source = ImportSource::fromBatchSystem($system);

        if ($raw !== [] && $source !== null) {
            $this->normalizer ??= Normalizer::fromDb($this->db);
            return $this->normalizer->normalize(
                $raw,
                $source->batchSystem,
                ColumnMap::for($source->columnMapKey),
                $source->crosswalkSystem,
                $source->aliasSystem,
                $source->personType
            );
        }

        // Fallback: build from the normalized staging columns.
        return new NormalizedRow(
            system: $system,
            sourceKey: (string) ($staging['n_source_key'] ?? ''),
            firstName: (string) ($staging['n_first'] ?? ''),
            lastName: (string) ($staging['n_last'] ?? ''),
            dob: $staging['n_dob'] ?: null,
            employeeId: $staging['n_employee_id'] ?: null,
            schoolCode: $staging['n_school_code'] ?: null,
            raw: $raw,
        );
    }

    private function schoolName(?int $schoolId, ?string $code): string
    {
        if ($schoolId !== null) {
            $stmt = $this->db->prepare('SELECT name FROM school WHERE school_id = :id');
            $stmt->execute([':id' => $schoolId]);
            $name = $stmt->fetchColumn();
            if ($name !== false) {
                return (string) $name;
            }
        }
        return $code !== null ? "code {$code}" : '';
    }

    private function sourceLabel(string $system, string $sourceKey): string
    {
        return ucfirst($system) . ' #' . $sourceKey;
    }

    private function personName(int $personId): string
    {
        $stmt = $this->db->prepare('SELECT first_name, last_name FROM person WHERE person_id = :id');
        $stmt->execute([':id' => $personId]);
        $r = $stmt->fetch();
        return $r ? trim($r['first_name'] . ' ' . $r['last_name']) : "person #{$personId}";
    }
}
