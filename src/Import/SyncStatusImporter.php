<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use PDO;
use RuntimeException;

/**
 * Account-status write-back importer.
 *
 * Reads OneSync's export log (username, uniqueId, action, actionStatus,
 * destination, timestamp, message) and upserts ONE current-status row per
 * (person, destination) into account_sync_status — so each person's detail page
 * shows "is this account provisioned in AD / Google / Raptor / PowerSchool, and
 * did the last sync succeed?". Also appends to the capped account_sync_event
 * history (pruned to ACCOUNT_SYNC_EVENT_CAP to avoid the multi-million-row bloat
 * of the raw logs).
 *
 * Idempotent (upsert on (person_uuid, destination)); supports --dry-run.
 * Runs as the limited write-back DB role.
 */
final class SyncStatusImporter
{
    private PDO $db;

    private const MAP = [
        'uniqueId' => 'uniqueId', 'username' => 'username', 'destination' => 'destination',
        'action' => 'action', 'status' => 'actionStatus', 'message' => 'message',
        'timestamp' => 'timestamp', 'dest_type' => 'destType',
    ];

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

    /** @return array<string,mixed> summary */
    public function run(?string $file = null, bool $dryRun = false): array
    {
        $file ??= Config::get('ONESYNC_EXPORT_LOG');
        if ($file === null || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('OneSync export log not found (set ONESYNC_EXPORT_LOG or pass --file): ' . (string) $file);
        }

        $rows = Csv::read($file);
        $counts = ['total' => 0, 'upserted' => 0, 'events' => 0, 'no_person' => 0, 'skipped' => 0, 'errors' => 0];

        $upsert = $this->db->prepare(
            'INSERT INTO account_sync_status
               (person_id, person_uuid, destination, dest_type, last_action, last_status, last_sync_at, message)
             VALUES (:pid, :uuid, :dest, :dtype, :action, :status, :ts, :msg)
             ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), dest_type = VALUES(dest_type),
               last_action = VALUES(last_action), last_status = VALUES(last_status),
               last_sync_at = VALUES(last_sync_at), message = VALUES(message)'
        );
        $event = $this->db->prepare(
            'INSERT INTO account_sync_event (person_uuid, destination, action, status, message, occurred_at)
             VALUES (:uuid, :dest, :action, :status, :msg, :ts)'
        );

        foreach ($rows as $raw) {
            $counts['total']++;
            $uuid = trim((string) ($raw[self::MAP['uniqueId']] ?? ''));
            $dest = trim((string) ($raw[self::MAP['destination']] ?? ''));
            if ($uuid === '' || $dest === '') {
                $counts['skipped']++;
                continue;
            }
            $rawAction = (string) ($raw[self::MAP['action']] ?? '');
            $rawStatus = (string) ($raw[self::MAP['status']] ?? '');
            $action = self::normalizeAction($rawAction);
            $status = self::normalizeStatus($rawStatus);
            $msg = ($raw[self::MAP['message']] ?? '') !== '' ? mb_substr((string) $raw[self::MAP['message']], 0, 1000) : null;
            $ts = Normalizer::parseDate($raw[self::MAP['timestamp']] ?? null);
            $tsFull = self::parseTimestamp((string) ($raw[self::MAP['timestamp']] ?? '')) ?? ($ts ? $ts . ' 00:00:00' : null);
            $dtype = self::deriveDestType($dest, $raw[self::MAP['dest_type']] ?? null);

            try {
                $pid = $this->resolvePersonId($uuid);
                if ($pid === null) {
                    $counts['no_person']++;
                }
                if (!$dryRun) {
                    $upsert->execute([
                        ':pid' => $pid, ':uuid' => $uuid, ':dest' => $dest, ':dtype' => $dtype,
                        ':action' => $action, ':status' => $status, ':ts' => $tsFull, ':msg' => $msg,
                    ]);
                    $event->execute([
                        ':uuid' => $uuid, ':dest' => $dest, ':action' => $rawAction ?: null,
                        ':status' => $rawStatus ?: null, ':msg' => $msg, ':ts' => $tsFull,
                    ]);
                    $counts['events']++;
                }
                $counts['upserted']++;
            } catch (\Throwable $e) {
                $counts['errors']++;
            }
        }

        if (!$dryRun) {
            $this->pruneEvents();
        }

        return ['dry_run' => $dryRun, 'counts' => $counts];
    }

    private function resolvePersonId(string $uuid): ?int
    {
        $stmt = $this->db->prepare('SELECT person_id FROM person WHERE person_uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Keep the event log bounded (rotate/prune). */
    private function pruneEvents(): void
    {
        $cap = max(1000, (int) Config::get('ACCOUNT_SYNC_EVENT_CAP', '100000'));
        $total = (int) $this->db->query('SELECT COUNT(*) FROM account_sync_event')->fetchColumn();
        if ($total <= $cap) {
            return;
        }
        // Delete the oldest rows beyond the cap.
        $cutoff = $this->db->prepare('SELECT id FROM account_sync_event ORDER BY id DESC LIMIT 1 OFFSET :n');
        $cutoff->bindValue(':n', $cap, PDO::PARAM_INT);
        $cutoff->execute();
        $minKeep = $cutoff->fetchColumn();
        if ($minKeep !== false) {
            $del = $this->db->prepare('DELETE FROM account_sync_event WHERE id < :id');
            $del->execute([':id' => (int) $minKeep]);
        }
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
