<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;
use App\Import\ImportSource;
use App\Sync\Freshness;
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

    /**
     * Shared FROM/WHERE for "should be disabled" candidates: people who are no
     * longer in NextGen (no ACTIVE NextGen crosswalk id — covers manual
     * contractors/interns/subs and anyone dropped off the feed) and still enabled
     * (active/pending), that meet EITHER trigger:
     *   (a) their exit date (person.end_date) is already in the past; or
     *   (b) they were dropped from the NextGen feed more than N days ago
     *       (an inactive NextGen crosswalk row whose last_seen is older than
     *       NEXTGEN_DROPOUT_FLAG_DAYS), regardless of exit date — this catches
     *       leavers NextGen drops without ever setting an end date.
     * NextGen drives disable for its own people but never touches off-feed
     * records, so without this flag they linger enabled forever. Read-only: this
     * only surfaces them; an admin reviews and disables.
     */
    private function disableCandidateFromWhere(): string
    {
        $days = max(0, (int) Config::get('NEXTGEN_DROPOUT_FLAG_DAYS', '7'));
        // $days is a validated int, safe to interpolate (INTERVAL can't be bound
        // in query()); the rest of the predicate takes no parameters.
        return "FROM person p
         WHERE p.status IN ('active','pending')
           AND NOT EXISTS (
               SELECT 1 FROM person_source_id psi
               WHERE psi.person_id = p.person_id
                 AND psi.system = 'nextgen'
                 AND psi.is_active = 1
           )
           AND (
               (p.end_date IS NOT NULL AND p.end_date < CURDATE())
               OR EXISTS (
                   SELECT 1 FROM person_source_id d
                   WHERE d.person_id = p.person_id
                     AND d.system = 'nextgen'
                     AND d.is_active = 0
                     AND d.last_seen < (NOW() - INTERVAL {$days} DAY)
               )
           )";
    }

    /** @return array{pendingReview:int,pendingActivation:int,missingUsername:int,unmapped:int,failedSync:int,disableFlagged:int,lastFeed:?array} */
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
                  AND NOT EXISTS (
                      SELECT 1 FROM school_code_alias a
                      WHERE a.system = s.system
                        AND TRIM(LEADING '0' FROM a.code) = TRIM(LEADING '0' FROM s.n_school_code)
                  )
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
            'disableFlagged'    => $this->count("SELECT COUNT(*) " . $this->disableCandidateFromWhere()),
            'lastFeed'          => $lastFeed === false ? null : $lastFeed,
        ];
    }

    /**
     * People to review for disabling: not in NextGen and still enabled, with
     * either a past exit date or a stale drop from the NextGen feed. See
     * disableCandidateFromWhere(). `nextgen_last_seen` is when they last appeared
     * in a NextGen feed (NULL for records that were never in NextGen, e.g. manual
     * contractors/interns) so callers can show why each one is flagged.
     * Read-only — surfaced on the dashboard and by bin/flag_disable_candidates.php.
     */
    public function disableCandidates(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.person_id, p.first_name, p.last_name, p.person_type,
                    p.status, p.end_date, p.source_of_record,
                    (SELECT MAX(d.last_seen) FROM person_source_id d
                      WHERE d.person_id = p.person_id AND d.system = 'nextgen' AND d.is_active = 0)
                        AS nextgen_last_seen
             " . $this->disableCandidateFromWhere() . "
             ORDER BY p.end_date IS NULL, p.end_date ASC, p.last_name, p.first_name
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
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
        $now = time();
        $staleHours = max(1, (int) Config::get('FEED_STALE_HOURS', '26'));
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
                $fresh = Freshness::classify($row['started_at'] ?? null, $staleHours, $now);
                $row['fresh_state'] = $fresh['state'];
                $row['fresh_label'] = $fresh['label'];
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * OneSync write-back freshness: when did OneSync last report any status, and
     * how many accounts are stale? Drives the "OneSync hasn't run" indicator.
     *
     * @return array{state:string,label:string,at:?string,staleAccounts:int,staleHours:int}
     */
    public function syncHealth(): array
    {
        $staleHours = max(1, (int) Config::get('SYNC_STALE_HOURS', '26'));
        $lastAt = $this->db->query('SELECT MAX(last_sync_at) FROM account_sync_status')->fetchColumn();
        $lastAt = $lastAt === false ? null : $lastAt;

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM account_sync_status
             WHERE last_sync_at IS NULL OR last_sync_at < (NOW() - INTERVAL :h HOUR)'
        );
        $stmt->bindValue(':h', $staleHours, PDO::PARAM_INT);
        $stmt->execute();
        $staleAccounts = (int) $stmt->fetchColumn();

        $fresh = Freshness::classify($lastAt, $staleHours, time());
        return [
            'state' => $fresh['state'],
            'label' => $fresh['label'],
            'at' => $fresh['at'],
            'staleAccounts' => $staleAccounts,
            'staleHours' => $staleHours,
        ];
    }

    /**
     * Students passthrough sync status: the latest student import run plus the
     * current active-student count. Drives the "Students (OneSync)" dashboard card
     * — the web app only shows the status of this sync, it doesn't edit students.
     *
     * @return array{state:string,label:string,at:?string,status:?string,active:int,lastRun:?array}
     */
    public function studentSync(): array
    {
        $staleHours = max(1, (int) Config::get('FEED_STALE_HOURS', '26'));
        $active = $this->count('SELECT COUNT(*) FROM student WHERE is_active = 1');

        $last = $this->db->query(
            'SELECT started_at, finished_at, row_count, inserted, updated, deactivated, status, message
             FROM student_import_batch ORDER BY started_at DESC, batch_id DESC LIMIT 1'
        )->fetch();
        $last = $last === false ? null : $last;

        $fresh = Freshness::classify($last['started_at'] ?? null, $staleHours, time());
        return [
            'state'   => $fresh['state'],
            'label'   => $fresh['label'],
            'at'      => $fresh['at'],
            'status'  => $last['status'] ?? null,
            'active'  => $active,
            'lastRun' => $last,
        ];
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
