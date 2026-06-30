<?php

declare(strict_types=1);

/**
 * ONE-TIME: clear legacy AD ids ("T#####") from the crosswalk.
 *
 *   php bin/cleanup_ad_ids.php --dry-run        # preview, change nothing
 *   php bin/cleanup_ad_ids.php                  # remove legacy ids where a GUID exists
 *   php bin/cleanup_ad_ids.php --all            # also remove legacy ids that are the only AD id
 *
 * Some people carry two `person_source_id` rows under system 'ad' — the early
 * import's uniqueId ("T" + TEACHERS.ID, e.g. T13305) and the Employee List
 * import's real objectGUID. The objectGUID is what live verification uses, so
 * this drops the legacy uniqueId. By default it keeps a person's legacy id when
 * it's their ONLY AD id (so they aren't left unlinked); --all removes those too.
 */

use App\Import\AdIdCleanup;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$all = isset($opts['all']);

try {
    $result = (new AdIdCleanup())->run($dryRun, $all);
} catch (\Throwable $e) {
    fwrite(STDERR, 'AD id cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'AD id cleanup' . ($dryRun ? " (DRY RUN)\n" : "\n");
foreach ($result['outcomes'] as $o) {
    printf("  [%-16s] person %-7d %s\n", $o['action'], $o['person_id'], $o['detail']);
}
$verb = $dryRun ? 'would remove' : 'removed';
echo "\n  {$verb} {$result['removed']} legacy id(s) across {$result['persons']} person(s)"
    . "  ·  kept {$result['kept']}  ·  only-legacy left {$result['orphans']}"
    . ($result['orphans'] > 0 && !$all ? " (run --all to remove those too)" : '') . "\n";

exit(0);
