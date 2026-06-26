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
 * their now-existing source id (tier 1), so no duplicate persons appear. Supports
 * --dry-run (reads + matches, writes nothing).
 */
final class Importer
{
    private PDO $db;
    private Matcher $matcher;
    private PersonWriter $writer;

    public function __construct(?PDO $db = null, ?Matcher $matcher = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $threshold = (float) Config::get('MATCH_AUTO_THRESHOLD', '90');
        $this->matcher = $matcher ?? new Matcher($threshold);
        $this->writer = new PersonWriter($this->db, new AuditService($this->db));
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
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open feed file: {$file}");
        }

        $firstLine = fgets($fh);
        if ($firstLine === false) {
            throw new RuntimeException('Feed file is empty.');
        }
        $delim = Csv::detectDelimiter($firstLine);

        if ($source->headerless) {
            // No header row: columns are positional (the column map uses indexes).
            $header = null;
            rewind($fh); // the first line is data
        } else {
            $header = array_map(static fn($h) => trim((string) $h), str_getcsv(Csv::stripBom($firstLine), $delim, '"', '\\'));
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

        while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
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
                $decision = $this->matcher->match($row, $lookup);

                if (!$dryRun) {
                    $this->applyRow($batchId, $source, $row, $decision, $actor);
                }

                $counts[$decision->action]++;
                $outcomes[] = [
                    'name' => trim($row->firstName . ' ' . $row->lastName),
                    'source_key' => $row->sourceKey,
                    'action' => $decision->action,
                    'reason' => $decision->reason,
                    'warnings' => $row->warnings,
                ];
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['name' => '(row ' . $counts['total'] . ')', 'source_key' => '', 'action' => 'error', 'reason' => $e->getMessage(), 'warnings' => []];
            }
        }
        fclose($fh);

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

    /** Persist one staged row and apply its match decision (transactional). */
    private function applyRow(?int $batchId, ImportSource $source, NormalizedRow $row, MatchDecision $decision, string $actor): void
    {
        $crosswalk = $row->sourceSystem();
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
                    foreach ($decision->candidates as $c) {
                        $this->writer->createMatchCandidate($stagingId, $c['person_id'], $c['score'], $c['basis']);
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
