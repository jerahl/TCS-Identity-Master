<?php

declare(strict_types=1);

/**
 * Reference-data seeder.
 *
 *   php bin/seed.php            upsert school, school_code_alias, ethnicity_map
 *   php bin/seed.php --dry-run  parse + report counts, change nothing
 *
 * Idempotent: re-running upserts on the natural keys, so it's safe to run after
 * editing the CSVs in db/seeds/. These CSVs ship as PLACEHOLDERS — replace with
 * the district's real school map and ALSDE ethnicity codes before go-live.
 *
 * Runs as the MIGRATE role (a trusted ops/bootstrap step).
 */

use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$seedDir = __DIR__ . '/../db/seeds';

try {
    $pdo = Db::connect(Db::ROLE_MIGRATE);

    // --- ethnicity_map (PK: source_value) ---
    $eth = readCsv("{$seedDir}/ethnicity_map.csv");
    echo 'ethnicity_map: ' . count($eth) . " row(s)\n";
    if (!$dryRun) {
        $stmt = $pdo->prepare(
            'INSERT INTO ethnicity_map (source_value, alsde_code, federal_group)
             VALUES (:source_value, :alsde_code, :federal_group)
             ON DUPLICATE KEY UPDATE alsde_code = VALUES(alsde_code),
                                     federal_group = VALUES(federal_group)'
        );
        foreach ($eth as $row) {
            $stmt->execute([
                ':source_value'  => $row['source_value'],
                ':alsde_code'    => $row['alsde_code'],
                ':federal_group' => $row['federal_group'] ?? null,
            ]);
        }
    }

    // --- school (natural key: ps_school_id) ---
    $schools = readCsv("{$seedDir}/school.csv");
    echo 'school: ' . count($schools) . " row(s)\n";
    if (!$dryRun) {
        $stmt = $pdo->prepare(
            'INSERT INTO school (name, ps_school_id, ad_ou, google_ou, status)
             VALUES (:name, :ps, :ad_ou, :google_ou, :status)
             ON DUPLICATE KEY UPDATE name = VALUES(name),
                                     ad_ou = VALUES(ad_ou),
                                     google_ou = VALUES(google_ou),
                                     status = VALUES(status)'
        );
        foreach ($schools as $row) {
            $stmt->execute([
                ':name'      => $row['name'],
                ':ps'        => $row['ps_school_id'],
                ':ad_ou'     => $row['ad_ou'] ?: null,
                ':google_ou' => $row['google_ou'] ?: null,
                ':status'    => $row['status'] ?: 'active',
            ]);
        }
    }

    // --- school_code_alias (resolve school_id via ps_school_id; key: system,code) ---
    $aliases = readCsv("{$seedDir}/school_code_alias.csv");
    echo 'school_code_alias: ' . count($aliases) . " row(s)\n";
    if (!$dryRun) {
        $lookup = $pdo->prepare('SELECT school_id FROM school WHERE ps_school_id = ?');
        $stmt = $pdo->prepare(
            'INSERT INTO school_code_alias (school_id, system, code)
             VALUES (:school_id, :system, :code)
             ON DUPLICATE KEY UPDATE school_id = VALUES(school_id)'
        );
        foreach ($aliases as $row) {
            $lookup->execute([$row['ps_school_id']]);
            $schoolId = $lookup->fetchColumn();
            if ($schoolId === false) {
                fwrite(STDERR, "  WARN: no school for ps_school_id={$row['ps_school_id']} (alias {$row['system']}:{$row['code']}) — skipped\n");
                continue;
            }
            $stmt->execute([
                ':school_id' => (int) $schoolId,
                ':system'    => $row['system'],
                ':code'      => $row['code'],
            ]);
        }
    }

    echo $dryRun ? "Dry run complete — nothing written.\n" : "Seed complete.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}

/** Read a CSV with a header row into a list of associative rows. */
function readCsv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Seed file not found: {$path}");
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Cannot open seed file: {$path}");
    }
    $rows = [];
    $header = null;
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        // Skip fully blank lines.
        if ($cols === [null] || $cols === [false]) {
            continue;
        }
        if ($header === null) {
            $header = array_map('trim', $cols);
            continue;
        }
        if (count($cols) === 1 && trim((string) $cols[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}
