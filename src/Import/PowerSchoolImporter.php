<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use App\Matching\Matcher;
use App\Matching\MatchDecision;
use App\Matching\PdoMatchLookup;
use App\Service\AuditService;
use PDO;
use RuntimeException;

/**
 * PowerSchool ingestion from the three exports (USERS + TEACHERS + SCHOOLSTAFF).
 * PowerSchoolBundle::combine() joins them into one record per person; this matches
 * each to a golden record and applies it:
 *   - links every TEACHERS.ID to the crosswalk (system 'powerschool') so AD
 *     accounts (uniqueId "T" + TEACHERS.ID) resolve to the same person;
 *   - upserts one assignment per school (SCHOOLSTAFF.SchoolID -> school), one primary;
 *   - refreshes HR fields.
 *
 * Matching: an existing powerschool source id (any TEACHERS.ID) -> AUTO; else the
 * standard matcher (employee_id = TeacherNumber links to the NextGen person, then
 * name+DOB). Idempotent; --dry-run writes nothing. Runs as the APP role.
 */
final class PowerSchoolImporter
{
    private PDO $db;
    private Matcher $matcher;
    private PersonWriter $writer;
    /** @var string[] normalized excluded names (system accounts) */
    private array $excludeNames;

    public function __construct(?PDO $db = null, ?Matcher $matcher = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->matcher = $matcher ?? new Matcher((float) Config::get('MATCH_AUTO_THRESHOLD', '90'));
        $this->writer = new PersonWriter($this->db, new AuditService($this->db));
        $raw = (string) Config::get('IMPORT_EXCLUDE_NAMES', 'admin,lookup');
        $this->excludeNames = array_values(array_filter(
            array_map(static fn(string $s) => Matcher::norm($s), explode(',', $raw)),
            static fn(string $s) => $s !== ''
        ));
    }

