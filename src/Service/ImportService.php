<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Import/feed status: the batch history and a drill-in to each batch's staged
 * rows and how they matched. Read-only.
 */
final class ImportService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
    }

    /** Batch list, newest first, with a matched-row count. */
    public function batches(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.batch_id, b.system, b.file_name, b.started_at, b.finished_at,
                    b.row_count, b.status, b.message,
                    (SELECT COUNT(*) FROM staging_record s
                     WHERE s.batch_id = b.batch_id AND s.match_status IN ('auto_matched','merged')) AS matched
             FROM import_batch b
             ORDER BY b.started_at DESC, b.batch_id DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function batch(int $batchId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM import_batch WHERE batch_id = :id');
        $stmt->execute([':id' => $batchId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Staged rows for a batch, with their match outcome. */
    public function stagedRows(int $batchId, int $limit = 500): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, n_first, n_last, n_source_key, n_employee_id, match_status, matched_person_id, reason
             FROM staging_record WHERE batch_id = :id
             ORDER BY id
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':id' => $batchId]);
        return $stmt->fetchAll();
    }
}
