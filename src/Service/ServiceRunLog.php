<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Records and reads rows in `service_run` — one row per run of a background job
 * (the OneSync DB sync, the feed pull, the students sync), from either the web
 * admin page or the CLI/cron.
 *
 * It exists so the admin "Services" page can show an authoritative "last run"
 * for jobs that leave no run record of their own (the OneSync DB result sync in
 * particular), and so manual "Run now" actions land in one job-keyed history.
 *
 * Writes as the APP role. Never throws on the logging path: a run must not be
 * reported as failed just because the bookkeeping insert had a hiccup — start()
 * returns null and finish() is a no-op when the row id is null.
 */
final class ServiceRunLog
{
    /** Known job keys (also the labels' source of truth). */
    public const JOBS = [
        'onesync_db' => 'OneSync DB sync',
        'feeds'      => 'Feed imports',
        'students'   => 'Students sync',
        'adaxes'     => 'Active Directory sync (Adaxes)',
        'google'     => 'Google Workspace sync',
        'ps_export'  => 'PowerSchool staff export',
    ];

    /** Severity of a service_run_log entry (drives the log-view filter). */
    public const LEVELS = ['attention', 'change', 'info'];

    private ?PDO $pdo;

    public function __construct(?PDO $db = null)
    {
        $this->pdo = $db; // connect lazily
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    public static function label(string $job): string
    {
        return self::JOBS[$job] ?? $job;
    }

    /**
     * Open a run row (status 'running') and return its id, or null if the insert
     * failed (logging must never break the actual job).
     */
    public function start(string $job, string $origin, ?string $actor): ?int
    {
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO service_run (job, origin, actor, status) VALUES (:job, :origin, :actor, :status)'
            );
            $stmt->execute([
                ':job'    => $job,
                ':origin' => $origin,
                ':actor'  => $actor,
                ':status' => 'running',
            ]);
            return (int) $this->db()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[idm] service_run start: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Close a run row with its outcome. No-op when $runId is null (see start()).
     *
     * @param 'complete'|'failed' $status
     * @param array<string,mixed> $counts
     */
    public function finish(?int $runId, string $status, array $counts = [], ?string $message = null): void
    {
        if ($runId === null) {
            return;
        }
        try {
            $stmt = $this->db()->prepare(
                'UPDATE service_run
                    SET status = :status, counts_json = :counts, message = :message, finished_at = NOW()
                  WHERE run_id = :id'
            );
            $stmt->execute([
                ':status'  => $status,
                ':counts'  => $counts === [] ? null : json_encode($counts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':message' => $message === null ? null : mb_substr($message, 0, 1000),
                ':id'      => $runId,
            ]);
        } catch (\Throwable $e) {
            error_log('[idm] service_run finish: ' . $e->getMessage());
        }
    }

    /** The most recent run of a job, or null if it has never run. */
    public function last(string $job): ?array
    {
        try {
            $stmt = $this->db()->prepare(
                'SELECT run_id, job, origin, started_at, finished_at, status, actor, counts_json, message
                   FROM service_run WHERE job = :job ORDER BY started_at DESC, run_id DESC LIMIT 1'
            );
            $stmt->execute([':job' => $job]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            error_log('[idm] service_run last: ' . $e->getMessage());
            return null;
        }
    }

    /** One run row by id, or null when it doesn't exist (or the read failed). */
    public function run(int $runId): ?array
    {
        try {
            $stmt = $this->db()->prepare(
                'SELECT run_id, job, origin, started_at, finished_at, status, actor, counts_json, message
                   FROM service_run WHERE run_id = :id'
            );
            $stmt->execute([':id' => $runId]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            error_log('[idm] service_run get: ' . $e->getMessage());
            return null;
        }
    }

    /** Recent runs of ONE job, newest first (drives the log page's run picker). */
    public function recentForJob(string $job, int $limit = 20): array
    {
        try {
            $stmt = $this->db()->prepare(
                'SELECT run_id, job, origin, started_at, finished_at, status, actor, counts_json, message
                   FROM service_run WHERE job = :job ORDER BY started_at DESC, run_id DESC LIMIT ' . (int) $limit
            );
            $stmt->execute([':job' => $job]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[idm] service_run recentForJob: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Append one detailed log entry to a run (a service_run_log row). No-op when
     * $runId is null (dry runs aren't recorded). Like start()/finish(), never
     * throws: a run must not fail because its log bookkeeping hiccuped.
     *
     * @param array{phase:?string, person_id:?int, subject:string, outcome:string, level:string, detail:string} $e
     */
    public function entry(?int $runId, int $seq, array $e): void
    {
        if ($runId === null) {
            return;
        }
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO service_run_log (run_id, seq, phase, person_id, subject, outcome, level, detail)
                 VALUES (:run, :seq, :phase, :person, :subject, :outcome, :level, :detail)'
            );
            $stmt->execute([
                ':run'     => $runId,
                ':seq'     => $seq,
                ':phase'   => $e['phase'] ?? null,
                ':person'  => ($e['person_id'] ?? null) ?: null,
                ':subject' => mb_substr((string) ($e['subject'] ?? ''), 0, 190),
                ':outcome' => mb_substr((string) ($e['outcome'] ?? ''), 0, 32),
                ':level'   => in_array($e['level'] ?? '', self::LEVELS, true) ? $e['level'] : 'info',
                ':detail'  => mb_substr((string) ($e['detail'] ?? ''), 0, 1000),
            ]);
        } catch (\Throwable $ex) {
            error_log('[idm] service_run_log entry: ' . $ex->getMessage());
        }
    }

    /**
     * A run's detailed log entries in run order, optionally filtered by level
     * ('attention' | 'change' | 'info'; anything else = all).
     */
    public function entries(int $runId, string $level = 'all', int $limit = 2000): array
    {
        $filter = in_array($level, self::LEVELS, true);
        try {
            $stmt = $this->db()->prepare(
                'SELECT seq, logged_at, phase, person_id, subject, outcome, level, detail
                   FROM service_run_log WHERE run_id = :run' . ($filter ? ' AND level = :level' : '')
                . ' ORDER BY seq, log_id LIMIT ' . (int) $limit
            );
            $params = [':run' => $runId] + ($filter ? [':level' => $level] : []);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[idm] service_run_log entries: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Entry totals per level for one run (badges on the log page's filter
     * buttons). Always returns all three keys plus 'total'.
     *
     * @return array{total:int, attention:int, change:int, info:int}
     */
    public function entryCounts(int $runId): array
    {
        $out = ['total' => 0, 'attention' => 0, 'change' => 0, 'info' => 0];
        try {
            $stmt = $this->db()->prepare(
                'SELECT level, COUNT(*) AS n FROM service_run_log WHERE run_id = :run GROUP BY level'
            );
            $stmt->execute([':run' => $runId]);
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['level']] = (int) $row['n'];
                $out['total'] += (int) $row['n'];
            }
        } catch (\Throwable $e) {
            error_log('[idm] service_run_log counts: ' . $e->getMessage());
        }
        return $out;
    }

    /** Recent runs across all jobs, newest first (drives the run-history table). */
    public function recent(int $limit = 20): array
    {
        try {
            $stmt = $this->db()->prepare(
                'SELECT run_id, job, origin, started_at, finished_at, status, actor, counts_json, message
                   FROM service_run ORDER BY started_at DESC, run_id DESC LIMIT ' . (int) $limit
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[idm] service_run recent: ' . $e->getMessage());
            return [];
        }
    }
}
