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
 * Ingestion pipeline: read a NextGen/PowerSchool CSV into import_batch +
 * staging_record, normalize each row, match it to a person (strongest key
 * first), and apply the decision — auto-link, create-new, or queue for review.
 *
 * Idempotent: a re-run of the same file matches rows it previously created via
 * their now-existing source id (tier 1), so no duplicate persons appear. Rows
 * still awaiting human review carry no source id yet (nothing was linked), so
 * they would otherwise re-queue on every import — we guard against that by
 * skipping any incoming source that already has a pending review case, instead
 * of duplicating it in the queue. Supports --dry-run (reads + matches, writes
 * nothing).
 */
final class Importer
{
    private PDO $db;
    private Matcher $matcher;
    private PersonWriter $writer;
    /** @var string[] normalized names whose rows are skipped (system accounts) */
    private array $excludeNames;

    public function __construct(?PDO $db = null, ?Matcher $matcher = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $threshold = (float) Config::get('MATCH_AUTO_THRESHOLD', '90');
        $this->matcher = $matcher ?? new Matcher($threshold);
        $this->writer = new PersonWriter($this->db, new AuditService($this->db));

        // Non-person rows to ignore (e.g. PowerSchool Admin / Lookup accounts),
        // matched by first OR last name. Configurable, comma-separated.
        $raw = (string) Config::get('IMPORT_EXCLUDE_NAMES', 'admin,lookup');
        $this->excludeNames = array_values(array_filter(
            array_map(static fn(string $s) => Matcher::norm($s), explode(',', $raw)),
            static fn(string $s) => $s !== ''
        ));
    }

    /** True if this row is a system/non-person account we should ignore. */
    private function isExcludedAccount(NormalizedRow $row): bool
    {
        return self::nameExcluded($row->firstName, $row->lastName, $this->excludeNames);
    }

    /**
     * Pure exclusion test: true when the first OR last name (normalized) is in the
     * exclude list. Static + dependency-free so it's unit-testable.
     *
     * @param string[] $excludeNames already-normalized exclude tokens
     */
    public static function nameExcluded(string $first, string $last, array $excludeNames): bool
    {
        if ($excludeNames === []) {
            return false;
        }
        return in_array(Matcher::norm($first), $excludeNames, true)
            || in_array(Matcher::norm($last), $excludeNames, true);
    }

    /**
     * @param array<string,string>|null $map  override the system's default column map
     * @return array<string,mixed> summary (counts + per-row outcomes)
     */
    public function run(string $sourceKey, string $file, ?array $map = null, bool $dryRun = false, ?string $actor = null, ?string $originalName = null): array
    {
        $source = ImportSource::for($sourceKey);
        $system = $source->batchSystem;
        $actor ??= 'system:import_' . $sourceKey;
        $map ??= ColumnMap::for($source->columnMapKey);

        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Feed file not found or unreadable: {$file}");
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("Cannot open feed file: {$file}");
        }

        // Split on any line ending (CR / CRLF / LF). PowerSchool exports often use
        // bare CR; fgets/fgetcsv would otherwise read the whole file as one line.
        $lines = Csv::splitLines($content);
        if ($lines === []) {
            throw new RuntimeException('Feed file is empty.');
        }
        $delim = Csv::detectDelimiter($lines[0]);

        if ($source->headerless) {
            // No header row: columns are positional (the column map uses indexes).
            $header = null;
            $dataLines = $lines; // the first line is data
        } else {
            $header = array_map(static fn($h) => trim((string) $h), str_getcsv(Csv::stripBom($lines[0]), $delim, '"', '\\'));
            $dataLines = array_slice($lines, 1);
        }

        $normalizer = Normalizer::fromDb($this->db);
        $lookup = new PdoMatchLookup($this->db);

        $batchId = null;
        if (!$dryRun) {
            $stmt = $this->db->prepare(
                "INSERT INTO import_batch (system, file_name, status) VALUES (:system, :file, 'running')"
            );
            $stmt->execute([':system' => $system, ':file' => $originalName ?? basename($file)]);
            $batchId = (int) $this->db->lastInsertId();
        }

