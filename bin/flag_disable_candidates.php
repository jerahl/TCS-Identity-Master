<?php

declare(strict_types=1);

/**
 * List people who should be reviewed for disabling: NOT in NextGen (no active
 * NextGen crosswalk id — manual contractors/interns/subs, or anyone dropped off
 * the feed), still enabled (active/pending), whose exit date (person.end_date)
 * is already in the past OR who dropped from the NextGen feed more than
 * NEXTGEN_DROPOUT_FLAG_DAYS days ago (regardless of exit date).
 *
 * NextGen drives disable for its own people, but never touches off-feed records,
 * so these would otherwise linger enabled forever. This is a READ-ONLY report —
 * it changes nothing; an admin reviews and disables (which makes OneSync disable,
 * not orphan, the account). The same list is shown on the dashboard.
 *
 *   php bin/flag_disable_candidates.php [--limit=500]
 *
 * Exit code is 0 when there are none to flag, 1 when there are (so a cron/monitor
 * can alert on it).
 */

use App\Service\ReviewService;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$limit = max(1, (int) ($opts['limit'] ?? 500));

try {
    $rows = (new ReviewService())->disableCandidates($limit);
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not query disable candidates: {$e->getMessage()}\n");
    exit(2);
}

if ($rows === []) {
    echo "No people to flag — nothing is off-NextGen with a past exit date and still enabled.\n";
    exit(0);
}

printf("%d person(s) not in NextGen, still enabled — review to disable:\n\n", count($rows));
printf("  %-24s %-12s %-12s %-14s %-9s %s\n", 'Name', 'Type', 'Exit date', 'Off NextGen', 'Status', 'Source');
foreach ($rows as $r) {
    printf("  %-24s %-12s %-12s %-14s %-9s %s\n",
        substr(trim($r['first_name'] . ' ' . $r['last_name']), 0, 24),
        (string) $r['person_type'],
        (string) ($r['end_date'] ?? '—'),
        $r['nextgen_last_seen'] ? substr((string) $r['nextgen_last_seen'], 0, 10) : '—',
        (string) $r['status'],
        (string) $r['source_of_record']
    );
}
echo "\nReview each on the review queue (\"Not in NextGen — review to disable\" panel) and disable if they have truly left.\n";
exit(1);
