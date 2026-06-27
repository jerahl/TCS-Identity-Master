<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Read-side access to the golden record and everything that hangs off it.
 *
 * Milestone 2 is read-only: list (with filters/search) and full person detail
 * (crosswalk, assignments, per-destination provisioning status, lifecycle
 * timeline). Writes/edits land in a later milestone behind RBAC; mutations will
 * go through AuditService.
 */
final class PersonService
{
    private ?PDO $pdo;

    public function __construct(?PDO $db = null)
    {
        // Connect lazily so constructing the service (e.g. a page that never
        // queries) doesn't open a connection or fail before error handling.
        $this->pdo = $db;
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    /**
     * Filtered, searched people list for the table.
     *
     * @param array{
     *   status?:string, type?:string, school?:string,
     *   missing?:bool, pending?:bool, q?:string
     * } $filters
     * @return array{rows:array<int,array>, total:int}
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        $status = $filters['status'] ?? 'all';
        if ($status !== '' && $status !== 'all') {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }

        $type = $filters['type'] ?? 'all';
        if ($type !== '' && $type !== 'all') {
            $where[] = 'p.person_type = :type';
            $params[':type'] = $type;
        }

        $school = $filters['school'] ?? 'all';
        if ($school !== '' && $school !== 'all') {
            $where[] = 'p.primary_school_id = :school';
            $params[':school'] = (int) $school;
        }

        if (!empty($filters['missing'])) {
            $where[] = "(p.username IS NULL OR p.username = '')";
        }

        if (!empty($filters['pending'])) {
            $where[] = "p.status = 'pending'";
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Each LIKE needs its own placeholder — with emulated prepares off a
            // named placeholder may appear only once (else HY093).
            $cols = ['first_name', 'last_name', 'username', 'email', 'employee_id', 'person_uuid'];
            $likes = [];
            foreach ($cols as $i => $col) {
                $ph = ':q' . $i;
                $likes[] = "p.{$col} LIKE {$ph}";
                $params[$ph] = '%' . $q . '%';
            }
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $orderSql = self::orderBy((string) ($filters['sort'] ?? 'name'), (string) ($filters['dir'] ?? 'asc'));

        $sql = "SELECT p.person_id, p.person_uuid, p.first_name, p.middle_name, p.last_name,
                       p.person_type, p.status, p.username, p.email, p.employee_id,
                       s.name AS primary_school
                FROM person p
                LEFT JOIN school s ON s.school_id = p.primary_school_id
                {$whereSql}
                {$orderSql}";

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = (int) $this->db()->query('SELECT COUNT(*) FROM person')->fetchColumn();

        return ['rows' => $rows, 'total' => $total];
    }

    /** Sort keys the people table allows (column whitelist — never interpolate user input). */
    public const SORTS = ['name', 'employee_id'];

    /**
     * Build a safe ORDER BY from a whitelisted sort key + direction. Employee IDs
     * sort numerically with nulls/blanks last; name sorts by last then first.
     */
    private static function orderBy(string $sort, string $dir): string
    {
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'name';
        }
        return match ($sort) {
            'employee_id' => "ORDER BY (p.employee_id IS NULL OR p.employee_id = ''),
                              CAST(p.employee_id AS UNSIGNED) {$dir}, p.employee_id {$dir}",
            default       => "ORDER BY p.last_name {$dir}, p.first_name {$dir}",
        };
    }

    /** Distinct primary schools present, for the list's school filter. */
    public function schoolFilterOptions(): array
    {
        $sql = 'SELECT DISTINCT s.school_id, s.name
                FROM school s JOIN person p ON p.primary_school_id = s.school_id
                ORDER BY s.name';
        return $this->db()->query($sql)->fetchAll();
    }

    /** All active schools (for the manual-add / edit forms). */
    public function allSchools(): array
    {
        return $this->db()->query(
            "SELECT school_id, name FROM school WHERE status = 'active' ORDER BY name"
        )->fetchAll();
    }

    /** Resolve an ethnicity source value to its ALSDE code, or null if unmapped. */
    public function ethnicityCodeFor(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $stmt = $this->db()->prepare('SELECT alsde_code FROM ethnicity_map WHERE LOWER(source_value) = LOWER(:v)');
        $stmt->execute([':v' => $raw]);
        $code = $stmt->fetchColumn();
        return $code === false ? null : (string) $code;
    }

    /** Count of pending match candidates (the review-queue badge). */
    public function pendingReviewCount(): int
    {
        try {
            return (int) $this->db()->query(
                "SELECT COUNT(*) FROM match_candidate WHERE status = 'pending'"
            )->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** The golden record by surrogate id, or null. */
    public function find(int $personId): ?array
    {
        $sql = 'SELECT p.*, s.name AS primary_school_name
                FROM person p
                LEFT JOIN school s ON s.school_id = p.primary_school_id
                WHERE p.person_id = :id';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([':id' => $personId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Source-ID crosswalk for a person. */
    public function sourceIds(int $personId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT system, source_key, is_active, first_seen, last_seen
             FROM person_source_id WHERE person_id = :id
             ORDER BY is_active DESC, system'
        );
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }

    /** Assignments (multi-location), primary first. */
    public function assignments(int $personId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT a.*, s.name AS school_name
             FROM assignment a JOIN school s ON s.school_id = a.school_id
             WHERE a.person_id = :id
             ORDER BY a.is_primary DESC, s.name'
        );
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }

    /** Current per-destination provisioning status (AD / Google / Raptor / PS). */
    public function syncStatus(int $personId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT destination, dest_type, last_action, last_status, last_sync_at, message
             FROM account_sync_status WHERE person_id = :id
             ORDER BY destination'
        );
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }

    /** Lifecycle/audit timeline (newest first). */
    public function timeline(int $personId, int $limit = 25): array
    {
        $stmt = $this->db()->prepare(
            'SELECT event_type, detail, occurred_at, actor
             FROM lifecycle_event WHERE person_id = :id
             ORDER BY occurred_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([':id' => $personId]);
        return $stmt->fetchAll();
    }
}
