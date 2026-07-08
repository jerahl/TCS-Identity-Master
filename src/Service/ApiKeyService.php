<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use App\Support\ApiKey;
use PDO;

/**
 * Per-user API keys for programmatic access (the MCP server authenticates with
 * these). A key is bound to one app_user; the rights it grants are the owning
 * user's LIVE role, resolved on every request — so a role downgrade or a
 * deactivated/ revoked account takes effect immediately, without re-issuing keys.
 *
 * Only the SHA-256 hash of a key is stored. create() returns the raw secret once
 * (the caller must show it and then forget it). Revocation is soft (revoked_at)
 * so the audit trail and last-used history survive.
 */
final class ApiKeyService
{
    private PDO $db;
    private AuditService $audit;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->audit = $audit ?? new AuditService($this->db);
    }

    /**
     * Mint a new key for a user. Returns the plaintext key (shown once) plus the
     * stored row. The plaintext is never persisted.
     *
     * @return array{plaintext:string, row:array<string,mixed>}
     */
    public function create(int $userId, string $label, string $actor): array
    {
        $label = trim($label) !== '' ? trim($label) : 'API key';
        $key = ApiKey::generate();

        $stmt = $this->db->prepare(
            'INSERT INTO api_key (user_id, label, token_prefix, token_hash, created_by)
             VALUES (:uid, :label, :prefix, :hash, :actor)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':label'  => mb_substr($label, 0, 120),
            ':prefix' => ApiKey::displayPrefix($key),
            ':hash'   => ApiKey::hash($key),
            ':actor'  => mb_substr($actor, 0, 160),
        ]);
        $id = (int) $this->db->lastInsertId();

        // Logged against the owning user (audit_log has no api_key entity type).
        $this->audit->log('user', $userId, 'insert', null,
            ['api_key_id' => $id, 'label' => $label, 'prefix' => ApiKey::displayPrefix($key)], $actor);

        return ['plaintext' => $key, 'row' => $this->findById($id) ?? []];
    }

    /**
     * Resolve a presented key to its owning user + role, or null when the key is
     * missing/malformed/revoked or the owner is deactivated. Touches last_used_at
     * on a successful match.
     *
     * @return array{key_id:int, user_id:int, email:string, display_name:?string, role:string}|null
     */
    public function verify(?string $presented): ?array
    {
        if ($presented === null || !ApiKey::looksValid($presented)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT k.id AS key_id, u.user_id, u.email, u.display_name, u.role
               FROM api_key k
               JOIN app_user u ON u.user_id = k.user_id
              WHERE k.token_hash = :hash
                AND k.revoked_at IS NULL
                AND u.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([':hash' => ApiKey::hash($presented)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $this->db->prepare('UPDATE api_key SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => (int) $row['key_id']]);

        return [
            'key_id'       => (int) $row['key_id'],
            'user_id'      => (int) $row['user_id'],
            'email'        => (string) $row['email'],
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'role'         => (string) $row['role'],
        ];
    }

    /** Active + revoked keys for one user (secrets never returned — only metadata). */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, token_prefix, last_used_at, created_at, revoked_at
               FROM api_key WHERE user_id = :uid
              ORDER BY (revoked_at IS NOT NULL), created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** All keys with owner email (admin oversight). */
    public function listAll(): array
    {
        return $this->db->query(
            'SELECT k.id, k.label, k.token_prefix, k.last_used_at, k.created_at, k.revoked_at,
                    u.email AS owner_email, u.role AS owner_role
               FROM api_key k JOIN app_user u ON u.user_id = k.user_id
              ORDER BY (k.revoked_at IS NOT NULL), k.created_at DESC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM api_key WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Revoke a key. When $ownerId is given the key must belong to that user
     * (self-service guard); pass null for an admin revoking any key. Returns true
     * if a key transitioned active -> revoked.
     */
    public function revoke(int $keyId, ?int $ownerId, string $actor): bool
    {
        $sql = 'UPDATE api_key SET revoked_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND revoked_at IS NULL';
        $params = [':id' => $keyId];
        if ($ownerId !== null) {
            $sql .= ' AND user_id = :uid';
            $params[':uid'] = $ownerId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $revoked = $stmt->rowCount() > 0;

        if ($revoked) {
            $row = $this->findById($keyId);
            $this->audit->log('user', (int) ($row['user_id'] ?? 0), 'update', null,
                ['api_key_id' => $keyId, 'action' => 'revoke', 'prefix' => $row['token_prefix'] ?? null], $actor);
        }
        return $revoked;
    }
}