    /** @return array<string,mixed> summary */
    public function run(string $usersFile, string $teachersFile, string $schoolStaffFile, bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:import_powerschool';
        foreach (['users' => $usersFile, 'teachers' => $teachersFile, 'schoolstaff' => $schoolStaffFile] as $label => $f) {
            if (!is_file($f) || !is_readable($f)) {
                throw new RuntimeException("PowerSchool {$label} file not found or unreadable: {$f}");
            }
        }

        $people = PowerSchoolBundle::combine(Csv::read($usersFile), Csv::read($teachersFile), Csv::read($schoolStaffFile));
        $normalizer = Normalizer::fromDb($this->db);
        $lookup = new PdoMatchLookup($this->db);

        $batchId = null;
        if (!$dryRun) {
            $stmt = $this->db->prepare("INSERT INTO import_batch (system, file_name, status) VALUES ('powerschool', :file, 'running')");
            $stmt->execute([':file' => basename($usersFile) . ' + teachers + schoolstaff']);
            $batchId = (int) $this->db->lastInsertId();
        }

        $counts = ['total' => 0, 'auto_match' => 0, 'new' => 0, 'needs_review' => 0, 'skipped' => 0, 'assignments' => 0, 'unmapped_school' => 0, 'errors' => 0];
        $outcomes = [];

        foreach ($people as $ps) {
            $counts['total']++;
            try {
                $row = $this->primaryRow($ps, $normalizer);
                $resolved = $this->resolve($ps, $row, $lookup);

                $action = $resolved['action'];
                $reason = $resolved['reason'];
                if (!$dryRun) {
                    [$action, $reason] = $this->apply($ps, $row, $resolved, $normalizer, $actor, $batchId, $counts);
                }
                $counts[$action]++;
                $outcomes[] = [
                    'name' => trim($ps->firstName . ' ' . $ps->lastName),
                    'source_key' => $row->sourceKey,
                    'action' => $action,
                    'schools' => count($ps->schools),
                    'reason' => $reason,
                ];
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['name' => trim($ps->firstName . ' ' . $ps->lastName), 'source_key' => '', 'action' => 'error', 'reason' => $e->getMessage()];
            }
        }

        if (!$dryRun) {
            $msg = sprintf('auto %d · new %d · review %d · skipped %d · assignments %d · unmapped-school %d · errors %d',
                $counts['auto_match'], $counts['new'], $counts['needs_review'], $counts['skipped'], $counts['assignments'], $counts['unmapped_school'], $counts['errors']);
            $status = ($counts['total'] > 0 && $counts['auto_match'] + $counts['new'] + $counts['needs_review'] === 0) ? 'failed' : 'complete';
            $this->db->prepare('UPDATE import_batch SET finished_at = CURRENT_TIMESTAMP, row_count = :n, status = :s, message = :m WHERE batch_id = :id')
                ->execute([':n' => $counts['total'], ':s' => $status, ':m' => $msg, ':id' => $batchId]);
        }

        return ['batch_id' => $batchId, 'dry_run' => $dryRun, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    /**
     * Decide what to do with a combined user: exclude, link to an existing
     * powerschool id (any TEACHERS.ID), or run the standard matcher.
     *
     * @return array{action:string,personId:?int,candidates:array,reason:string}
     */
    private function resolve(PsUser $ps, NormalizedRow $row, PdoMatchLookup $lookup): array
    {
        if (Importer::nameExcluded($ps->firstName, $ps->lastName, $this->excludeNames)) {
            return ['action' => MatchDecision::SKIPPED, 'personId' => null, 'candidates' => [], 'reason' => 'Excluded system account (name).'];
        }
        // Tier 1 over every TEACHERS.ID this user owns (idempotent re-runs).
        foreach ($ps->teacherIds as $tid) {
            $pid = $lookup->findPersonIdBySourceId('powerschool', $tid);
            if ($pid !== null) {
                return ['action' => MatchDecision::AUTO, 'personId' => $pid, 'candidates' => [], 'reason' => "existing powerschool id {$tid}"];
            }
        }
        $d = $this->matcher->match($row, $lookup);
        return ['action' => $d->action, 'personId' => $d->personId, 'candidates' => $d->candidates, 'reason' => $d->reason];
    }

    /**
     * Persist one combined user (transactional): person + all source ids + assignments.
     *
     * @return array{0:string,1:string} the effective [action, reason] — normally the
     *   resolver's, but a review row whose source already has a pending case is
     *   reported as 'skipped' rather than re-queued (idempotent re-import).
     */
    private function apply(PsUser $ps, NormalizedRow $row, array $resolved, Normalizer $norm, string $actor, ?int $batchId, array &$counts): array
    {
        $action = $resolved['action'];
        $reason = $resolved['reason'];
        $this->db->beginTransaction();
        try {
            $stagingId = $this->insertStaging($batchId, $ps, $row, self::stagingStatus($resolved['action']), $resolved['reason']);

            $personId = $resolved['personId'];
            if ($resolved['action'] === MatchDecision::NEW) {
                $personId = $this->writer->createPerson($row, $actor);
            } elseif ($resolved['action'] === MatchDecision::REVIEW) {
                // Idempotent re-import: a review row carries no source id, so the
                // tier-1 source-id match can't catch it on a re-run. If this source
                // already has an unresolved review case, re-queuing it would
                // duplicate the case in the review queue — so skip it instead.
                if (Importer::hasPendingReview($this->db, 'powerschool', $row->sourceKey, $stagingId)) {
                    $reason = 'Already pending review from an earlier import — not re-queued.';
                    $action = MatchDecision::SKIPPED;
                    $this->db->prepare("UPDATE staging_record SET match_status = 'skipped', reason = :reason WHERE id = :id")
                        ->execute([':reason' => $reason, ':id' => $stagingId]);
                } else {
                    foreach ($resolved['candidates'] as $c) {
                        $this->writer->createMatchCandidate($stagingId, $c['person_id'], $c['score'], $c['basis']);
                    }
                }
                $this->db->commit();
                return [$action, $reason];
            } elseif ($resolved['action'] === MatchDecision::SKIPPED) {
                $this->db->commit();
                return [$action, $reason];
            }

            if ($personId === null) {
                $this->db->commit();
                return [$action, $reason];
            }

            // Link every TEACHERS.ID, refresh HR, and upsert one assignment per school.
            foreach ($ps->teacherIds as $tid) {
                $this->writer->attachSourceId($personId, 'powerschool', $tid, $actor);
            }
            $this->writer->updateHrFields($personId, $row, $actor);

            foreach ($ps->schools as $s) {
                $schoolId = $norm->resolveSchool('powerschool', $s['code']);
                if ($schoolId === null) {
                    $counts['unmapped_school']++;
                    continue;
                }
                $this->writer->upsertAssignment($personId, $this->schoolRow($ps, $schoolId, $s['primary']), $actor);
                $counts['assignments']++;
            }

            $this->db->prepare('UPDATE staging_record SET matched_person_id = :pid WHERE id = :id')
                ->execute([':pid' => $personId, ':id' => $stagingId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [$action, $reason];
    }

    /** The person-level row (primary school) used for matching + create/update. */
    private function primaryRow(PsUser $ps, Normalizer $norm): NormalizedRow
    {
        $primaryCode = $ps->primarySchoolCode();
        return new NormalizedRow(
            system: 'powerschool',
            sourceKey: $ps->teacherIds[0] ?? $ps->usersDcid,
            firstName: $ps->firstName,
            lastName: $ps->lastName,
            crosswalkSystem: 'powerschool',
            middleName: $ps->middleName !== '' ? $ps->middleName : null,
            employeeId: $ps->employeeId !== '' ? $ps->employeeId : null,
            schoolCode: $primaryCode,
            schoolId: $primaryCode !== null ? $norm->resolveSchool('powerschool', $primaryCode) : null,
            title: $ps->title,
            hireDate: Normalizer::parseDate($ps->hireDate),
            endDate: Normalizer::parseDate($ps->endDate),
            isPrimary: true,
        );
    }

    /** A row for one school assignment. */
    private function schoolRow(PsUser $ps, int $schoolId, bool $primary): NormalizedRow
    {
        return new NormalizedRow(
            system: 'powerschool',
            sourceKey: $ps->teacherIds[0] ?? $ps->usersDcid,
            firstName: $ps->firstName,
            lastName: $ps->lastName,
            crosswalkSystem: 'powerschool',
            employeeId: $ps->employeeId !== '' ? $ps->employeeId : null,
            schoolId: $schoolId,
            title: $ps->title,
            hireDate: Normalizer::parseDate($ps->hireDate),
            endDate: Normalizer::parseDate($ps->endDate),
            isPrimary: $primary,
        );
    }

    private function insertStaging(?int $batchId, PsUser $ps, NormalizedRow $row, string $status, string $reason): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO staging_record
               (batch_id, system, raw_json, n_first, n_last, n_dob, n_employee_id, n_source_key, n_school_code, match_status, reason)
             VALUES (:batch, :system, :raw, :first, :last, NULL, :emp, :key, :school, :status, :reason)'
        );
        $stmt->execute([
            ':batch' => $batchId,
            ':system' => 'powerschool',
            ':raw' => json_encode([
                'usersDcid' => $ps->usersDcid, 'teacherIds' => $ps->teacherIds,
                'schools' => $ps->schools, 'employeeId' => $ps->employeeId,
            ], JSON_UNESCAPED_SLASHES),
            ':first' => $ps->firstName,
            ':last' => $ps->lastName,
            ':emp' => $ps->employeeId !== '' ? $ps->employeeId : null,
            ':key' => $row->sourceKey,
            ':school' => $ps->primarySchoolCode(),
            ':status' => $status,
            ':reason' => $reason,
        ]);
        return (int) $this->db->lastInsertId();
    }

    private static function stagingStatus(string $action): string
    {
        return match ($action) {
            MatchDecision::AUTO => 'auto_matched',
            MatchDecision::REVIEW => 'needs_review',
            MatchDecision::SKIPPED => 'skipped',
            default => 'new',
        };
    }
}
