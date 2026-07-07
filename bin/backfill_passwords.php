<?php

declare(strict_types=1);

/**
 * ONE-TIME backfill of initial passwords for accounts OneSync created BEFORE
 * the /api/onesync/password endpoint existed. Run by hand from a trusted shell:
 *
 *   php bin/backfill_passwords.php --file=/path/to/passwords.csv --dry-run
 *   php bin/backfill_passwords.php --file=/path/to/passwords.csv [--unlink]
 *
 * CSV: header row required. Each row needs `password` plus `uniqueId` (person
 * UUID — preferred) or `username`. Header names are case-insensitive; common
 * aliases (uuid/id, user, temp_password) are accepted — including `AD Login` /
 * `AD Password`, so the HR personnel-action (board approval) spreadsheet can be
 * fed in as-is; its other columns are ignored, and rows without both values
 * (e.g. transfers with no new account) are skipped.
 *
 * Passwords are encrypted (libsodium, CREDENTIAL_ENC_KEY) before the DB write
 * and are never printed or logged by this script. THE CSV ITSELF IS THE RISK:
 * keep it out of feed/backup/synced directories, and delete it as soon as the
 * import succeeds — --unlink does that for you (best-effort at the storage
 * layer; journals/snapshots may retain blocks).
 *
 * Always --dry-run first: it shows exactly who matches without writing.
 */

use App\Import\InitialPasswordImporter;
use App\Support\Crypto;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$file = (string) ($opts['file'] ?? '');
$dryRun = isset($opts['dry-run']);
$unlink = isset($opts['unlink']);

if ($file === '') {
    fwrite(STDERR, "Usage: php bin/backfill_passwords.php --file=/path/to/passwords.csv [--dry-run] [--unlink]\n");
    exit(1);
}
if (!Crypto::configured()) {
    fwrite(STDERR, 'Backfill refused: ' . Crypto::KEY_ENV . " is not set (64 hex chars; `openssl rand -hex 32`).\n"
        . "Without it passwords can't be encrypted for storage.\n");
    exit(1);
}

try {
    $result = (new InitialPasswordImporter())->run($file, $dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Password backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Initial-password backfill' . ($dryRun ? " (DRY RUN — nothing written)\n" : "\n");
foreach ($result['outcomes'] as $o) {
    $who = $o['uuid'] !== '' ? $o['uuid'] : ($o['username'] !== '' ? $o['username'] : '(no key)');
    printf("  [%-9s] %-36s %s\n", strtoupper($o['outcome']), $who, $o['detail']);
}
$c = $result['counts'];
echo "\n  rows {$c['total']}  ·  applied {$c['applied']}  ·  skipped {$c['skipped']}"
    . "  ·  no-person {$c['no_person']}  ·  errors {$c['errors']}\n";

if (!$dryRun && $c['errors'] === 0 && $unlink) {
    if (@unlink($file)) {
        echo "\n  CSV deleted: {$file}\n";
    } else {
        fwrite(STDERR, "\n  Could not delete {$file} — remove it yourself NOW; it holds plaintext passwords.\n");
    }
} elseif (!$dryRun) {
    echo "\n  REMINDER: {$file} holds plaintext passwords — delete it now that it's imported\n"
        . "  (re-run with --unlink to have this script do it).\n";
}

exit($c['errors'] > 0 ? 1 : 0);
