<?php

declare(strict_types=1);

/**
 * ONE-TIME maintenance: give Transportation its own building.
 *
 *   php bin/split_transportation_building.php --dry-run   # preview, change nothing
 *   php bin/split_transportation_building.php             # apply
 *
 * NextGen identifies transportation staff by a distinct location code (8410 at
 * TCS, from AD_TRANSPORTATION_SCHOOL_CODES). If that code is just an *alias* on
 * the Central Office school row (which also owns the ordinary CO code, e.g.
 * 8620), then IDM can't tell a transportation employee from any other Central
 * Office employee — both resolve to the same school_id — and the Adaxes sync's
 * location rule flags the whole building as transportation.
 *
 * This splits them apart: it ensures a dedicated "Transportation" school row
 * (ad_ou = AD_OU_TRANSPORTATION, default OU=trans; no PowerSchool id — PowerSchool
 * has no transportation location, those staff come across as SchoolID 0 / Central
 * Office) and repoints each transportation NextGen code's alias onto it. After
 * this, only people NextGen places at 8410 resolve to the Transportation
 * building; Central Office staff (8620) no longer match the transportation rule.
 *
 * Idempotent and audited. Existing transportation employees keep their current
 * building until the next NextGen import re-resolves their location code onto the
 * new Transportation row (their location code is not stored per-person). The
 * immediate effect is that Central Office staff STOP being mis-classified.
 */

use App\Config;
use App\Db;
use App\Service\AuditService;

require __DIR__ . '/../src/bootstrap.php';

const ACTOR = 'system:split_transportation';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);

$transOu = trim((string) (Config::get('AD_OU_TRANSPORTATION', '') ?: Config::get('AD_OU_BUS_DRIVER', 'OU=trans')), " ,");
$codes = array_values(array_filter(
    array_map('trim', explode(',', (string) Config::get('AD_TRANSPORTATION_SCHOOL_CODES', '8410'))),
    static fn($s) => $s !== '',
));

if ($codes === []) {
    fwrite(STDERR, "AD_TRANSPORTATION_SCHOOL_CODES is empty — nothing to split.\n");
    exit(0);
}

echo 'Split Transportation into its own building' . ($dryRun ? ' (DRY RUN)' : '') . "\n";
echo '  transportation OU: ' . $transOu . '  ·  NextGen code(s): ' . implode(', ', $codes) . "\n\n";

try {
    $db    = Db::connect(Db::ROLE_APP);
    $audit = new AuditService($db);

    // 1) Ensure a Transportation school row exists (match by its trans OU, else by
    //    name). Create it with NO PowerSchool id — PowerSchool has no such location.
    $find = $db->prepare("SELECT school_id, name FROM school WHERE ad_ou = :ou OR name = 'Transportation' LIMIT 1");
    $find->execute([':ou' => $transOu]);
    $row = $find->fetch();

    if ($row !== false) {
        $transId = (int) $row['school_id'];
        echo "Transportation building: existing school_id {$transId} (\"{$row['name']}\").\n";
    } elseif ($dryRun) {
        $transId = 0; // not created in a dry run
        echo "Transportation building: would CREATE (name 'Transportation', ad_ou {$transOu}).\n";
    } else {
        $db->prepare('INSERT INTO school (name, ps_school_id, ad_ou, status) VALUES (:n, NULL, :ou, :st)')
            ->execute([':n' => 'Transportation', ':ou' => $transOu, ':st' => 'active']);
        $transId = (int) $db->lastInsertId();
        $audit->log('school', $transId, 'insert', null, ['name' => 'Transportation', 'ad_ou' => $transOu], ACTOR);
        echo "Transportation building: CREATED school_id {$transId} (name 'Transportation', ad_ou {$transOu}).\n";
    }

    // 2) Repoint each transportation NextGen code's alias onto the Transportation row.
    $repointed = 0;
    $created = 0;
    $noop = 0;
    foreach ($codes as $code) {
        $a = $db->prepare("SELECT alias_id, school_id FROM school_code_alias WHERE system = 'nextgen' AND code = :c");
        $a->execute([':c' => $code]);
        $alias = $a->fetch();

        if ($alias !== false && (int) $alias['school_id'] === $transId && $transId !== 0) {
            echo "  8410 alias {$code}: already on Transportation — no change.\n";
            $noop++;
            continue;
        }

        if ($alias !== false) {
            $from = (int) $alias['school_id'];
            echo "  nextgen {$code}: repoint school_id {$from} → " . ($transId ?: '(new)') . "\n";
            if (!$dryRun) {
                $db->prepare('UPDATE school_code_alias SET school_id = :s WHERE alias_id = :id')
                    ->execute([':s' => $transId, ':id' => (int) $alias['alias_id']]);
                $audit->log('school_code_alias', (int) $alias['alias_id'], 'update',
                    ['school_id' => $from, 'code' => $code], ['school_id' => $transId, 'code' => $code], ACTOR);
            }
            $repointed++;
        } else {
            echo "  nextgen {$code}: no alias yet — would create → Transportation\n";
            if (!$dryRun) {
                $db->prepare("INSERT INTO school_code_alias (school_id, system, code) VALUES (:s, 'nextgen', :c)")
                    ->execute([':s' => $transId, ':c' => $code]);
                $audit->log('school_code_alias', (int) $db->lastInsertId(), 'insert', null,
                    ['school_id' => $transId, 'system' => 'nextgen', 'code' => $code], ACTOR);
            }
            $created++;
        }
    }

    echo "\n" . ($dryRun ? '[DRY RUN] ' : '')
        . "aliases repointed {$repointed} · created {$created} · unchanged {$noop}\n";
    echo "Note: existing transportation employees move to the Transportation building on the next NextGen import.\n";
    if ($dryRun) {
        echo "\nRe-run without --dry-run to apply.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'split_transportation failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
