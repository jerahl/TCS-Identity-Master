<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use PDO;

/**
 * Pull OneSync provisioning results straight from OneSync's MariaDB into
 * account_sync_status (current per-destination state + failure message) and the
 * capped account_sync_event history.
 *
 * Mapping (verified against the live schema):
 *   os_users.userId        = our person_uuid  (sourceId = our IDM source)
 *   os_users.id            -> os_export_log.userId (numeric)
 *   os_export_log          = per (user, destination) export: action, actionStatus
 *   os_export_log_part     = detail messages (sourceId = os_export_log.id)
 *   os_destinations.id     -> name / typeId (3=AD, 5=Google, 2=CSV/file)
 *
 * We take the latest export per (user, destination) and, for failures, attach the
 * most recent message(s). Reads OneSync read-only; writes as the write-back role.
 */
final class OneSyncResultImporter
{
    private PDO $src;   // OneSync DB (read-only)
    private PDO $app;   // our DB (write-back role)

    public function __construct(?PDO $src = null, ?PDO $app = null)
    {
        $this->src = $src ?? Db::connectOneSyncSource();
        $this->app = $app ?? Db::connect(Db::ROLE_WRITEBACK);
    }

    /** os_export_log.actionStatus -> account_sync_status.last_status. */
    public static function status(int $code): string
    {
        return match ($code) {
            3       => 'Success',
            4       => 'Fail',
            10      => 'Skipped',
            default => 'New',     // 0 / unknown
        };
    }

    /** os_export_log.action -> account_sync_status.last_action. */
    public static function action(int $code): string
    {
        return match ($code) {
            1       => 'Add',
            3       => 'Disable',
            4       => 'Enable',
            0       => 'NoChange',
            default => 'Edit',    // 11 (update) / 2 / unknown
        };
    }

    /** os_destinations.typeId -> dest_type label. */
    public static function destType(int $typeId): ?string
    {
        return match ($typeId) {
            3       => 'ActiveDirectory',
            5       => 'GSuite',
            2       => 'CSV',
            default => null,
        };
    }

