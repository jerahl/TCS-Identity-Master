<?php

declare(strict_types=1);

/**
 * ONE-TIME DIAGNOSTIC — test WRITE access over the PowerSchool ODBC
 * connection (PS_ODBC_DSN / PS_ODBC_USER, same connection the nightly import
 * reads through). Delete this script once you have your answer.
 *
 * Safe by design — it never persists a change:
 *   1. connects and runs a read check (COUNT on the users table);
 *   2. picks ONE row and runs a NO-OP self-assignment
 *        UPDATE users SET last_name = last_name WHERE dcid = :dcid
 *      inside a transaction — the value written is the value already there;
 *   3. ALWAYS rolls the transaction back, then re-reads the row to verify it
 *      is unchanged.
 * If the ODBC user has no write privilege, step 2 fails with the Oracle error
 * (typically ORA-01031 insufficient privileges, or a driver read-only error)
 * and that IS the answer.
 *
 *   php bin/test_ps_odbc_write.php                        test against users.last_name
 *   php bin/test_ps_odbc_write.php --table=teachers --column=title --key-column=dcid
 *   php bin/test_ps_odbc_write.php --key=12345            target a specific dcid
 *   php bin/test_ps_odbc_write.php --force-autocommit     run even if the driver
 *                                                         can't do transactions
 *                                                         (still a no-op update)
 *
 * Exit codes: 0 = write access confirmed · 1 = connect/read failed ·
 *             2 = write denied · 3 = driver can't do transactions (rerun with
 *             --force-autocommit if a no-op autocommitted update is acceptable)
 */

use App\Config;
use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

$table = (string) ($opts['table'] ?? 'users');
$column = (string) ($opts['column'] ?? 'last_name');
$keyColumn = (string) ($opts['key-column'] ?? 'dcid');
$key = isset($opts['key']) && $opts['key'] !== '1' ? (string) $opts['key'] : null;
$forceAutocommit = isset($opts['force-autocommit']);

foreach (['table' => $table, 'column' => $column, 'key-column' => $keyColumn] as $optName => $ident) {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_$]*$/', $ident)) {
        fwrite(STDERR, "Invalid --{$optName} identifier: {$ident}\n");
        exit(1);
    }
}
$schema = trim((string) Config::get('PS_ODBC_SCHEMA', ''));
$qualified = ($schema !== '' ? $schema . '.' : '') . $table;

printf("PowerSchool ODBC write-access test  (no-op self-assignment + rollback)\n");
printf("  DSN:    %s\n", (string) Config::get('PS_ODBC_DSN', '(unset)'));
printf("  user:   %s\n", (string) Config::get('PS_ODBC_USER', '(unset)'));
printf("  target: %s.%s (keyed by %s)\n\n", $qualified, $column, $keyColumn);

// 1. Connect + read check.
try {
    $db = Db::connectPowerSchoolSource();
    $count = $db->query("SELECT COUNT(*) AS n FROM {$qualified}")->fetch();
    printf("  [ok] connected; read check passed (%s rows in %s)\n", (string) $count['n'], $qualified);
} catch (\Throwable $e) {
    fwrite(STDERR, '  [FAIL] connect/read failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// 2. Pick the target row (any one row unless --key was given).
try {
    if ($key === null) {
        $row = $db->query(
            "SELECT {$keyColumn} AS k, {$column} AS v FROM {$qualified} WHERE ROWNUM = 1"
        )->fetch();
    } else {
        $stmt = $db->prepare(
            "SELECT {$keyColumn} AS k, {$column} AS v FROM {$qualified} WHERE {$keyColumn} = ?"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();
    }
    if ($row === false) {
        fwrite(STDERR, "  [FAIL] no target row found in {$qualified}"
            . ($key !== null ? " for {$keyColumn} = {$key}" : '') . "\n");
        exit(1);
    }
    $key = (string) $row['k'];
    $before = $row['v'];
    printf("  [ok] target row: %s = %s\n", $keyColumn, $key);
} catch (\Throwable $e) {
    fwrite(STDERR, '  [FAIL] could not select a target row: ' . $e->getMessage() . "\n");
    exit(1);
}

// 3. Transaction guard — refuse to touch anything without one unless forced.
$hasTxn = false;
try {
    $hasTxn = $db->beginTransaction();
} catch (\Throwable $e) {
    // fall through to the check below
}
if (!$hasTxn && !$forceAutocommit) {
    fwrite(STDERR, "  [STOP] the ODBC driver would not start a transaction, so the update\n"
        . "         could not be rolled back. The planned statement is a no-op\n"
        . "         (SET {$column} = {$column}); rerun with --force-autocommit to accept that.\n");
    exit(3);
}
printf("  [ok] %s\n", $hasTxn ? 'transaction started' : 'NO transaction (forced) — update is still a no-op');

// 4. The write: assign the column to itself on that one row.
try {
    $stmt = $db->prepare(
        "UPDATE {$qualified} SET {$column} = {$column} WHERE {$keyColumn} = ?"
    );
    $stmt->execute([$key]);
    printf("  [ok] UPDATE executed — %d row(s) matched\n", $stmt->rowCount());
} catch (\Throwable $e) {
    if ($hasTxn) {
        try {
            $db->rollBack();
        } catch (\Throwable) {
            // nothing was written; rollback failure is irrelevant here
        }
    }
    printf("\nRESULT: WRITE ACCESS DENIED\n");
    printf("  the UPDATE was refused: %s\n", $e->getMessage());
    exit(2);
}

// 5. Roll back and verify the row is untouched.
if ($hasTxn) {
    $db->rollBack();
    printf("  [ok] rolled back\n");
}
try {
    $stmt = $db->prepare("SELECT {$column} AS v FROM {$qualified} WHERE {$keyColumn} = ?");
    $stmt->execute([$key]);
    $after = $stmt->fetch()['v'] ?? null;
    printf("  [%s] post-check: value %s\n",
        $after === $before ? 'ok' : 'WARN',
        $after === $before ? 'unchanged' : 'DIFFERS from before (unexpected for a self-assignment)');
} catch (\Throwable $e) {
    printf("  [WARN] post-check read failed: %s\n", $e->getMessage());
}

printf("\nRESULT: WRITE ACCESS CONFIRMED\n");
printf("  the ODBC user can UPDATE %s — nothing was changed (self-assignment%s).\n",
    $qualified, $hasTxn ? ', rolled back' : '');
exit(0);
