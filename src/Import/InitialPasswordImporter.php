<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use App\Service\AuditService;
use App\Support\Crypto;
use PDO;

/**
 * Initial-password write-back importer (API-only — there is no CSV variant on
 * purpose: a password must never sit in a file drop).
 *
 * OneSync sets a temporary password when it creates the account and POSTs it to
 * /api/onesync/password. This encrypts the value (libsodium, CREDENTIAL_ENC_KEY)
 * and stores it on the golden record; only the ciphertext ever reaches the DB,
 * and the plaintext never reaches audit rows or logs.
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
     * Apply a single password write-back event (the API entry point).
     *
     * @param array<string,mixed> $event
     * @return array{outcome:string,detail:string,uuid:string}
     */
    public function applyEvent(array $event, ?string $actor = null): array
    {
        $actor ??= 'system:onesync_api';
        $uuid = trim((string) ($event['uniqueId'] ?? ''));
        $password = (string) ($event['password'] ?? '');

        if ($uuid === '') {
            return ['outcome' => 'skipped', 'detail' => 'missing uniqueId', 'uuid' => $uuid];
        }
        if (trim($password) === '') {
            return ['outcome' => 'skipped', 'detail' => 'blank password', 'uuid' => $uuid];
        }

        $stmt = $this->db->prepare(
            'SELECT person_id, initial_password_set_at FROM person WHERE person_uuid = :uuid'
        );
        $stmt->execute([':uuid' => $uuid]);
        $person = $stmt->fetch();
        if ($person === false) {
            return ['outcome' => 'no_person', 'detail' => 'no person for uniqueId ' . $uuid, 'uuid' => $uuid];
        }

        $upd = $this->db->prepare(
            'UPDATE person SET initial_password_enc = :enc, initial_password_set_at = CURRENT_TIMESTAMP
             WHERE person_id = :id'
        );
        $upd->bindValue(':enc', Crypto::encrypt($password), PDO::PARAM_LOB);
        $upd->bindValue(':id', (int) $person['person_id'], PDO::PARAM_INT);
        $upd->execute();

        $replaced = $person['initial_password_set_at'] !== null;
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
            'uuid'    => $uuid,
        ];
    }
}