    /** @return array<string,mixed> summary */
    public function run(bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:import_onesync_db';
        $sourceId = (int) Config::get('ONESYNC_DB_SOURCE_ID', '21');
        $counts = ['users' => 0, 'rows' => 0, 'upserted' => 0, 'failed' => 0, 'no_person' => 0, 'errors' => 0];

        // Destinations: id -> name/typeId.
        $dests = [];
        foreach ($this->src->query('SELECT id, name, typeId FROM os_destinations')->fetchAll() as $d) {
            $dests[(int) $d['id']] = ['name' => (string) $d['name'], 'typeId' => (int) $d['typeId']];
        }

        // Our users in OneSync (faculty/staff imported from our IDM source).
        $idToUuid = [];
        $u = $this->src->prepare('SELECT id, userId FROM os_users WHERE sourceId = :sid');
        $u->execute([':sid' => $sourceId]);
        foreach ($u->fetchAll() as $r) {
            $uuid = trim((string) $r['userId']);
            if (strlen($uuid) === 36) {            // our person_uuid; skip non-UUID source ids
                $idToUuid[(int) $r['id']] = $uuid;
            }
        }
        $counts['users'] = count($idToUuid);
        if ($idToUuid === []) {
            return ['dry_run' => $dryRun, 'counts' => $counts, 'note' => "No os_users with sourceId={$sourceId} (set ONESYNC_DB_SOURCE_ID)."];
        }

        // Latest export id per (user, destination), then fetch those rows.
        $latestIds = [];
        foreach (array_chunk(array_keys($idToUuid), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $q = $this->src->query(
                "SELECT MAX(id) AS maxid FROM os_export_log
                 WHERE userId IN ({$in}) AND destinationId IS NOT NULL AND dryRecord = 0
                 GROUP BY userId, destinationId"
            );
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $maxid) {
                $latestIds[] = (int) $maxid;
            }
        }

        $logs = [];
        foreach (array_chunk($latestIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($this->src->query(
                "SELECT id, userId, destinationId, action, actionStatus, endTime
                 FROM os_export_log WHERE id IN ({$in})"
            )->fetchAll() as $r) {
                $logs[] = $r;
            }
        }
        $counts['rows'] = count($logs);

        // Failure messages: latest few parts per failed export.
        $failIds = [];
        foreach ($logs as $r) {
            if (self::status((int) $r['actionStatus']) === 'Fail') {
                $failIds[] = (int) $r['id'];
            }
        }
        $msgs = [];
        foreach (array_chunk($failIds, 300) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($this->src->query(
                "SELECT sourceId, message FROM os_export_log_part
                 WHERE sourceId IN ({$in}) AND message IS NOT NULL AND message <> '' ORDER BY id DESC"
            )->fetchAll() as $p) {
                $sid = (int) $p['sourceId'];
                $msgs[$sid] ??= [];
                if (count($msgs[$sid]) < 3) {
                    $msgs[$sid][] = (string) $p['message'];
                }
            }
        }

        $upsert = $this->app->prepare(
            'INSERT INTO account_sync_status
               (person_id, person_uuid, destination, dest_type, last_action, last_status, last_sync_at, message)
             VALUES (:pid, :uuid, :dest, :dtype, :action, :status, :ts, :msg)
             ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), dest_type = VALUES(dest_type),
               last_action = VALUES(last_action), last_status = VALUES(last_status),
               last_sync_at = VALUES(last_sync_at), message = VALUES(message)'
        );
        $event = $this->app->prepare(
            'INSERT INTO account_sync_event (person_uuid, destination, action, status, message, occurred_at)
             VALUES (:uuid, :dest, :action, :status, :msg, :ts)'
        );
        $findPerson = $this->app->prepare('SELECT person_id FROM person WHERE person_uuid = :u');

        foreach ($logs as $r) {
            $uuid = $idToUuid[(int) $r['userId']] ?? null;
            $dest = $dests[(int) $r['destinationId']] ?? null;
            if ($uuid === null || $dest === null) {
                continue;
            }
            $status = self::status((int) $r['actionStatus']);
            $action = self::action((int) $r['action']);
            $ts = ($r['endTime'] && !str_starts_with((string) $r['endTime'], '0001-01-01')) ? $r['endTime'] : null;
            $msg = null;
            if ($status === 'Fail') {
                $counts['failed']++;
                $msg = isset($msgs[(int) $r['id']]) ? mb_substr(implode(' | ', $msgs[(int) $r['id']]), 0, 1000) : null;
            }

            try {
                $pid = null;
                $findPerson->execute([':u' => $uuid]);
                $found = $findPerson->fetchColumn();
                $pid = $found === false ? null : (int) $found;
                if ($pid === null) {
                    $counts['no_person']++;
                }
                if (!$dryRun) {
                    $params = [
                        ':pid' => $pid, ':uuid' => $uuid, ':dest' => mb_substr($dest['name'], 0, 80),
                        ':dtype' => self::destType($dest['typeId']), ':action' => $action,
                        ':status' => $status, ':ts' => $ts, ':msg' => $msg,
                    ];
                    $upsert->execute($params);
                    $event->execute([':uuid' => $uuid, ':dest' => mb_substr($dest['name'], 0, 80),
                        ':action' => $action, ':status' => $status, ':msg' => $msg, ':ts' => $ts]);
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

    /** Keep account_sync_event bounded (same cap as the CSV importer). */
    private function pruneEvents(): void
    {
        $cap = max(1000, (int) Config::get('ACCOUNT_SYNC_EVENT_CAP', '100000'));
        $total = (int) $this->app->query('SELECT COUNT(*) FROM account_sync_event')->fetchColumn();
        if ($total <= $cap) {
            return;
        }
        $cut = $this->app->prepare('SELECT id FROM account_sync_event ORDER BY id DESC LIMIT 1 OFFSET :n');
        $cut->bindValue(':n', $cap, PDO::PARAM_INT);
        $cut->execute();
        $min = $cut->fetchColumn();
        if ($min !== false) {
            $this->app->prepare('DELETE FROM account_sync_event WHERE id < :id')->execute([':id' => (int) $min]);
        }
    }
}
