<?php

declare(strict_types=1);

namespace App\Sync;

use App\Db;
use PDO;

/**
 * Records which SFTP files have been fetched per source, so re-runs skip files
 * already downloaded/imported. Backed by feed_fetch_log.
 */
final class FetchLog
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
    }

    /** @return string[] remote names already fetched for this source */
    public function seen(string $system): array
    {
        $stmt = $this->db->prepare('SELECT remote_name FROM feed_fetch_log WHERE system = :s');
        $stmt->execute([':s' => $system]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** Record a downloaded file; returns the row id (idempotent on (system, name)). */
    public function record(string $system, string $name, ?string $localPath, ?int $size): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO feed_fetch_log (system, remote_name, local_path, size_bytes, status)
             VALUES (:s, :n, :p, :z, \'downloaded\')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), local_path = VALUES(local_path),
                                     size_bytes = VALUES(size_bytes), fetched_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':s' => $system, ':n' => $name, ':p' => $localPath, ':z' => $size]);
        return (int) $this->db->lastInsertId();
    }

    public function markImported(int $id, ?int $batchId): void
    {
        $this->db->prepare("UPDATE feed_fetch_log SET status = 'imported', batch_id = :b WHERE id = :id")
            ->execute([':b' => $batchId, ':id' => $id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $this->db->prepare("UPDATE feed_fetch_log SET status = 'failed', message = :m WHERE id = :id")
            ->execute([':m' => mb_substr($message, 0, 500), ':id' => $id]);
    }
}
