<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * The "Logins" report: the golden record projected into the columns of the manual
 * Logins spreadsheet, so onboarding staff can pull new/changed employees straight
 * from the IDM instead of re-keying them from NextGen.
 *
 * Every column but Board Approval is already ingested and reconciled from NextGen
 * (name, position, school, dates, employee id, gender, race) and PowerSchool (DOB,
 * ALSDE ID); Board Approval is entered in-app (migration 0011). "From School /
 * From Position" is *derived* — the person's most recent non-primary assignment —
 * so a transfer shows where they moved from and a brand-new hire shows blank.
 *
 * Read-only: connects as the APP role and never writes.
 */
final class LoginsReportService
{
    private ?PDO $pdo;

    public function __construct(?PDO $db = null)
    {
        // Lazy connect — mirrors PersonService so constructing the service is cheap.
        $this->pdo = $db;
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    /**
     * The Logins columns, in spreadsheet order: logical key => header label. Shared
     * by the on-screen table and the CSV export so the two never drift.
     *
     * @return array<string,string>
     */
    public static function columns(): array
    {
        return [
            'last_name'           => 'Lastname',
            'first_mi'            => 'First Name MI',
            'from_school'         => 'From School',
            'from_position'       => 'From Position',
            'to_position'         => 'To Position',
            'to_school'           => 'To School',
            'effective_date'      => 'Effective Date',
            'end_date'            => 'End Date',
            'board_approval'      => 'Board Approval',
            'employee_id'         => 'Employee ID',
            'dob'                 => 'DOB',
            'gender'              => 'Gender',
            'race'                => 'Race',
            'alsde_id'            => 'ALSDE ID',
        ];
    }

    /** Status filter values the report accepts (whitelist — never interpolated). */
    public const STATUSES = ['all', 'pending', 'active', 'disabled', 'terminated'];

    /**
     * Rows for the report, each already projected into the Logins columns plus the
     * person_id/uuid for linking. Filters:
     *   status  one of STATUSES (default 'all')
     *   school  primary_school_id, or 'all'
     *   from/to inclusive date window on the effective date
     *           (COALESCE(position_start_date, hire_date))
     *   q       name / employee-id / uuid search
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function rows(array $filters = []): array
    {
        $where = [];
        $params = [];

        $status = (string) ($filters['status'] ?? 'all');
        if ($status !== 'all' && in_array($status, self::STATUSES, true)) {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }

        $school = (string) ($filters['school'] ?? 'all');
        if ($school !== '' && $school !== 'all') {
            $where[] = 'p.primary_school_id = :school';
            $params[':school'] = (int) $school;
        }

        // Effective date = position start, falling back to hire date.
        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'COALESCE(p.position_start_date, p.hire_date) >= :from';
            $params[':from'] = $from;
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'COALESCE(p.position_start_date, p.hire_date) <= :to';
            $params[':to'] = $to;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $cols = ['first_name', 'last_name', 'employee_id', 'person_uuid'];
            $likes = [];
            foreach ($cols as $i => $col) {
                $ph = ':q' . $i;
                $likes[] = "p.{$col} LIKE {$ph}";
                $params[$ph] = '%' . $q . '%';
            }
            $likes[] = "CONCAT_WS(' ', p.first_name, p.last_name) LIKE :qfl";
            $params[':qfl'] = '%' . $q . '%';
            $likes[] = "CONCAT_WS(' ', p.last_name, p.first_name) LIKE :qlf";
            $params[':qlf'] = '%' . $q . '%';
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // prev = the most recent NON-primary assignment per person (the "From" side
        // of a transfer). ROW_NUMBER picks one row per person; NULL effective dates
        // sort last. A brand-new hire has no such row, so From columns come back NULL.
        $sql = "SELECT
                    p.person_id, p.person_uuid, p.first_name, p.middle_name, p.last_name,
                    p.employee_id, p.dob, p.gender, p.ethnicity_source,
                    p.alsde_id, p.position_number, p.status,
                    p.username, p.username_locked,
                    p.board_approval_date, p.board_approval_note,
                    COALESCE(p.position_start_date, p.hire_date) AS effective_date,
                    p.end_date,
                    cur.name AS to_school,
                    pa.title AS to_title,
                    prev.title AS from_title,
                    prev.school_name AS from_school
                FROM person p
                LEFT JOIN school cur ON cur.school_id = p.primary_school_id
                LEFT JOIN assignment pa ON pa.person_id = p.person_id AND pa.is_primary = 1
                LEFT JOIN (
                    SELECT x.person_id, x.title, s.name AS school_name
                    FROM (
                        SELECT a.person_id, a.title, a.school_id,
                               ROW_NUMBER() OVER (
                                   PARTITION BY a.person_id
                                   ORDER BY (a.effective_date IS NULL), a.effective_date DESC, a.id DESC
                               ) AS rn
                        FROM assignment a
                        WHERE a.is_primary = 0
                    ) x
                    LEFT JOIN school s ON s.school_id = x.school_id
                    WHERE x.rn = 1
                ) prev ON prev.person_id = p.person_id
                {$whereSql}
                ORDER BY p.last_name ASC, p.first_name ASC";

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $raw = $stmt->fetchAll();

        return array_map([self::class, 'project'], $raw);
    }

    /**
     * Project one DB row into the Logins columns (values as display strings).
     * Public + static so the projection (MI formatting, board-approval combining,
     * To-Position fallback, From derivation) is unit-testable without a DB — the
     * same pattern as PersonWriter's pure diff helpers.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function project(array $r): array
    {
        $mi = trim((string) ($r['middle_name'] ?? ''));
        $mi = $mi === '' ? '' : ' ' . mb_strtoupper(mb_substr($mi, 0, 1)) . '.';

        $board = trim((string) ($r['board_approval_date'] ?? ''));
        $note = trim((string) ($r['board_approval_note'] ?? ''));
        if ($board !== '' && $note !== '') {
            $board .= ' (' . $note . ')';
        } elseif ($board === '' && $note !== '') {
            $board = $note;
        }

        // To Position prefers the primary assignment title, then the HR position #.
        $toPosition = trim((string) ($r['to_title'] ?? ''));
        if ($toPosition === '') {
            $toPosition = trim((string) ($r['position_number'] ?? ''));
        }

        // Ready for an orientation checklist once a username is minted + locked.
        $ready = (int) ($r['username_locked'] ?? 0) === 1
            && trim((string) ($r['username'] ?? '')) !== '';

        return [
            'person_id'      => (int) $r['person_id'],
            'person_uuid'    => (string) $r['person_uuid'],
            'status'         => (string) $r['status'],
            'checklist_ready' => $ready,
            'last_name'      => (string) $r['last_name'],
            'first_mi'       => trim((string) $r['first_name'] . $mi),
            'from_school'    => trim((string) ($r['from_school'] ?? '')),
            'from_position'  => trim((string) ($r['from_title'] ?? '')),
            'to_position'    => $toPosition,
            'to_school'      => trim((string) ($r['to_school'] ?? '')),
            'effective_date' => trim((string) ($r['effective_date'] ?? '')),
            'end_date'       => trim((string) ($r['end_date'] ?? '')),
            'board_approval' => $board,
            'employee_id'    => trim((string) ($r['employee_id'] ?? '')),
            'dob'            => trim((string) ($r['dob'] ?? '')),
            'gender'         => trim((string) ($r['gender'] ?? '')),
            'race'           => trim((string) ($r['ethnicity_source'] ?? '')),
            'alsde_id'       => trim((string) ($r['alsde_id'] ?? '')),
        ];
    }

    /** Active schools for the report's school filter. */
    public function schoolOptions(): array
    {
        return $this->db()->query(
            "SELECT school_id, name FROM school WHERE status = 'active' ORDER BY name"
        )->fetchAll();
    }
}
