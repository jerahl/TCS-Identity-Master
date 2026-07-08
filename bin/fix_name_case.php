<?php

declare(strict_types=1);

/**
 * Normalize every person's first/last name to "first letter capital" casing,
 * so records imported as "JAMES SMITH" or "james smith" become "James Smith".
 *
 *   php bin/fix_name_case.php --dry-run     # preview, change nothing
 *   php bin/fix_name_case.php               # apply the fixes
 *
 * Only rows whose casing actually changes are written; each change is audited
 * and put on the person's timeline. Idempotent — safe to re-run. Names with
 * special forms (McDonald, O'Brien, Smith-Jones, generational suffix III) are
 * cased conventionally; see App\Support\NameCase.
 */

use App\Import\NameCaseFixer;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);

try {
    $result = (new NameCaseFixer())->run($dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Name-case fix failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Name-case fix' . ($dryRun ? " (DRY RUN)\n" : "\n");
foreach ($result['outcomes'] as $o) {
    printf("  person %-7d %s  ->  %s\n", $o['person_id'], $o['from'], $o['to']);
}
$verb = $dryRun ? 'would fix' : 'fixed';
echo "\n  {$verb} {$result['changed']} name(s) of {$result['scanned']} scanned\n";

exit(0);
