<?php

declare(strict_types=1);

/**
 * ONE-TIME import: reclassify substitutes that live in AD's OU=Subs but were
 * typed wrong in IDM, using an Adaxes reconciler dry-run report as the source.
 *
 *   php bin/reclass_subs_from_report.php --file=/path/to/adaxes-dry-run.txt --dry-run
 *   php bin/reclass_subs_from_report.php --file=/path/to/adaxes-dry-run.txt
 *
 * For every person the report shows moving OUT of OU=Subs (a
 * "[WOULD-MOVE] … move OU=Subs,…" line — i.e. their live AD account is currently
 * in the Subs OU, but IDM would relocate them because it doesn't know they're a
 * sub), this:
 *
 *   1. sets person.person_type = 'sub'
 *   2. sets their primary assignment title to the account's live AD description
 *
 * Why the report is a complete source for the AD description: the reconciler's
 * edit phase only emits a "description: X → Y" drift when AD's description (X)
 * differs from the IDM-derived title mirror. So for anyone shown moving out of
 * OU=Subs:
 *   - if the report has a "description: X → …" line, X IS the live AD description
 *     and differs from the current IDM title → set the title to X;
 *   - if there is no description drift, AD's description already equals the
 *     current IDM title → the title is already what's in AD, nothing to change.
 * An empty/"(unset)" AD description is left alone (never blank out a title).
 *
 * Idempotent and audited (audit_log + lifecycle_event, actor
 * system:reclass_ou_subs). --dry-run previews without writing. After applying,
 * the next Adaxes sync keeps these accounts in OU=Subs instead of moving them.
 */

use App\Db;
use App\Service\AuditService;

require __DIR__ . '/../src/bootstrap.php';

const ACTOR = 'system:reclass_ou_subs';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$file   = (string) ($opts['file'] ?? '');

if ($file === '' || !is_readable($file)) {
    fwrite(STDERR, "Usage: php bin/reclass_subs_from_report.php --file=<adaxes dry-run report> [--dry-run]\n");
    fwrite(STDERR, $file === '' ? "  --file is required.\n" : "  Cannot read file: {$file}\n");
    exit(2);
}

$lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

// Pass 1: index the report.
//   $cohort[id]      = display name       (people moving OUT of OU=Subs)
//   $adDescription[id] = live AD description (left side of a description drift)
$cohort = [];
$adDescription = [];
foreach ($lines as $line) {
    // A move whose CURRENT container is the Subs OU: "… move OU=Subs,… → …".
    if (preg_match('/\[WOULD-MOVE\s*\]\s+#(\d+)\s+(.+?)\s{2,}move\s+OU=Subs,/i', $line, $m)) {
        $cohort[(int) $m[1]] = trim($m[2]);
        continue;
    }
    // A description drift carries the live AD value on the LEFT of the arrow.
    // Lazy capture stops at the first " → " so a trailing attr never leaks in.
    if (preg_match('/\[WOULD-EDIT\s*\]\s+#(\d+)\b.*?\bdescription:\s+(.+?)\s+→\s/u', $line, $m)) {
        $adDescription[(int) $m[1]] = trim($m[2]);
    }
}

if ($cohort === []) {
    fwrite(STDERR, "No '[WOULD-MOVE] … move OU=Subs,…' rows found in {$file}. Nothing to do.\n");
    exit(0);
}

/** An AD description worth writing as a title — non-empty and not the "(unset)" marker. */
$usableDescription = static function (int $id) use ($adDescription): ?string {
    $d = trim((string) ($adDescription[$id] ?? ''));
    if ($d === '' || strcasecmp($d, '(unset)') === 0) {
        return null;
    }
    return $d;
};

echo 'Reclassify OU=Subs members as person_type=sub' . ($dryRun ? ' (DRY RUN)' : '')
   . ' — ' . count($cohort) . " candidate(s) from " . basename($file) . "\n\n";

try {
    $db    = Db::connect(Db::ROLE_APP);
    $audit = new AuditService($db);

    $selectPerson = $db->prepare('SELECT person_type FROM person WHERE person_id = :id');
    // Primary assignment (same rule the reconciler uses to read a person's title).
    $selectAssignment = $db->prepare(
        'SELECT id, title FROM assignment WHERE person_id = :id ORDER BY is_primary DESC, id LIMIT 1'
    );
    $updateType  = $db->prepare('UPDATE person SET person_type = :t, updated_by = :actor WHERE person_id = :id');
    $updateTitle = $db->prepare('UPDATE assignment SET title = :title WHERE id = :aid');

    $examined = 0;
    $typed    = 0;
    $retitled = 0;
    $missing  = 0;

    ksort($cohort);
    foreach ($cohort as $id => $name) {
        $examined++;

        $selectPerson->execute([':id' => $id]);
        $currentType = $selectPerson->fetchColumn();
        if ($currentType === false) {
            $missing++;
            printf("  #%-6d %-28s SKIP — no such person in IDM\n", $id, substr($name, 0, 28));
            continue;
        }
        $currentType = (string) $currentType;

        $selectAssignment->execute([':id' => $id]);
        $assignment   = $selectAssignment->fetch();
        $currentTitle = $assignment !== false ? (string) ($assignment['title'] ?? '') : '';

        $newTitle   = $usableDescription($id);           // null = leave title as-is
        $typeChange  = $currentType !== 'sub';
        $titleChange = $newTitle !== null && $assignment !== false && trim($newTitle) !== trim($currentTitle);

        if (!$typeChange && !$titleChange) {
            continue; // already correct — no noise
        }

        $parts = [];
        if ($typeChange) {
            $parts[] = "type {$currentType} → sub";
        }
        if ($titleChange) {
            $parts[] = "title \"{$currentTitle}\" → \"{$newTitle}\"";
        } elseif ($newTitle === null && $typeChange) {
            $parts[] = 'title unchanged (no AD description in report)';
        }
        printf("  #%-6d %-28s %s\n", $id, substr($name, 0, 28), implode('; ', $parts));

        if ($dryRun) {
            $typed    += $typeChange ? 1 : 0;
            $retitled += $titleChange ? 1 : 0;
            continue;
        }

        $before = ['person_type' => $currentType, 'title' => $currentTitle];
        if ($typeChange) {
            $updateType->execute([':t' => 'sub', ':actor' => ACTOR, ':id' => $id]);
            $typed++;
        }
        if ($titleChange) {
            $updateTitle->execute([':title' => $newTitle, ':aid' => (int) $assignment['id']]);
            $retitled++;
        }
        $after = ['person_type' => 'sub', 'title' => $titleChange ? $newTitle : $currentTitle];

        $audit->log('person', $id, 'update', $before, $after, ACTOR);
        $audit->lifecycle($id, 'update', [
            'summary' => 'Reclassified as substitute (in AD OU=Subs): '
                . ($typeChange ? 'person_type → sub' : 'person_type already sub')
                . ($titleChange ? ', title set from AD description to "' . $newTitle . '"' : '')
                . '.',
        ], ACTOR);
    }

    echo "\n" . ($dryRun ? '[DRY RUN] would update ' : 'updated ')
       . ($examined - $missing) . ' of ' . $examined . " people\n";
    echo '  -> person_type set to sub: ' . $typed . "\n";
    echo '  -> title set from AD description: ' . $retitled . "\n";
    if ($missing > 0) {
        echo '  -> not found in IDM (skipped): ' . $missing . "\n";
    }
    if ($dryRun && ($typed > 0 || $retitled > 0)) {
        echo "\nRe-run without --dry-run to apply.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'reclass_subs failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
