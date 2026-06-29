<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;
use PDO;

/**
 * Authentication + RBAC.
 *
 * Identity comes from the district IdP via SAML; this maps the SAML NameID/email
 * to an app_user + role and tracks the session. First-login users are created
 * `readonly` (pending an admin grant) unless their email is in ADMIN_EMAILS.
 *
 * Roles (rank): readonly(1) < editor(2) < admin(3).
 * Capabilities: 'view' (all), 'edit' (editor+), 'admin' (admin only).
 */
final class AuthService
{
    private const RANK = ['readonly' => 1, 'editor' => 2, 'admin' => 3];
    private const CAP_MIN = ['view' => 1, 'edit' => 2, 'admin' => 3];

    private PDO $db;
    private AuditService $audit;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_APP);
        $this->audit = $audit ?? new AuditService($this->db);
    }

    /** The signed-in user (session), or null. */
    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user']['user_id']);
    }

    public function role(): string
    {
        return $_SESSION['user']['role'] ?? 'readonly';
    }

    /** Whether the current user has a capability. */
    public function can(string $capability): bool
    {
        return self::roleHasCapability($this->role(), $capability);
    }

    /** Pure capability check — unit tested. */
    public static function roleHasCapability(string $role, string $capability): bool
    {
        $rank = self::RANK[$role] ?? 0;
        $need = self::CAP_MIN[$capability] ?? 99;
        return $rank >= $need;
    }

    public static function isValidRole(string $role): bool
    {
        return isset(self::RANK[$role]);
    }

    /** SAML usable only when the IdP is fully configured. */
    public function isSamlConfigured(): bool
    {
        // The IdP signing cert may be inline or supplied as a PEM file.
        $certFile = (string) Config::get('SAML_IDP_X509_CERT_FILE', '');
        $certPresent = Config::get('SAML_IDP_X509_CERT') !== null
            || ($certFile !== '' && is_file($certFile) && is_readable($certFile));

        return Config::get('SAML_IDP_ENTITY_ID') !== null
            && Config::get('SAML_IDP_SSO_URL') !== null
            && $certPresent;
    }

    /** Dev login is allowed ONLY outside production and when SAML isn't configured. */
    public function devLoginAllowed(): bool
    {
        return strtolower((string) Config::get('APP_ENV', 'development')) !== 'production'
            && !$this->isSamlConfigured();
    }

    /**
     * Resolve (or provision) an app_user from IdP attributes.
     * Returns the user row, or null if the account exists but is deactivated.
     */
    public function findOrCreateUser(string $nameId, string $email, ?string $displayName): ?array
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare(
            'SELECT * FROM app_user WHERE saml_name_id = :nid OR email = :email LIMIT 1'
        );
        $stmt->execute([':nid' => $nameId, ':email' => $email]);
        $user = $stmt->fetch();

        if ($user !== false) {
            if ((int) $user['is_active'] !== 1) {
                return null; // deactivated — deny
            }
            return $user;
        }

        // First login: readonly unless explicitly an admin email.
        $role = in_array($email, self::adminEmails(), true) ? 'admin' : 'readonly';
        $ins = $this->db->prepare(
            'INSERT INTO app_user (saml_name_id, email, display_name, role)
             VALUES (:nid, :email, :name, :role)'
        );
        $ins->execute([':nid' => $nameId, ':email' => $email, ':name' => $displayName, ':role' => $role]);
        $id = (int) $this->db->lastInsertId();

        $this->audit->log('user', $id, 'insert', null,
            ['email' => $email, 'role' => $role, 'origin' => 'first-login'], $email);

        return $this->findById($id);
    }

    /** Establish the session for a user (called after IdP/dev verification). */
    public function login(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // prevent fixation
        }
        $_SESSION['user'] = [
            'user_id'      => (int) $user['user_id'],
            'email'        => $user['email'],
            'display_name' => $user['display_name'] ?: $user['email'],
            'role'         => $user['role'],
        ];
        $this->db->prepare('UPDATE app_user SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = :id')
            ->execute([':id' => (int) $user['user_id']]);
        $this->audit->log('user', (int) $user['user_id'], 'login', null, ['email' => $user['email']], $user['email']);
    }

    public function logout(): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->audit->log('user', (int) $user['user_id'], 'logout', null, ['email' => $user['email']], $user['email']);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Dev-only: upsert an app_user by email with the chosen role (so RBAC can be
     * exercised without an IdP). Caller MUST gate on devLoginAllowed().
     */
    public function provisionDev(string $email, string $role, ?string $name): array
    {
        return $this->upsertUser($email, $role, $name);
    }

    /** Set (or create) a user's role from a trusted context (CLI / admin UI). */
    public function grantRole(string $email, string $role, ?string $name = null, string $actor = 'system:cli'): array
    {
        $user = $this->upsertUser($email, $role, $name);
        $this->audit->log('user', (int) $user['user_id'], 'update', null,
            ['email' => $user['email'], 'role' => $user['role'], 'origin' => 'grant'], $actor);
        return $user;
    }

    private function upsertUser(string $email, string $role, ?string $name): array
    {
        $email = strtolower(trim($email));
        $role = self::isValidRole($role) ? $role : 'readonly';
        $name = ($name !== null && trim($name) !== '') ? trim($name) : $email;

        $existing = $this->db->prepare('SELECT user_id FROM app_user WHERE email = :email OR saml_name_id = :nid');
        $existing->execute([':email' => $email, ':nid' => $email]);
        $id = $existing->fetchColumn();

        if ($id !== false) {
            $this->db->prepare('UPDATE app_user SET role = :r, display_name = :n, is_active = 1 WHERE user_id = :id')
                ->execute([':r' => $role, ':n' => $name, ':id' => (int) $id]);
            $id = (int) $id;
        } else {
            $this->db->prepare(
                'INSERT INTO app_user (saml_name_id, email, display_name, role) VALUES (:nid, :e, :n, :r)'
            )->execute([':nid' => $email, ':e' => $email, ':n' => $name, ':r' => $role]);
            $id = (int) $this->db->lastInsertId();
        }
        return $this->findById($id);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM app_user WHERE user_id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return string[] lowercased admin emails from config */
    public static function adminEmails(): array
    {
        $raw = (string) Config::get('ADMIN_EMAILS', '');
        return array_values(array_filter(array_map(
            static fn($e) => strtolower(trim($e)),
            explode(',', $raw)
        )));
    }
}
