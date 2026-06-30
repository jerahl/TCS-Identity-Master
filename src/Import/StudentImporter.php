<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use App\Support\Uuid;
use PDO;

/**
 * Students passthrough to OneSync.
 *
 * Students are NOT identity-matched like staff: there is no crosswalk, no review
 * queue and no golden record. We pull the active enrollments from PowerSchool
 * (StudentOdbcReader) and stage them verbatim in the `student` table so OneSync
 * can read them from v_onesync_student_source — the student equivalent of how it
 * reads v_onesync_source for staff. The web app only shows the status of the run.
 *
 * The DCID is PowerSchool's stable internal key, so it's the upsert key:
 *   - a DCID we've not seen before is inserted (with a freshly minted uuid);
 *   - a DCID we have is updated in place (uuid preserved → OneSync uniqueId is stable);
 *   - a DCID that was active before but is absent from this pull is flagged
 *     is_active = 0 (so OneSync disables, never orphans) — never deleted.
 *
 * Idempotent; --dry-run reads but writes nothing. Runs as the APP role.
 */
final class StudentImporter
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
    }

    /**
     * Pull students directly from PowerSchool over ODBC and stage them.
     *
     * @return array<string,mixed> summary
     */
    public function runFromOdbc(bool $dryRun = false, ?StudentOdbcReader $reader = null): array
    {
        $reader ??= new StudentOdbcReader();
        return $this->importRows($reader->read(), $dryRun);
    }

    /**
     * Stage a set of student rows (header-keyed, as StudentOdbcReader returns).
     * Shared core so the import is testable without an ODBC driver.
     *
     * @param array<int,array<string,string>> $rows
     * @return array<string,mixed> summary
     */
    public function importRows(array $rows, bool $dryRun = false): array
    {
        $existing = $this->existingByDcid();

        $counts = ['total' => count($rows), 'inserted' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0];

        $batchId = null;
        if (!$dryRun) {
            $this->db->prepare("INSERT INTO student_import_batch (source, status) VALUES ('powerschool_odbc', 'running')")
                ->execute();
            $batchId = (int) $this->db->lastInsertId();
        }

        $seen = [];
        try {
            if (!$dryRun) {
                $this->db->beginTransaction();
            }
            foreach ($rows as $row) {
                $dcid = trim((string) ($row['Students.DCID'] ?? ''));
                if ($dcid === '') {
                    $counts['skipped']++; // no stable key — can't stage safely
                    continue;
                }
                if (isset($seen[$dcid])) {
                    $counts['skipped']++; // duplicate DCID in this pull — stage once
                    continue;
                }
                $seen[$dcid] = true;
                $fields = self::fields($row);

                if (isset($existing[$dcid])) {
                    $counts['updated']++;
                    if (!$dryRun) {
                        $this->update((int) $existing[$dcid]['student_id'], $fields);
                    }
                } else {
                    $counts['inserted']++;
                    if (!$dryRun) {
                        $this->insert($dcid, $fields);
                    }
                }
            }

            // Drop-outs: students active before this pull but absent from it. We
            // disable (is_active = 0) rather than delete, so OneSync disables the
            // account instead of orphaning it.
            $dropouts = $this->dropoutIds($existing, $seen);
            $counts['deactivated'] = count($dropouts);
            if (!$dryRun && $dropouts !== []) {
                $this->deactivate($dropouts);
            }

            if (!$dryRun) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($batchId !== null) {
                $this->finishBatch($batchId, $counts, 'failed', $e->getMessage());
            }
            throw $e;
        }

        if ($batchId !== null) {
            $this->finishBatch($batchId, $counts, 'complete', $this->summaryMessage($counts));
        }

        return ['batch_id' => $batchId, 'dry_run' => $dryRun, 'counts' => $counts];
    }

    /**
     * Map a raw PowerSchool student row to the `student` column values. Dates are
     * normalised; blanks become NULL so the columns stay clean.
     *
     * @param array<string,string> $row
     * @return array<string,?string>
     */
    private static function fields(array $row): array
    {
        $val = static fn(string $k): ?string => ($v = trim((string) ($row[$k] ?? ''))) !== '' ? $v : null;
        return [
            'ps_id'               => $val('Students.ID'),
            'state_studentnumber' => $val('Students.State_StudentNumber'),
            'ps_school_id'        => $val('Students.SchoolID'),
            'grade_level'         => $val('Students.Grade_Level'),
            'first_name'          => (string) ($val('Students.First_Name') ?? ''),
            'last_name'           => (string) ($val('Students.Last_Name') ?? ''),
            'entry_code'          => $val('Students.EntryCode'),
            'exit_code'           => $val('Students.ExitCode'),
            'exit_date'           => Normalizer::parseDate($val('Students.ExitDate')),
            'enroll_status'       => $val('Students.Enroll_Status'),
        ];
    }

    /** @return array<string,array{student_id:int,is_active:int}> keyed by ps_dcid */
    private function existingByDcid(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT student_id, ps_dcid, is_active FROM student') as $r) {
            $out[(string) $r['ps_dcid']] = ['student_id' => (int) $r['student_id'], 'is_active' => (int) $r['is_active']];
        }
        return $out;
    }

    /** @param array<string,?string> $f */
    private function insert(string $dcid, array $f): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO student
               (student_uuid, ps_dcid, ps_id, state_studentnumber, ps_school_id, grade_level,
                first_name, last_name, entry_code, exit_code, exit_date, enroll_status,
                is_active, last_seen)
             VALUES
               (:uuid, :dcid, :ps_id, :ssn, :school, :grade,
                :first, :last, :entry, :exit, :exit_date, :enroll,
                1, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':uuid'      => Uuid::v4(),
            ':dcid'      => $dcid,
            ':ps_id'     => $f['ps_id'],
            ':ssn'       => $f['state_studentnumber'],
            ':school'    => $f['ps_school_id'],
            ':grade'     => $f['grade_level'],
            ':first'     => $f['first_name'],
            ':last'      => $f['last_name'],
            ':entry'     => $f['entry_code'],
            ':exit'      => $f['exit_code'],
            ':exit_date' => $f['exit_date'],
            ':enroll'    => $f['enroll_status'],
        ]);
    }

    /** @param array<string,?string> $f */
    private function update(int $studentId, array $f): void
    {
        $stmt = $this->db->prepare(
            'UPDATE student SET
                ps_id = :ps_id, state_studentnumber = :ssn, ps_school_id = :school,
                grade_level = :grade, first_name = :first, last_name = :last,
                entry_code = :entry, exit_code = :exit, exit_date = :exit_date,
                enroll_status = :enroll, is_active = 1, last_seen = CURRENT_TIMESTAMP
             WHERE student_id = :id'
        );
        $stmt->execute([
            ':ps_id'     => $f['ps_id'],
            ':ssn'       => $f['state_studentnumber'],
            ':school'    => $f['ps_school_id'],
            ':grade'     => $f['grade_level'],
            ':first'     => $f['first_name'],
            ':last'      => $f['last_name'],
            ':entry'     => $f['entry_code'],
            ':exit'      => $f['exit_code'],
            ':exit_date' => $f['exit_date'],
            ':enroll'    => $f['enroll_status'],
            ':id'        => $studentId,
        ]);
    }

    /**
     * student_ids that were active before this pull but absent from it (drop-outs).
     *
     * @param array<string,array{student_id:int,is_active:int}> $existing
     * @param array<string,bool> $seen
     * @return int[]
     */
    private function dropoutIds(array $existing, array $seen): array
    {
        $ids = [];
        foreach ($existing as $dcid => $row) {
            if ($row['is_active'] === 1 && !isset($seen[$dcid])) {
                $ids[] = $row['student_id'];
            }
        }
        return $ids;
    }

    /**
     * Flag the given students inactive, in chunks so the IN list stays bounded.
     *
     * @param int[] $ids
     */
    private function deactivate(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '?'));
            $this->db->prepare("UPDATE student SET is_active = 0 WHERE student_id IN ({$place})")
                ->execute($chunk);
        }
    }

    /** @param array<string,int> $counts */
    private function finishBatch(int $batchId, array $counts, string $status, string $message): void
    {
        $this->db->prepare(
            'UPDATE student_import_batch
                SET finished_at = CURRENT_TIMESTAMP, row_count = :n, inserted = :ins,
                    updated = :upd, deactivated = :deact, status = :s, message = :m
              WHERE batch_id = :id'
        )->execute([
            ':n'     => $counts['total'],
            ':ins'   => $counts['inserted'],
            ':upd'   => $counts['updated'],
            ':deact' => $counts['deactivated'],
            ':s'     => $status,
            ':m'     => $message,
            ':id'    => $batchId,
        ]);
    }

    /** @param array<string,int> $counts */
    private function summaryMessage(array $counts): string
    {
        return sprintf(
            'rows %d · inserted %d · updated %d · deactivated %d · skipped %d',
            $counts['total'], $counts['inserted'], $counts['updated'], $counts['deactivated'], $counts['skipped']
        );
    }
}
