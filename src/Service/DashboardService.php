<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Import\ImportSource;
use PDO;

/**
 * Aggregates the home/health dashboard: KPI counts, recent activity, the last
 * feed per source, and the failed-sync rollup. Read-only.
 */
final class DashboardService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
    }

    private function count(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    /** @return array{pendingReview:int,pendingActivation:int,missingUsername:int,unmapped:int,failedSync:int,lastFeed:?array} */
    public function kpis(): array
    {
        $unmappedEth = $this->count(
            "SELECT COUNT(DISTINCT ethnicity_source) FROM person
             WHERE ethnicity_source IS NOT NULL AND ethnicity_source <> '' AND (ethnicity_code IS NULL OR ethnicity_code = '')"
        );
        $unmappedSchool = $this->count(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT s.system, s.n_school_code FROM staging_record s
                WHERE s.n_school_code IS NOT NULL AND s.n_school_code <> ''
                  AND NOT EXISTS (SELECT 1 FROM school_code_alias a WHERE a.system = s.system AND a.code = s.n_school_code)
             ) t"
        );

        $lastFeed = $this->db->query(
            'SELECT system, started_at, status, row_count FROM import_batch ORDER BY started_at DESC, batch_id DESC LIMIT 1'
        )->fetch();

        return [
            'pendingReview'     => $this->count("SELECT COUNT(*) FROM match_candidate WHERE status = 'pending'"),
            'pendingActivation' => $this->count("SELECT COUNT(*) FROM person WHERE status = 'pending'"),
            'missingUsername'   => $this->count("SELECT COUNT(*) FROM person WHERE (username IS NULL OR username = '') AND status <> 'terminated'"),
            'unmapped'          => $unmappedEth + $unmappedSchool,
            'failedSync'        => $this->count("SELECT COUNT(*) FROM account_sync_status WHERE last_status = 'Fail'"),
            'lastFeed'          => $lastFeed === false ? null : $lastFeed,
        ];
    }

    /** Recent lifecycle activity across all people. */
    public function recentActivity(int $limit = 8): array
    {
        $stmt = $this->db->prepare(
            'SELECT le.event_type, le.detail, le.occurred_at, le.actor,
                    p.person_id, p.first_name, p.last_name
             FROM lifecycle_event le
             JOIN person p ON p.person_id = le.person_id
             ORDER BY le.occurred_at DESC, le.id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Most recent batch per import source (the per-source feed breakdown). */
    public function feeds(): array
    {
        $out = [];
        $stmt = $this->db->prepare(
            'SELECT system, started_at, finished_at, row_count, status, message,
                    (SELECT COUNT(*) FROM staging_record s
                     WHERE s.batch_id = b.batch_id AND s.match_status = \'needs_review\') AS review_count
             FROM import_batch b WHERE system = :sys ORDER BY started_at DESC, batch_id DESC LIMIT 1'
        );
        foreach (ImportSource::all() as $source) {
            $stmt->execute([':sys' => $source->batchSystem]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $row['label'] = $source->label;
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Accounts whose last sync failed (the health rollup). */
    public function failedSyncs(int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.destination, a.last_status, a.last_sync_at, a.message,
                    a.person_id, p.first_name, p.last_name
             FROM account_sync_status a
             LEFT JOIN person p ON p.person_id = a.person_id
             WHERE a.last_status = 'Fail'
             ORDER BY a.last_sync_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
