<?php

declare(strict_types=1);

/**
 * Tiny migration runner.
 *
 *   php bin/migrate.php            apply all pending migrations
 *   php bin/migrate.php --status   show applied / pending, change nothing
 *   php bin/migrate.php --dry-run  print what WOULD run, change nothing
 *
 * Migrations are db/migrations/*.sql applied in filename order. Applied versions
 * are recorded in `schema_migrations` so re-runs are no-ops (idempotent).
 * Connects as the MIGRATE role (schema owner) and creates the database if needed.
 */

use App\Config;
use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$statusOnly = in_array('--status', $args, true);

$migrationsDir = __DIR__ . '/../db/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No migration files found in {$migrationsDir}\n");
    exit(1);
}

try {
    $dbName = Config::require('DB_NAME');

    // Ensure the database exists, then connect into it as the schema owner.
    $server = Db::connectServer(Db::ROLE_MIGRATE);
    if (!$dryRun) {
        // Identifier can't be bound; DB_NAME is operator-controlled config, and we
        // hard-validate it to a safe charset before interpolating.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException("Unsafe DB_NAME: {$dbName}");
        }
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $pdo = Db::connect(Db::ROLE_MIGRATE);

    // Tracking table.
    if (!$dryRun) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(255) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    $applied = [];
    try {
        $rows = $pdo->query('SELECT version FROM schema_migrations')->fetchAll();
        foreach ($rows as $r) {
            $applied[$r['version']] = true;
        }
    } catch (\PDOException) {
        // Table doesn't exist yet (dry-run on a fresh DB) — treat as none applied.
    }

    $pending = [];
    foreach ($files as $file) {
        $version = basename($file);
        if (!isset($applied[$version])) {
            $pending[] = $file;
        }
    }

    if ($statusOnly) {
        echo "Applied migrations:\n";
        echo $applied === [] ? "  (none)\n" : '  ' . implode("\n  ", array_keys($applied)) . "\n";
        echo "Pending migrations:\n";
        echo $pending === [] ? "  (none)\n" : '  ' . implode("\n  ", array_map('basename', $pending)) . "\n";
        exit(0);
    }

    if ($pending === []) {
        echo "Nothing to do — database is up to date.\n";
        exit(0);
    }

    $insert = $dryRun ? null : $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');

    foreach ($pending as $file) {
        $version = basename($file);
        $statements = splitSqlStatements((string) file_get_contents($file));

        if ($dryRun) {
            echo "[dry-run] {$version}: " . count($statements) . " statement(s) would run\n";
            continue;
        }

        echo "Applying {$version} (" . count($statements) . " statement(s))... ";
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            // Record inside the same transaction. NOTE: MySQL DDL auto-commits, so
            // a mid-file failure can't be fully rolled back — migrations are
            // authored to be additive and each runs at most once.
            $insert->execute([$version]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            echo "ok\n";
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, "\nFAILED on {$version}: {$e->getMessage()}\n");
            exit(1);
        }
    }

    echo $dryRun ? "Dry run complete.\n" : "Migrations complete.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Split a .sql file into individual statements. Strips line/inline `--` comments
 * (none of our schema embeds `--` inside string literals) and splits on `;`.
 */
function splitSqlStatements(string $sql): array
{
    $clean = [];
    foreach (explode("\n", $sql) as $line) {
        $pos = strpos($line, '--');
        if ($pos !== false) {
            $line = substr($line, 0, $pos);
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    $statements = [];
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }
    return $statements;
}