        $counts = ['total' => 0, 'auto_match' => 0, 'new' => 0, 'needs_review' => 0, 'skipped' => 0, 'errors' => 0, 'unmapped' => 0];
        $outcomes = [];

        foreach ($dataLines as $line) {
            $cols = str_getcsv($line, $delim, '"', '\\');
            if ($cols === [null] || (count($cols) === 1 && trim((string) ($cols[0] ?? '')) === '')) {
                continue; // blank line
            }
            $counts['total']++;

            $raw = [];
            if ($header !== null) {
                foreach ($header as $i => $key) {
                    $raw[$key] = $cols[$i] ?? null;
                }
            } else {
                // Headerless: key by 0-based index; strip a BOM off the first cell.
                foreach ($cols as $i => $v) {
                    $raw[$i] = $i === 0 ? Csv::stripBom((string) $v) : $v;
                }
            }

            try {
                $row = $normalizer->normalize($raw, $system, $map, $source->crosswalkSystem, $source->aliasSystem, $source->personType);
                $counts['unmapped'] += count($row->warnings);

                $decision = $this->isExcludedAccount($row)
                    ? new MatchDecision(MatchDecision::SKIPPED, null, 0.0, 'excluded',
                        'Excluded system account (name in IMPORT_EXCLUDE_NAMES).')
                    : $this->matcher->match($row, $lookup);

                $action = $decision->action;
                $reason = $decision->reason;
                if (!$dryRun) {
                    [$action, $reason] = $this->applyRow($batchId, $source, $row, $decision, $actor);
                }

                $counts[$action]++;
                $outcomes[] = [
                    'name' => trim($row->firstName . ' ' . $row->lastName),
                    'source_key' => $row->sourceKey,
                    'action' => $action,
                    'reason' => $reason,
                    'warnings' => $row->warnings,
                ];
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['name' => '(row ' . $counts['total'] . ')', 'source_key' => '', 'action' => 'error', 'reason' => $e->getMessage(), 'warnings' => []];
            }
        }

        if (!$dryRun) {
            $status = ($counts['total'] > 0 && $counts['auto_match'] + $counts['new'] + $counts['needs_review'] === 0)
                ? 'failed' : 'complete';
            $msg = sprintf('auto %d · new %d · review %d · skipped %d · errors %d · unmapped %d',
                $counts['auto_match'], $counts['new'], $counts['needs_review'], $counts['skipped'], $counts['errors'], $counts['unmapped']);
            $this->db->prepare(
                'UPDATE import_batch SET finished_at = CURRENT_TIMESTAMP, row_count = :n, status = :status, message = :msg WHERE batch_id = :id'
            )->execute([':n' => $counts['total'], ':status' => $status, ':msg' => $msg, ':id' => $batchId]);
        }

