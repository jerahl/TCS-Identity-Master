<?php

declare(strict_types=1);

/**
 * Inspect the EXTERNAL OneSync database so we can map the tables/columns that
 * hold provisioning results (username minted, per-destination success/failure).
 * Read-only; connects via ONESYNC_DB_* (see .env.example).
 *
 *   php bin/onesync_db_inspect.php                 list tables + row counts
 *   php bin/onesync_db_inspect.php --table=NAME    columns of one table
 *   php bin/onesync_db_inspect.php --table=NAME --sample=5   + sample rows
 *   php bin/onesync_db_inspect.php --search=user   find columns by name across tables
 *   php bin/onesync_db_inspect.php --distinct=os_export_log.actionStatus
 *                                                 value frequency of a column (decode enum ints)
 *
 * --sample prints real data (may include usernames/emails) — use on a trusted
 * terminal. Default is columns only.
 */

use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

try {
    $db = Db::connectOneSyncSource();
    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    echo "OneSync DB: {$dbName}\n\n";

    // Real table list (also used to whitelist --table against injection).
    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'
         ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);

    // --- search columns by name across all tables ---
    if (isset($opts['search'])) {
        $stmt = $db->prepare(
            'SELECT table_name, column_name, column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND column_name LIKE :q
             ORDER BY table_name, ordinal_position'
        );
        $stmt->execute([':q' => '%' . $opts['search'] . '%']);
        $rows = $stmt->fetchAll();
        echo "Columns matching '{$opts['search']}':\n";
        foreach ($rows as $r) {
            printf("  %-32s %-26s %s\n", $r['table_name'], $r['column_name'], $r['column_type']);
        }
        echo '  (' . count($rows) . " match(es))\n";
        exit(0);
    }

    // --- value frequency of a column (decode integer enums) ---
    if (isset($opts['distinct'])) {
        $parts = explode('.', (string) $opts['distinct'], 2);
        if (count($parts) !== 2) {
            fwrite(STDERR, "Use --distinct=table.column\n");
            exit(2);
        }
        [$t, $col] = $parts;
        if (!in_array($t, $tables, true)) {
            fwrite(STDERR, "No such table '{$t}'.\n");
            exit(2);
        }
        $valid = $db->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $valid->execute([':t' => $t, ':c' => $col]);
        if ($valid->fetchColumn() === false) {
            fwrite(STDERR, "No such column '{$col}' on '{$t}'.\n");
            exit(2);
        }
        // $t and $col are validated against information_schema (whitelist).
        echo "Value frequency for {$t}.{$col} (top 50):\n";
        $rows = $db->query(
            "SELECT `{$col}` AS v, COUNT(*) AS n FROM `{$t}` GROUP BY `{$col}` ORDER BY n DESC LIMIT 50"
        )->fetchAll();
        foreach ($rows as $r) {
            printf("  %-40s %10d\n", $r['v'] === null ? '(null)' : (string) $r['v'], (int) $r['n']);
        }
        exit(0);
    }

    // --- one table: columns (+ optional sample rows) ---
    if (isset($opts['table'])) {
        $t = (string) $opts['table'];
        if (!in_array($t, $tables, true)) {
            fwrite(STDERR, "No such table '{$t}'. Run with no args to list tables.\n");
            exit(2);
        }
        $cols = $db->prepare(
            'SELECT column_name, column_type, is_nullable, column_key, column_comment
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position'
        );
        $cols->execute([':t' => $t]);
        echo "Table {$t} — columns:\n";
        foreach ($cols->fetchAll() as $c) {
            printf("  %-28s %-24s %-4s %-4s %s\n",
                $c['column_name'], $c['column_type'],
                $c['is_nullable'] === 'YES' ? 'NULL' : 'NOT',
                $c['column_key'] ?: '', $c['column_comment'] ?: '');
        }

        $sample = (int) ($opts['sample'] ?? 0);
        if ($sample > 0) {
            $sample = min($sample, 50);
            echo "\nSample rows (LIMIT {$sample}):\n";
            // $t is whitelisted against the real table list above.
            foreach ($db->query("SELECT * FROM `{$t}` LIMIT {$sample}")->fetchAll() as $i => $row) {
                echo '  #' . ($i + 1) . ' ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        exit(0);
    }

    // --- default: all tables + row counts ---
    echo count($tables) . " table(s):\n";
    foreach ($tables as $t) {
        $n = (int) $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        printf("  %-40s %8d row(s)\n", $t, $n);
    }
    echo "\nNext: inspect a likely table, e.g.\n";
    echo "  php bin/onesync_db_inspect.php --search=guid\n";
    echo "  php bin/onesync_db_inspect.php --table=<name> --sample=3\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'OneSync DB inspect failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Check ONESYNC_DB_HOST/PORT/NAME/USER/PASS in .env and that the user has SELECT.\n");
    exit(1);
}
