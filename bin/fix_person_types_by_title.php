<?php

declare(strict_types=1);

/**
 * ONE-TIME maintenance: reclassify person.person_type from the primary job TITLE
 * for the four title-driven categories the Adaxes reconciler cares about. Fixes
 * records the feed mis-typed — e.g. a Substitute or Bus Aide imported as generic
 * 'staff'/'faculty' — so their person_type matches what their title says they are.
 *
 *   php bin/fix_person_types_by_title.php --dry-run   # preview, change nothing
 *   php bin/fix_person_types_by_title.php             # apply
 *   php bin/fix_person_types_by_title.php --limit=50 --dry-run
 *
 * Title -> person_type (FIRST match wins, mirroring the reconciler's placement
 * precedence in AdaxesReconciler::placement(): transportation, then SRO, then
 * substitute, then intern):
 *
 *   transportation  Bus Driver/Aide/Monitor, + AD_TRANSPORTATION_TITLES   -> staff
 *   SRO             "SRO", "School Resource Officer"                       -> contractor
 *   substitute      "Substitute", "Long-term Substitute"                  -> sub
 *   intern          "Intern", "Student Intern"                            -> intern
 *
 * Only people whose primary title matches a category AND whose current
 * person_type differs are updated; everyone else is left untouched. Idempotent
 * (a second run changes nothing) and fully audited (audit_log + lifecycle_event,
 * actor system:fix_person_types). --dry-run previews without writing.
 *
 * The title regexes intentionally mirror AdaxesReconciler's isTransportation /
 * isSro / isSubstitute so this backfill and the live sync agree on who is what.
 */

use App\Config;
use App\Db;
use App\Service\AuditService;

require __DIR__ . '/../src/bootstrap.php';

const ACTOR = 'system:fix_person_types';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$limit  = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;

// Extra transportation title keywords (beyond "bus"), mirroring the reconciler's
// AD_TRANSPORTATION_TITLES knob (e.g. a mechanic or dispatcher).
$extraTransport = array_values(array_filter(
    array_map('trim', explode(',', (string) Config::get('AD_TRANSPORTATION_TITLES', ''))),
    static fn($s) => $s !== '',
));

/**
 * Map a job title to the person_type it implies, or null when the title is not
 * one of the four managed categories. Precedence matches the reconciler.
 */
$classify = static function (string $title) use ($extraTransport): ?string {
    $t = trim($title);
    if ($t === '') {
        return null;
    }
    // Transportation: "bus" as a whole word (so "Business" never matches), plus
    // any configured extra keywords. Bus Drivers/Aides/Monitors are district staff.
    if (preg_match('/\bbus\b/i', $t)) {
        return 'staff';
    }
    foreach ($extraTransport as $kw) {
        if (stripos($t, $kw) !== false) {
            return 'staff';
        }
    }
    // SRO: contracted law enforcement.
    if (preg_match('/\bSRO\b|school\s+resource\s+officer/i', $t)) {
        return 'contractor';
    }
    // Substitute (whole word so it never false-matches an unrelated title).
    if (preg_match('/\bsubstitutes?\b/i', $t)) {
        return 'sub';
    }
    // Intern (whole word "Intern"/"Interns" — NOT "Internal"/"International"/
    // "Internship Coordinator").
    if (preg_match('/\binterns?\b/i', $t)) {
        return 'intern';
    }
    return null;
};

try {
    $db    = Db::connect(Db::ROLE_APP);
    $audit = new AuditService($db);

    // Title comes from the primary assignment (same rule the reconciler uses).
    $sql = "SELECT p.person_id, p.first_name, p.last_name, p.person_type,
                   (SELECT a.title FROM assignment a WHERE a.person_id = p.person_id
                     ORDER BY a.is_primary DESC, a.id LIMIT 1) AS title
            FROM person p
            ORDER BY p.person_id";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $people = $db->query($sql)->fetchAll();

    $update = $db->prepare('UPDATE person SET person_type = :t, updated_by = :actor WHERE person_id = :id');

    echo 'Reclassify person_type by title' . ($dryRun ? ' (DRY RUN)' : '') . "\n\n";

    $examined = count($people);
    $changed  = 0;
    $counts   = [];

    foreach ($people as $p) {
        $target = $classify((string) ($p['title'] ?? ''));
        if ($target === null) {
            continue; // title is not one of the managed categories
        }
        $current = (string) $p['person_type'];
        if ($current === $target) {
            continue; // already correctly typed
        }

        $name = trim((string) $p['first_name'] . ' ' . (string) $p['last_name']);
        printf("  #%-6d %-26s %-38s %-10s -> %s\n",
            (int) $p['person_id'], substr($name, 0, 26), substr((string) $p['title'], 0, 38), $current, $target);

        if (!$dryRun) {
            $update->execute([':t' => $target, ':actor' => ACTOR, ':id' => (int) $p['person_id']]);
            $audit->log('person', (int) $p['person_id'], 'update', ['person_type' => $current], ['person_type' => $target], ACTOR);
            $audit->lifecycle((int) $p['person_id'], 'update', [
                'summary' => 'person_type set to ' . $target . ' from title "' . trim((string) $p['title']) . '" (title-based reclassification).',
            ], ACTOR);
        }

        $counts[$target] = ($counts[$target] ?? 0) + 1;
        $changed++;
    }

    echo "\n" . ($dryRun ? '[DRY RUN] would reclassify ' : 'reclassified ') . $changed . ' of ' . $examined . " people\n";
    ksort($counts);
    foreach ($counts as $type => $n) {
        echo '  -> ' . $type . ': ' . $n . "\n";
    }
    if ($dryRun && $changed > 0) {
        echo "\nRe-run without --dry-run to apply.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fix_person_types failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
