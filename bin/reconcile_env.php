<?php

declare(strict_types=1);

/**
 * Reconcile .env against .env.example: add any settings that exist in
 * .env.example but are missing from .env, and NEVER touch what's already there.
 *
 *   php bin/reconcile_env.php --dry-run        # show what would be added
 *   php bin/reconcile_env.php                  # append missing keys (backs up .env)
 *   php bin/reconcile_env.php --env=/path/.env --example=/path/.env.example
 *
 * Behavior:
 *  - A "setting" is any line of the form KEY=… — active (KEY=x) or commented
 *    (#KEY=x). Both forms in .env.example are considered; missing ones are
 *    appended VERBATIM (so a required key arrives active with the example's
 *    default, and an optional key arrives commented, exactly as documented),
 *    together with the help comments directly above it in .env.example.
 *  - A key already in .env in ANY form (active OR commented) is left untouched
 *    and never re-added, so your values and deliberate opt-outs are preserved.
 *  - Existing .env content is never modified or reordered — additions are
 *    appended in one clearly-marked block at the end.
 *  - Idempotent: a second run finds nothing to add.
 *
 * Values copied from .env.example may be placeholders (e.g. change-me-*); review
 * the appended block and fill in real values.
 */

date_default_timezone_set('UTC');

$root = dirname(__DIR__);

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun  = isset($opts['dry-run']);
$envPath = (string) ($opts['env'] ?? $root . '/.env');
$exPath  = (string) ($opts['example'] ?? $root . '/.env.example');

if (!is_readable($exPath)) {
    fwrite(STDERR, "Cannot read example file: {$exPath}\n");
    exit(2);
}
if (!is_file($envPath)) {
    fwrite(STDERR, "No .env at {$envPath}. Copy {$exPath} to {$envPath} first (this script only ADDS missing keys).\n");
    exit(2);
}
if (!is_readable($envPath) || !is_writable($envPath)) {
    fwrite(STDERR, "Cannot read/write .env: {$envPath}\n");
    exit(2);
}

/**
 * A setting line "KEY=…" (optionally commented) → the KEY, else null. Keys are
 * UPPER_SNAKE by convention; requiring that avoids matching prose comments that
 * happen to contain "word = …" (e.g. API-doc lines like "#  add = POST …").
 */
$keyOf = static function (string $line): ?string {
    return preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m) ? $m[1] : null;
};

$exampleLines = file($exPath, FILE_IGNORE_NEW_LINES) ?: [];
$envLines     = file($envPath, FILE_IGNORE_NEW_LINES) ?: [];

// Keys already present in .env (any form) — never re-added.
$present = [];
foreach ($envLines as $line) {
    $k = $keyOf($line);
    if ($k !== null) {
        $present[$k] = true;
    }
}

// Walk .env.example, collecting each missing key with the help-comment block
// directly above it (contiguous comment lines, no blank line between).
$missing = [];      // list of ['key'=>, 'block'=>string]
$seenExample = [];  // first definition of a key wins
$pending = [];      // buffer of preceding lines since the last definition
foreach ($exampleLines as $line) {
    $k = $keyOf($line);
    if ($k === null) {
        $pending[] = $line;
        continue;
    }
    if (!isset($seenExample[$k])) {
        $seenExample[$k] = true;
        if (!isset($present[$k])) {
            // Doc = the contiguous run of comment lines immediately above (stop at
            // a blank line or a non-comment), so shared blocks attach to the first
            // key only and aren't duplicated onto following keys.
            $doc = [];
            for ($i = count($pending) - 1; $i >= 0; $i--) {
                if (preg_match('/^\s*#/', $pending[$i])) {
                    $doc[] = $pending[$i];
                } else {
                    break;
                }
            }
            $block = array_merge(array_reverse($doc), [$line]);
            $missing[] = ['key' => $k, 'block' => implode("\n", $block)];
        }
    }
    $pending = [];
}

if ($missing === []) {
    echo ".env is already in sync with .env.example — no missing settings.\n";
    exit(0);
}

echo ($dryRun ? '[DRY RUN] ' : '') . 'Missing ' . count($missing) . " setting(s) from .env.example:\n";
foreach ($missing as $m) {
    echo '  + ' . $m['key'] . "\n";
}

if ($dryRun) {
    echo "\nWould append the following block to {$envPath}:\n";
    echo "-----------------------------------------------------------------\n";
    foreach ($missing as $m) {
        echo $m['block'] . "\n";
    }
    echo "-----------------------------------------------------------------\n";
    echo "\nRe-run without --dry-run to apply.\n";
    exit(0);
}

// Back up, then append the missing block. Existing content is untouched.
$original = (string) file_get_contents($envPath);
$backup = $envPath . '.bak';
if (@copy($envPath, $backup) === false) {
    fwrite(STDERR, "Could not write backup {$backup} — aborting (no changes made).\n");
    exit(1);
}

$header = "\n# ==========================================================================\n"
    . '# Added by bin/reconcile_env.php on ' . gmdate('Y-m-d') . " (UTC) — settings present\n"
    . "# in .env.example but missing here. Review values (some are placeholders) and\n"
    . "# uncomment/fill in as needed. Existing settings above were left untouched.\n"
    . "# ==========================================================================\n";

$append = $header;
foreach ($missing as $m) {
    $append .= $m['block'] . "\n";
}

// Ensure exactly one newline separates the old content from the added block.
$glued = rtrim($original, "\n") . "\n" . $append;
if (@file_put_contents($envPath, $glued, LOCK_EX) === false) {
    fwrite(STDERR, "Failed to write {$envPath}. Your original is safe at {$backup}.\n");
    exit(1);
}

echo "\nAppended " . count($missing) . " setting(s) to {$envPath}. Backup: {$backup}\n";
exit(0);
