<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use App\Service\AuditService;
use App\Support\Crypto;
use PDO;

/**
 * Initial-password write-back importer.
 *
 * OneSync sets a temporary password when it creates the account and POSTs it to
 * /api/onesync/password. This encrypts the value (libsodium, CREDENTIAL_ENC_KEY)
 * and stores it on the golden record; only the ciphertext ever reaches the DB,
 * and the plaintext never reaches audit rows, logs, or importer output.
 *
 * There is deliberately NO recurring file-drop variant — a password must never
 * sit in a scheduled feed directory. The one exception is run(): a ONE-TIME
 * backfill for accounts created before the API existed, executed by hand from a
 * trusted shell (bin/backfill_passwords.php) against a CSV you delete afterward.
 *
 * Re-sending replaces the stored value — OneSync may reset the password when it
 * re-provisions, and the newest value is the one the new hire needs.
 *
 * Runs as the limited write-back DB role.
 */
final class InitialPasswordImporter
{
    private PDO $db;
    private AuditService $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_WRITEBACK);
        $this->audit = new AuditService($this->db);
    }

    /**
     * Map a raw CSV row onto an event, matching header names case-insensitively
     * and accepting the aliases different exports use. Pure, for the backfill.
     *
     * The `AD Login`/`AD Password` pair lets the HR personnel-action (board
     * approval) spreadsheet be imported as-is — its other columns (Change Type,
     * Board Approval, name, position, DOB, ALSDE ID, …) are simply ignored.
     *
     * @param array<string,string> $row
     * @return array{uniqueId:string,username:string,password:string}
     */
    public static function normalizeRow(array $row): array
    {
        $aliases = [
            'uniqueId' => ['uniqueid', 'uuid', 'id'],
            'username' => ['username', 'user', 'ad login', 'ad_login', 'adlogin'],
            'password' => ['password', 'temp_password', 'temppassword', 'initial_password', 'initialpassword',
                'ad password', 'ad_password', 'adpassword'],
        ];
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower(trim((string) $k))] = (string) $v;
        }
        $out = [];
        foreach ($aliases as $field => $names) {
            $out[$field] = '';
            foreach ($names as $name) {
                if (isset($lower[$name]) && trim($lower[$name]) !== '') {
                    $out[$field] = trim($lower[$name]);
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Apply a single password event. The API requires uniqueId; the backfill may
     * match by username instead when no uniqueId column is available.
     *
     * @param array<string,mixed> $event
     * @return array{outcome:string,detail:string,uuid:string,username:string}
     */
    public function applyEvent(array $event, bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:onesync_api';
        $uuid = trim((string) ($event['uniqueId'] ?? ''));
        $username = trim((string) ($event['username'] ?? ''));
        $password = (string) ($event['password'] ?? '');
        $base = ['uuid' => $uuid, 'username' => $username];

        if ($uuid === '' && $username === '') {
            return ['outcome' => 'skipped', 'detail' => 'missing uniqueId/username'] + $base;
        }
        if (trim($password) === '') {
            return ['outcome' => 'skipped', 'detail' => 'blank password'] + $base;
        }

        $person = $this->findPerson($uuid, $username);
        if ($person === null) {
            $key = $uuid !== '' ? 'uniqueId ' . $uuid : "username '{$username}'";
            return ['outcome' => 'no_person', 'detail' => 'no person for ' . $key] + $base;
        }

        $replaced = $person['initial_password_set_at'] !== null;
        if ($dryRun) {
            return [
                'outcome' => 'applied',
                'detail'  => $replaced ? 'would replace initial password' : 'would store initial password',
            ] + $base;
        }

        $upd = $this->db->prepare(
            'UPDATE person SET initial_password_enc = :enc, initial_password_set_at = CURRENT_TIMESTAMP
             WHERE person_id = :id'
        );
        $upd->bindValue(':enc', Crypto::encrypt($password), PDO::PARAM_LOB);
        $upd->bindValue(':id', (int) $person['person_id'], PDO::PARAM_INT);
        $upd->execute();

        $personId = (int) $person['person_id'];
        // Audit the fact, never the value.
        $this->audit->log('person', $personId, 'update',
            ['initial_password' => $replaced ? '[set]' : null],
            ['initial_password' => '[set]'], $actor);
        $this->audit->lifecycle($personId, 'password_received',
            ['summary' => $replaced
                ? 'Initial password replaced by OneSync.'
                : 'Initial password received from OneSync.'], $actor);

        return [
            'outcome' => 'applied',
            'detail'  => $replaced ? 'initial password replaced' : 'initial password stored',
        ] + $base;
    }

    /**
     * ONE-TIME backfill from a CSV (header row required; column aliases in
     * normalizeRow). Each row needs a password plus a uniqueId or username.
     * Outcomes never contain the password. Delete the CSV when done.
     *
     * @return array{dry_run:bool,counts:array<string,int>,outcomes:array<int,array<string,string>>}
     */
    public function run(string $file, bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:password_backfill';
        $rows = Csv::read($file);

        $counts = ['total' => 0, 'applied' => 0, 'skipped' => 0, 'no_person' => 0, 'errors' => 0];
        $outcomes = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $event = self::normalizeRow($row);
            try {
                $r = $this->applyEvent($event, $dryRun, $actor);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $outcomes[] = ['uuid' => $event['uniqueId'], 'username' => $event['username'],
                    'outcome' => 'error', 'detail' => $e->getMessage()];
                continue;
            }
            $counts[$r['outcome']] ??= 0;
            $counts[$r['outcome']]++;
            $outcomes[] = $r;
        }
        return ['dry_run' => $dryRun, 'counts' => $counts, 'outcomes' => $outcomes];
    }

    /** Find by uuid when given (authoritative), else by the unique username. */
    private function findPerson(string $uuid, string $username): ?array
    {
        if ($uuid !== '') {
            $stmt = $this->db->prepare(
                'SELECT person_id, initial_password_set_at FROM person WHERE person_uuid = :k'
            );
            $stmt->execute([':k' => $uuid]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT person_id, initial_password_set_at FROM person WHERE username = :k'
            );
            $stmt->execute([':k' => $username]);
        }
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
