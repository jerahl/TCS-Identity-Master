<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * The delayed-events queue (scheduled_event). Lets any part of IDM defer an
 * action to a future time — the username/email cutover 7 days after a rename is
 * approved, the old-alias removal 90 days later, and the reminder emails between.
 *
 * A row is due once run_at passes; ScheduledEventRunner claims due rows and
 * dispatches them by event_type. Failures stay pending (with attempts/last_error)
 * and retry, up to MAX_ATTEMPTS, then flip to 'failed'. Scheduling is idempotent
 * when a dedupe_key is supplied — re-running the code that schedules a cutover
 * for the same rename won't create a duplicate.
 *
 * Time is passed in ($now / $runAt as 'Y-m-d H:i:s' UTC) so this unit-tests
 * deterministically without touching the clock.
 */
final class ScheduledEventService
{
    /** After this many failed attempts a row is parked as 'failed'. */
    public const MAX_ATTEMPTS = 5;

    private ?PDO $pdo;
    private ?AuditService $audit;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->pdo = $db;
        $this->audit = $audit;
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    private function audit(): AuditService
    {
        return $this->audit ??= new AuditService($this->db());
    }

    /**
     * Enqueue an event. With a $dedupeKey, an existing PENDING event with the same
     * key is returned instead of inserting a duplicate (idempotent scheduling).
     *
     * @param array<string,mixed>|null $payload
     * @return int the event id (new or the existing pending one)
     */
    public function schedule(
        string $eventType,
        string $runAt,
        ?array $payload,
        ?int $personId,
        string $actor,
        ?string $dedupeKey = null
    ): int {
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $existing = $this->db()->prepare(
                "SELECT id FROM scheduled_event WHERE dedupe_key = :k AND status = 'pending' LIMIT 1"
            );
            $existing->execute([':k' => $dedupeKey]);
            $id = $existing->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO scheduled_event (person_id, event_type, run_at, payload, dedupe_key, created_by)
             VALUES (:pid, :type, :run_at, :payload, :dedupe, :by)'
        );
        $stmt->execute([
            ':pid'     => $personId,
            ':type'    => $eventType,
            ':run_at'  => $runAt,
            ':payload' => $payload === null ? null : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':dedupe'  => ($dedupeKey === '' ? null : $dedupeKey),
            ':by'      => $actor,
        ]);
        $id = (int) $this->db()->lastInsertId();
        $this->audit()->log('config', $id, 'insert', null,
            ['scheduled_event' => $eventType, 'run_at' => $runAt, 'person_id' => $personId], $actor);
        return $id;
    }

    /**
     * Pending events that are due at or before $now, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function due(string $now, int $limit = 100): array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM scheduled_event
             WHERE status = 'pending' AND run_at <= :now
             ORDER BY run_at, id
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([':now' => $now]);
        return $stmt->fetchAll();
    }

    /** Pending events for a person (optionally of one type). @return list<array<string,mixed>> */
    public function pendingForPerson(int $personId, ?string $eventType = null): array
    {
        $sql = "SELECT * FROM scheduled_event WHERE person_id = :pid AND status = 'pending'";
        $params = [':pid' => $personId];
        if ($eventType !== null) {
            $sql .= ' AND event_type = :type';
            $params[':type'] = $eventType;
        }
        $sql .= ' ORDER BY run_at, id';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function markDone(int $id): void
    {
        $this->db()->prepare("UPDATE scheduled_event SET status = 'done', last_error = NULL WHERE id = :id")
            ->execute([':id' => $id]);
    }

    /**
     * Record a failed attempt: increment attempts and keep the row pending to
     * retry, or park it as 'failed' once MAX_ATTEMPTS is reached.
     */
    public function markFailed(int $id, string $error): void
    {
        // MAX_ATTEMPTS is inlined (a trusted int constant) rather than bound — a
        // bound value is text-typed and would make the numeric compare misbehave.
        $max = (int) self::MAX_ATTEMPTS;
        $this->db()->prepare(
            "UPDATE scheduled_event
               SET attempts = attempts + 1,
                   last_error = :err,
                   status = CASE WHEN attempts + 1 >= {$max} THEN 'failed' ELSE 'pending' END
             WHERE id = :id"
        )->execute([':err' => mb_substr($error, 0, 1000), ':id' => $id]);
    }

    /** Cancel a single pending event (e.g. superseded). Returns whether it changed. */
    public function cancel(int $id, string $actor): bool
    {
        $stmt = $this->db()->prepare("UPDATE scheduled_event SET status = 'canceled' WHERE id = :id AND status = 'pending'");
        $stmt->execute([':id' => $id]);
        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            $this->audit()->log('config', $id, 'update', ['status' => 'pending'], ['status' => 'canceled'], $actor);
        }
        return $changed;
    }

    /**
     * Cancel all pending events for a person (optionally of one type) — used when
     * a rename is superseded or a username is unlinked, so stale cutovers/removals
     * don't fire. Returns the count canceled.
     */
    public function cancelPending(int $personId, string $actor, ?string $eventType = null): int
    {
        $sql = "UPDATE scheduled_event SET status = 'canceled' WHERE person_id = :pid AND status = 'pending'";
        $params = [':pid' => $personId];
        if ($eventType !== null) {
            $sql .= ' AND event_type = :type';
            $params[':type'] = $eventType;
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $n = $stmt->rowCount();
        if ($n > 0) {
            $this->audit()->log('config', $personId, 'update',
                ['pending_events' => $eventType ?? 'all'], ['canceled' => $n], $actor);
        }
        return $n;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> the decoded payload */
    public static function payloadOf(array $row): array
    {
        $data = json_decode((string) ($row['payload'] ?? ''), true);
        return is_array($data) ? $data : [];
    }
}
