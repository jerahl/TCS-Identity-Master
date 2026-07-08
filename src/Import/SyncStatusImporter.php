<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use PDO;

/**
 * Reflects a single account-provisioning event into account_sync_status (one
 * current-status row per (person, destination)) and the capped
 * account_sync_event history.
 *
 * Scope note — this is deliberately NOT the old OneSync CSV/API status
 * importer. That push path (bin/import_sync_status.php, POST
 * /api/onesync/sync-status, and this class's former run()) was removed once the
 * OneSync DB pull (OneSyncResultImporter) became the sole path for
 * OneSync-mediated status. What survives here is applyEvent(): direct
 * provisioning (GoogleProvisioner) writes to the destination WITHOUT going
 * through OneSync, so OneSync's DB never learns about those writes — this class
 * reflects them into the same tables the pull writes, so the dashboard and
 * person page show a direct write exactly like a reported one.
 *
 * Idempotent (upsert on (person_uuid, destination)). Runs as the limited
 * write-back DB role by default, but honours an injected connection (the direct
 * provisioner reflects on its own app connection, inside its own flow).
 */
final class SyncStatusImporter
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_WRITEBACK);
    }

    /** Map a raw action to the account_sync_status enum, or null. */
    public static function normalizeAction(?string $a): ?string
    {
        return match (mb_strtolower(trim((string) $a))) {
            'add', 'create' => 'Add',
            'edit', 'update', 'modify' => 'Edit',
            'disable' => 'Disable',
            'enable' => 'Enable',
            'nochange', 'no change', 'none' => 'NoChange',
            'new' => 'New',
            default => null,
        };
    }

    /** Map a raw action status to the account_sync_status enum, or null. */
    public static function normalizeStatus(?string $s): ?string
    {
        return match (mb_strtolower(trim((string) $s))) {
            'success', 'succeeded', 'ok', 'completed' => 'Success',
            'fail', 'failed', 'failure', 'error' => 'Fail',
            'skipped', 'skip', 'nochange', 'no change' => 'Skipped',
            'new' => 'New',
            default => null,
        };
    }

    /** Best-effort destination type from the destination label. */
    public static function deriveDestType(string $destination, ?string $explicit = null): ?string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return trim($explicit);
        }
        $d = mb_strtolower($destination);
        return match (true) {
            str_contains($d, 'google'), str_contains($d, 'gsuite'), str_contains($d, 'workspace') => 'GSuite',
            str_contains($d, 'active directory'), str_contains($d, ' ad'), str_starts_with($d, 'ad'), str_contains($d, 'azure'), str_contains($d, 'entra') => 'ActiveDirectory',
            str_contains($d, 'raptor'), str_contains($d, 'powerschool'), str_contains($d, 'csv') => 'CSV',
            default => null,
        };
    }

    /**
     * Apply a single sync-status event. Accepts an associative event keyed by
     * the OneSync field names (uniqueId, destination, action,
     * actionStatus|status, message, timestamp, destType). Upserts the current
     * per-destination status and appends to the event history.
     *
     * @param array<string,mixed> $event
     * @return array{outcome:string,counts:array<string,int>}
     */
    public function applyEvent(array $event, bool $dryRun = false): array
    {
        $zero = ['upserted' => 0, 'events' => 0, 'no_person' => 0, 'skipped' => 0, 'errors' => 0];
        $uuid = trim((string) ($event['uniqueId'] ?? ''));
        $dest = trim((string) ($event['destination'] ?? ''));
        if ($uuid === '' || $dest === '') {
            return ['outcome' => 'skipped', 'counts' => ['skipped' => 1] + $zero];
        }

        $rawAction = (string) ($event['action'] ?? '');
        // Accept either 'actionStatus' (log field) or 'status' (API-style) for the result.
        $rawStatus = (string) ($event['actionStatus'] ?? ($event['status'] ?? ''));
        $msg = ($event['message'] ?? '') !== '' ? mb_substr((string) $event['message'], 0, 1000) : null;
        $ts = self::parseTimestamp((string) ($event['timestamp'] ?? ''));
        $dtype = self::deriveDestType($dest, isset($event['destType']) ? (string) $event['destType'] : null);

        try {
            $pid = $this->resolvePersonId($uuid);
            $counts = $zero;
            $counts['no_person'] = $pid === null ? 1 : 0;
            if (!$dryRun) {
                $this->db->prepare(
                    'INSERT INTO account_sync_status
                       (person_id, person_uuid, destination, dest_type, last_action, last_status, last_sync_at, message)
                     VALUES (:pid, :uuid, :dest, :dtype, :action, :status, :ts, :msg)
                     ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), dest_type = VALUES(dest_type),
                       last_action = VALUES(last_action), last_status = VALUES(last_status),
                       last_sync_at = VALUES(last_sync_at), message = VALUES(message)'
                )->execute([
                    ':pid' => $pid, ':uuid' => $uuid, ':dest' => $dest, ':dtype' => $dtype,
                    ':action' => self::normalizeAction($rawAction), ':status' => self::normalizeStatus($rawStatus),
                    ':ts' => $ts, ':msg' => $msg,
                ]);
                $this->db->prepare(
                    'INSERT INTO account_sync_event (person_uuid, destination, action, status, message, occurred_at)
                     VALUES (:uuid, :dest, :action, :status, :msg, :ts)'
                )->execute([
                    ':uuid' => $uuid, ':dest' => $dest, ':action' => $rawAction ?: null,
                    ':status' => $rawStatus ?: null, ':msg' => $msg, ':ts' => $ts,
                ]);
                $counts['events'] = 1;
            }
            $counts['upserted'] = 1;
            return ['outcome' => $pid === null ? 'no_person' : 'upserted', 'counts' => $counts];
        } catch (\Throwable $e) {
            return ['outcome' => 'error', 'counts' => ['errors' => 1] + $zero];
        }
    }

    private function resolvePersonId(string $uuid): ?int
    {
        $stmt = $this->db->prepare('SELECT person_id FROM person WHERE person_uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Parse a full timestamp (with time) to 'Y-m-d H:i:s', or null. */
    private static function parseTimestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