        return ['batch_id' => $batchId, 'dry_run' => $dryRun, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    /**
     * Persist one staged row and apply its match decision (transactional).
     *
     * @return array{0:string,1:string} the effective [action, reason] — normally
     *   the matcher's, but a review row whose source already has a pending case is
     *   reported as 'skipped' instead of re-queued.
     */
    private function applyRow(?int $batchId, ImportSource $source, NormalizedRow $row, MatchDecision $decision, string $actor): array
    {
        $crosswalk = $row->sourceSystem();
        $action = $decision->action;
        $reason = $decision->reason;
        $this->db->beginTransaction();
        try {
            $stagingId = $this->insertStaging($batchId, $source->batchSystem, $row, $decision);

            $matchedPersonId = null;
            switch ($decision->action) {
                case MatchDecision::AUTO:
                    $matchedPersonId = $decision->personId;
                    $this->writer->attachSourceId($matchedPersonId, $crosswalk, $row->sourceKey, $actor);
                    $this->writer->updateHrFields($matchedPersonId, $row, $actor);
                    $this->writer->upsertAssignment($matchedPersonId, $row, $actor);
                    break;

                case MatchDecision::NEW:
                    $matchedPersonId = $this->writer->createPerson($row, $actor);
                    $this->writer->attachSourceId($matchedPersonId, $crosswalk, $row->sourceKey, $actor);
                    $this->writer->upsertAssignment($matchedPersonId, $row, $actor);
                    break;

                case MatchDecision::REVIEW:
                    // Idempotent re-import: a review row carries no source id (nothing
                    // is linked until a human decides), so it can't be caught by the
                    // tier-1 source-id match on a re-run. If this incoming source
                    // already has an unresolved review case, re-queuing it would
                    // duplicate the case in the review queue — so skip it instead.
                    if (self::hasPendingReview($this->db, $source->batchSystem, $row->sourceKey, $stagingId)) {
                        $reason = 'Already pending review from an earlier import — not re-queued.';
                        $action = MatchDecision::SKIPPED;
                        $this->db->prepare(
                            "UPDATE staging_record SET match_status = 'skipped', reason = :reason WHERE id = :id"
                        )->execute([':reason' => $reason, ':id' => $stagingId]);
                    } else {
                        foreach ($decision->candidates as $c) {
                            $this->writer->createMatchCandidate($stagingId, $c['person_id'], $c['score'], $c['basis']);
                        }
                    }
                    break;

                case MatchDecision::SKIPPED:
                default:
                    break;
            }

            if ($matchedPersonId !== null) {
                $this->db->prepare('UPDATE staging_record SET matched_person_id = :pid WHERE id = :id')
                    ->execute([':pid' => $matchedPersonId, ':id' => $stagingId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [$action, $reason];
    }

    /**
     * True if this incoming source (batch system + source key) already has at
     * least one pending review case — i.e. a prior import queued it and no human
     * has resolved it yet. The just-inserted staging row is excluded via
     * $excludeStagingId (it has no candidates yet, but we exclude it for clarity).
     *
     * Shared by both importers (combined-PowerSchool and the generic CSV path) so
     * an unresolved review case is never re-queued, no matter which feed it came
     * from. Static + PDO-injected so it's directly unit-testable.
     */
    public static function hasPendingReview(PDO $db, string $system, string $sourceKey, int $excludeStagingId): bool
    {
        if (trim($sourceKey) === '') {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT 1
               FROM match_candidate mc
               JOIN staging_record s ON s.id = mc.staging_id
              WHERE mc.status = 'pending'
                AND s.system = :system
                AND s.n_source_key = :key
                AND s.id <> :exclude
              LIMIT 1"
        );
        $stmt->execute([':system' => $system, ':key' => $sourceKey, ':exclude' => $excludeStagingId]);
        return $stmt->fetchColumn() !== false;
    }

    private function insertStaging(?int $batchId, string $system, NormalizedRow $row, MatchDecision $decision): int
    {
        $statusMap = [
            MatchDecision::AUTO => 'auto_matched',
            MatchDecision::NEW => 'new',
            MatchDecision::REVIEW => 'needs_review',
            MatchDecision::SKIPPED => 'skipped',
        ];
        $stmt = $this->db->prepare(
            'INSERT INTO staging_record
               (batch_id, system, raw_json, n_first, n_last, n_dob, n_employee_id, n_source_key, n_school_code, match_status, reason)
             VALUES
               (:batch, :system, :raw, :first, :last, :dob, :emp, :key, :school, :status, :reason)'
        );
        $stmt->execute([
            ':batch' => $batchId,
            ':system' => $system,
            ':raw' => json_encode($row->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':first' => $row->firstName,
            ':last' => $row->lastName,
            ':dob' => $row->dob,
            ':emp' => $row->employeeId,
            ':key' => $row->sourceKey,
            ':school' => $row->schoolCode,
            ':status' => $statusMap[$decision->action],
            ':reason' => $decision->reason . ($row->warnings ? ' | ' . implode(' ', $row->warnings) : ''),
        ]);
        return (int) $this->db->lastInsertId();
    }
}
