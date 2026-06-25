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
    $rows = $db->query('SELECT uniqueId, First_Name, Last_Name, TeacherLoginID, Email_Addr, School_ID, StatusActive, PersonType FROM v_onesync_source ORDER BY Last_Name, First_Name LIMIT ' . $limit)->fetchAll();
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not read v_onesync_source as the read-only role: {$e->getMessage()}\n");
    fwrite(STDERR, "Check DB_ONESYNC_USER/PASS and that onesync_ro has SELECT on the view.\n");
    exit(1);
}

printf("v_onesync_source — %d row(s) total (one per person OneSync provisions)\n\n", $total);
printf("  %-10s %-22s %-12s %-28s %-7s %-6s %s\n", 'uniqueId', 'Name', 'Username', 'Email', 'School', 'Active', 'Type');
foreach ($rows as $r) {
    printf("  %-10s %-22s %-12s %-28s %-7s %-6s %s\n",
        substr((string) $r['uniqueId'], 0, 8),
        substr(trim($r['First_Name'] . ' ' . $r['Last_Name']), 0, 22),
        $r['TeacherLoginID'] ?? '(blank)',
        substr((string) ($r['Email_Addr'] ?? ''), 0, 28),
        (string) ($r['School_ID'] ?? '—'),
        (string) $r['StatusActive'],
        (string) $r['PersonType']
    );
}
if ($total > $limit) {
    printf("\n  … %d more. Use --limit=N to see more.\n", $total - $limit);
}
