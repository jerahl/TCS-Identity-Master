<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Centralized audit writer. Every mutation in the app must record an audit_log
 * row (before/after JSON, actor); person-scoped changes also append a
 * lifecycle_event so the person timeline tells the story.
 *
 * Built in Milestone 2 so the write paths added in later milestones (edits,
 * manual add, review-queue confirm/reject, write-back) have one place to log
 * through. The `actor` is the SAML user once SSO lands (M7); until then callers
 * pass a system/job actor.
 */
final class AuditService
{
    private ?PDO $pdo;

    public function __construct(?PDO $db = null)
    {
        $this->pdo = $db; // connect lazily on first write
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    /**
     * Record a mutation in audit_log.
     *
     * @param 'person'|'assignment'|'source_id'|'match'|'school'|'config' $entity
     * @param 'insert'|'update'|'delete'|'merge' $action
     */
    public function log(
        string $entity,
        ?int $entityId,
        string $action,
        ?array $before,
        ?array $after,
        string $actor
    ): void {
        $stmt = $this->db()->prepare(
            'INSERT INTO audit_log (entity, entity_id, action, before_json, after_json, actor)
             VALUES (:entity, :entity_id, :action, :before_json, :after_json, :actor)'
        );
        $stmt->execute([
            ':entity'      => $entity,
            ':entity_id'   => $entityId,
            ':action'      => $action,
            ':before_json' => $before === null ? null : self::json($before),
            ':after_json'  => $after === null ? null : self::json($after),
            ':actor'       => $actor,
        ]);
    }

    /**
     * Append a lifecycle event for a person (drives the detail-page timeline).
     *
     * @param 'create'|'update'|'disable'|'enable'|'terminate'|'convert'|'merge'|'username_assigned' $eventType
     */
    public function lifecycle(int $personId, string $eventType, ?array $detail, string $actor): void
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO lifecycle_event (person_id, event_type, detail, actor)
             VALUES (:person_id, :event_type, :detail, :actor)'
        );
        $stmt->execute([
            ':person_id'  => $personId,
            ':event_type' => $eventType,
            ':detail'     => $detail === null ? null : self::json($detail),
            ':actor'      => $actor,
        ]);
    }

    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
