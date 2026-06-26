<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;
use App\Db;
use App\Service\AuditService;
use PDO;
use RuntimeException;

/**
 * Username write-back importer.
 *
 * OneSync mints the username/email and emits a file (the same file PowerSchool's
 * 2 AM autocomm consumes). This loads that file into onesync_writeback, then
 * applies each row to the golden record: set username/email and lock it.
 *
 * Guardrails:
 *  - Username immutability: once username_locked, never overwrite with a
 *    DIFFERENT value (decide() returns 'conflict' and we skip + log).
 *  - Idempotent: re-running applies nothing new (same value => 'noop').
 *  - The app never mints usernames; this only records what OneSync decided.
 *
 * Runs as the limited write-back DB role.
 */
final class WritebackImporter
{
    private PDO $db;
    private AuditService $audit;

    /** Default file column => CSV header. */
    private const MAP = ['uniqueId' => 'uniqueId', 'username' => 'username', 'email' => 'email', 'upn' => 'upn'];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_WRITEBACK);
        $this->audit = new AuditService($this->db);
    }

    /**
     * Decide what to do with an incoming username for a person — the immutability
     * guardrail, pure and unit-tested.
     *
     * @return 'apply'|'noop'|'conflict'|'skip'
     */
    public static function decide(?string $currentUsername, bool $locked, ?string $incomingUsername): string
    {
        $incoming = trim((string) $incomingUsername);
        if ($incoming === '') {
            return 'skip';                       // nothing to write
        }
        $current = trim((string) $currentUsername);
        if ($current === $incoming) {
            return 'noop';                       // already set to this value
        }
        if ($locked && $current !== '') {
            return 'conflict';                   // never overwrite a locked username
        }
        return 'apply';
    }

    /** @return array<string,mixed> summary */
    public function run(?string $file = null, bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:import_writeback';
        $file ??= Config::get('ONESYNC_WRITEBACK_FILE');
        if ($file === null || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Username write-back file not found (set ONESYNC_WRITEBACK_FILE or pass --file): ' . (string) $file);
        }

        $rows = Csv::read($file);
        $counts = ['total' => 0, 'applied' => 0, 'noop' => 0, 'conflict' => 0, 'skipped' => 0, 'no_person' => 0, 'errors' => 0];
        $outcomes = [];

        foreach ($rows as $raw) {
            $counts['total']++;
            $uuid = trim((string) ($raw[self::MAP['uniqueId']] ?? ''));
            $username = trim((string) ($raw[self::MAP['username']] ?? ''));
            $email = trim((string) ($raw[self::MAP['email']] ?? ''));
            $upn = trim((string) ($raw[self::MAP['upn']] ?? ''));

            try {
                $outcome = $this->processRow($uuid, $username, $email, $upn, $dryRun, $actor);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['uuid' => $uuid, 'username' => $username, 'outcome' => 'error', 'detail' => $e->getMessage()];
                continue;
            }

            $counts[$outcome['key']]++;
            $outcomes[] = ['uuid' => $uuid, 'username' => $username, 'outcome' => $outcome['key'], 'detail' => $outcome['detail']];
        }

        return ['dry_run' => $dryRun, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    /** @return array{key:string,detail:string} */
    private function processRow(string $uuid, string $username, string $email, string $upn, bool $dryRun, string $actor): array
    {
        if ($uuid === '') {
            return ['key' => 'skipped', 'detail' => 'missing uniqueId'];
        }

        // Land the raw write-back row (history) before applying.
        $wbId = null;
        if (!$dryRun) {
            $ins = $this->db->prepare(
                'INSERT INTO onesync_writeback (person_uuid, username, email) VALUES (:uuid, :u, :e)'
            );
            $ins->execute([':uuid' => $uuid, ':u' => $username ?: null, ':e' => $email ?: null]);
            $wbId = (int) $this->db->lastInsertId();
        }

        $person = $this->findPerson($uuid);
        if ($person === null) {
            return ['key' => 'no_person', 'detail' => 'no person for uniqueId ' . $uuid];
        }

        return $this->applyDecision($person, $username, $email, $upn, $wbId, $dryRun, $actor);
    }

    /**
     * Apply a username/email to a person, honoring the immutability guardrail.
     * Shared by the file importer and the direct-write (--pending) path.
     *
     * @return array{key:string,detail:string}
     */
    private function applyDecision(array $person, string $username, string $email, string $upn, ?int $wbId, bool $dryRun, string $actor): array
    {
        $decision = self::decide($person['username'], (int) $person['username_locked'] === 1, $username);

        if ($decision === 'skip') {
            return ['key' => 'skipped', 'detail' => 'blank username'];
        }
        if ($decision === 'conflict') {
            return ['key' => 'conflict', 'detail' => "locked username '{$person['username']}' != '{$username}' — left unchanged"];
        }
        if ($decision === 'noop') {
            if (!$dryRun) {
                if ((int) $person['username_locked'] !== 1) {
                    $this->db->prepare('UPDATE person SET username_locked = 1 WHERE person_id = :id')
                        ->execute([':id' => $person['person_id']]);
                }
                $this->markApplied($wbId);
            }
            return ['key' => 'noop', 'detail' => 'already set'];
        }

        // apply
        if ($dryRun) {
            return ['key' => 'applied', 'detail' => "would set username '{$username}'"];
        }

        try {
            $before = ['username' => $person['username'], 'email' => $person['email'], 'username_locked' => $person['username_locked']];
            $sql = 'UPDATE person SET username = :u, username_assigned_at = CURRENT_TIMESTAMP, username_locked = 1';
            $params = [':u' => $username, ':id' => $person['person_id']];
            if ($email !== '') {
                $sql .= ', email = :e';
                $params[':e'] = $email;
            }
            if ($upn !== '') {
                $sql .= ', upn = :upn';
                $params[':upn'] = $upn;
            }
            $sql .= ' WHERE person_id = :id';
            $this->db->prepare($sql)->execute($params);

            $this->markApplied($wbId);
            $this->audit->log('person', (int) $person['person_id'], 'update', $before,
                ['username' => $username, 'email' => $email ?: $person['email'], 'username_locked' => 1], $actor);
            $this->audit->lifecycle((int) $person['person_id'], 'username_assigned',
                ['summary' => "Username {$username} written back from OneSync and locked."], $actor);

            return ['key' => 'applied', 'detail' => "username '{$username}' set + locked"];
        } catch (\PDOException $e) {
            // Most likely the UNIQUE(username|email) constraint — a collision.
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate')) {
                return ['key' => 'conflict', 'detail' => "unique conflict applying '{$username}' (already used)"];
            }
            throw $e;
        }
    }

    /**
     * Apply onesync_writeback rows OneSync wrote DIRECTLY to the DB (applied = 0).
     * This is the direct-write counterpart to run() (which ingests a file).
     *
     * @return array<string,mixed> summary
     */
    public function runPending(bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:writeback_pending';
        $rows = $this->db->query(
            'SELECT id, person_uuid, username, email FROM onesync_writeback WHERE applied = 0 ORDER BY id'
        )->fetchAll();

        $counts = ['total' => 0, 'applied' => 0, 'noop' => 0, 'conflict' => 0, 'skipped' => 0, 'no_person' => 0, 'errors' => 0];
        $outcomes = [];

        foreach ($rows as $r) {
            $counts['total']++;
            $uuid = (string) $r['person_uuid'];
            $username = (string) ($r['username'] ?? '');
            try {
                $person = $this->findPerson($uuid);
                if ($person === null) {
                    $counts['no_person']++;
                    $outcomes[] = ['uuid' => $uuid, 'username' => $username, 'outcome' => 'no_person', 'detail' => 'no person for uniqueId'];
                    continue;
                }
                $outcome = $this->applyDecision($person, $username, (string) ($r['email'] ?? ''), '', (int) $r['id'], $dryRun, $actor);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['uuid' => $uuid, 'username' => $username, 'outcome' => 'error', 'detail' => $e->getMessage()];
                continue;
            }
            $counts[$outcome['key']]++;
            $outcomes[] = ['uuid' => $uuid, 'username' => $username, 'outcome' => $outcome['key'], 'detail' => $outcome['detail']];
        }

        return ['dry_run' => $dryRun, 'pending' => true, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    private function findPerson(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT person_id, username, email, username_locked FROM person WHERE person_uuid = :uuid'
        );
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function markApplied(?int $wbId): void
    {
        if ($wbId === null) {
            return;
        }
        $this->db->prepare('UPDATE onesync_writeback SET applied = 1, applied_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => $wbId]);
    }
}
