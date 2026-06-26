<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * PDO connection factory.
 *
 * One DB host, several least-privilege ROLES (see .env.example):
 *   - app:        the dashboard/web app
 *   - migrate:    schema owner (DDL) — bin/migrate.php only
 *   - writeback:  limited writer for the OneSync write-back importers
 *   - onesync:    READ-ONLY on v_onesync_source (handed to OneSync's ODBC source)
 *
 * Every connection uses utf8mb4, throws on error, and returns real prepared
 * statements (no emulation) — the whole app relies on prepared statements, never
 * string-built SQL.
 */
final class Db
{
    public const ROLE_APP = 'app';
    public const ROLE_MIGRATE = 'migrate';
    public const ROLE_WRITEBACK = 'writeback';
    public const ROLE_ONESYNC = 'onesync';

    /** Cached connections, keyed by role. */
    private static array $connections = [];

    /** Map a role to its (env user, env pass) keys. */
    private const ROLE_KEYS = [
        self::ROLE_APP       => ['DB_APP_USER', 'DB_APP_PASS'],
        self::ROLE_MIGRATE   => ['DB_MIGRATE_USER', 'DB_MIGRATE_PASS'],
        self::ROLE_WRITEBACK => ['DB_WRITEBACK_USER', 'DB_WRITEBACK_PASS'],
        self::ROLE_ONESYNC   => ['DB_ONESYNC_USER', 'DB_ONESYNC_PASS'],
    ];

    /** Get (and cache) a PDO connection for the given role. */
    public static function connect(string $role = self::ROLE_APP): PDO
    {
        if (isset(self::$connections[$role])) {
            return self::$connections[$role];
        }

        if (!isset(self::ROLE_KEYS[$role])) {
            throw new RuntimeException("Unknown DB role: {$role}");
        }
        [$userKey, $passKey] = self::ROLE_KEYS[$role];

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::require('DB_NAME');
        $charset = Config::get('DB_CHARSET', 'utf8mb4');
        $user = Config::require($userKey);
        $pass = Config::get($passKey, '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        self::$connections[$role] = $pdo;
        return $pdo;
    }

    /**
     * Connect to the EXTERNAL OneSync database — a separate MariaDB we pull
     * provisioning results from (read-only intent). Configured via ONESYNC_DB_*,
     * independent of our app DB. Grant the user SELECT only.
     */
    public static function connectOneSyncSource(): PDO
    {
        if (isset(self::$connections['onesync_source'])) {
            return self::$connections['onesync_source'];
        }

        $host = Config::require('ONESYNC_DB_HOST');
        $port = Config::get('ONESYNC_DB_PORT', '3306');
        $name = Config::require('ONESYNC_DB_NAME');
        $charset = Config::get('ONESYNC_DB_CHARSET', 'utf8mb4');
        $user = Config::require('ONESYNC_DB_USER');
        $pass = Config::get('ONESYNC_DB_PASS', '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        self::$connections['onesync_source'] = $pdo;
        return $pdo;
    }

    /** Connect without selecting a database (for bootstrapping/creating the schema). */
    public static function connectServer(string $role = self::ROLE_MIGRATE): PDO
    {
        if (!isset(self::ROLE_KEYS[$role])) {
            throw new RuntimeException("Unknown DB role: {$role}");
        }
        [$userKey, $passKey] = self::ROLE_KEYS[$role];

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $charset = Config::get('DB_CHARSET', 'utf8mb4');
        $user = Config::require($userKey);
        $pass = Config::get($passKey, '');

        $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $host, $port, $charset);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
