<?php

declare(strict_types=1);

/**
 * Reference-data seeder.
 *
 *   php bin/seed.php            upsert school, school_code_alias, ethnicity_map,
 *                               position_type_map
 *   php bin/seed.php --dry-run  parse + report counts, change nothing
 *   php bin/seed.php --prune    also DELETE schools/aliases not in the CSVs
 *                               (makes the CSVs authoritative; clears stale rows)
 *
 * Idempotent: re-running upserts on the natural keys, so it's safe to run after
 * editing the CSVs in db/seeds/. These CSVs ship as PLACEHOLDERS — replace with
 * the district's real school map and ALSDE ethnicity codes before go-live.
 *
 * Runs as the MIGRATE role (a trusted ops/bootstrap step).
 */

use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$prune = in_array('--prune', $args, true);
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

    // --- position_type_map (PK: job_code) — classifies imported employees as
    //     faculty/staff by the NextGen JOB CODE. May be partial: unmapped codes
    //     default to 'staff' on create. ---
    $positions = readCsv("{$seedDir}/position_type_map.csv");
    echo 'position_type_map: ' . count($positions) . " row(s)\n";
    if (!$dryRun) {
        $stmt = $pdo->prepare(
            'INSERT INTO position_type_map (job_code, person_type, description)
             VALUES (:job_code, :person_type, :description)
             ON DUPLICATE KEY UPDATE person_type = VALUES(person_type),
                                     description = VALUES(description)'
        );
        foreach ($positions as $row) {
            $stmt->execute([
                ':job_code'    => $row['job_code'],
                ':person_type' => $row['person_type'],
                ':description' => $row['description'] ?: null,
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

    // --- prune (optional): make the CSVs authoritative by deleting school /
    //     school_code_alias rows that are no longer present in them. Removes stale
    //     demo placeholders. Aliases go first (FK child); a school still referenced
    //     by a person/assignment is reported and kept. ---
    if ($prune && !$dryRun) {
        $keepCodes = [];
        foreach ($aliases as $row) {
            $keepCodes[$row['system'] . "\x1f" . $row['code']] = true;
        }
        $del = $pdo->prepare('DELETE FROM school_code_alias WHERE system = :s AND code = :c');
        $prunedAliases = 0;
        foreach ($pdo->query('SELECT alias_id, system, code FROM school_code_alias')->fetchAll() as $a) {
            if (!isset($keepCodes[$a['system'] . "\x1f" . $a['code']])) {
                $del->execute([':s' => $a['system'], ':c' => $a['code']]);
                $prunedAliases++;
            }
        }

        $keepPs = [];
        foreach ($schools as $row) {
            $keepPs[(string) $row['ps_school_id']] = true;
        }
        $delSchool = $pdo->prepare('DELETE FROM school WHERE school_id = :id');
        $prunedSchools = 0;
        $kept = 0;
        foreach ($pdo->query('SELECT school_id, name, ps_school_id FROM school')->fetchAll() as $s) {
            if (isset($keepPs[(string) $s['ps_school_id']])) {
                continue;
            }
            try {
                $delSchool->execute([':id' => $s['school_id']]);
                $prunedSchools++;
            } catch (\PDOException $e) {
                fwrite(STDERR, "  WARN: kept school '{$s['name']}' (ps_school_id={$s['ps_school_id']}) — still referenced (person/assignment).\n");
                $kept++;
            }
        }
        echo "prune: removed {$prunedAliases} alias(es), {$prunedSchools} school(s)"
            . ($kept ? ", kept {$kept} still-referenced" : '') . ".\n";
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
