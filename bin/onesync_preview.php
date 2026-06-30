<?php

declare(strict_types=1);

/**
 * Preview exactly what OneSync sees — connects as the READ-ONLY onesync_ro role
 * and selects from v_onesync_source. Verifies the least-privilege grant works
 * and that the view returns one row per person.
 *
 *   php bin/onesync_preview.php [--limit=20]
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
$limit = max(1, (int) ($opts['limit'] ?? 20));

try {
    $db = Db::connect(Db::ROLE_ONESYNC);
    $total = (int) $db->query('SELECT COUNT(*) FROM v_onesync_source')->fetchColumn();
    $rows = $db->query('SELECT ID, FirstName, LastName, username, Email, HomeSchoolID, PSID, Title FROM v_onesync_source ORDER BY LastName, FirstName LIMIT ' . $limit)->fetchAll();
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not read v_onesync_source as the read-only role: {$e->getMessage()}\n");
    fwrite(STDERR, "Check DB_ONESYNC_USER/PASS and that onesync_ro has SELECT on the view.\n");
    exit(1);
}

printf("v_onesync_source — %d row(s) total (one per person OneSync provisions)\n\n", $total);
printf("  %-10s %-22s %-12s %-28s %-7s %-8s %s\n", 'ID', 'Name', 'Username', 'Email', 'School', 'PSID', 'Title');
foreach ($rows as $r) {
    printf("  %-10s %-22s %-12s %-28s %-7s %-8s %s\n",
        substr((string) $r['ID'], 0, 8),
        substr(trim($r['FirstName'] . ' ' . $r['LastName']), 0, 22),
        $r['username'] ?? '(blank)',
        substr((string) ($r['Email'] ?? ''), 0, 28),
        (string) ($r['HomeSchoolID'] ?? '—'),
        (string) ($r['PSID'] ?? '—'),
        (string) ($r['Title'] ?? '')
    );
}
if ($total > $limit) {
    printf("\n  … %d more. Use --limit=N to see more.\n", $total - $limit);
}
