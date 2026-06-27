<?php

declare(strict_types=1);

/**
 * DEV-ONLY demo data seeder.
 *
 *   php bin/seed_demo.php            seed sample people (non-production only)
 *   php bin/seed_demo.php --force    seed even if APP_ENV=production (don't)
 *   php bin/seed_demo.php --dry-run  report, change nothing
 *
 * Loads the design-mockup's sample faculty/staff so the People list and Person
 * detail render with realistic content before the real importers (Milestone 3)
 * exist. Idempotent: people upsert on person_uuid; a person's child rows
 * (source IDs, assignments, lifecycle, sync status) are rebuilt each run.
 *
 * This is fixture tooling, NOT the app — it deletes/re-inserts demo child rows by
 * design. Never point it at a production database.
 */

use App\Config;
use App\Db;

require __DIR__ . '/../src/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
$force = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

if (strtolower((string) Config::get('APP_ENV', 'development')) === 'production' && !$force) {
    fwrite(STDERR, "Refusing to seed demo data while APP_ENV=production. Use --force to override.\n");
    exit(1);
}

/** Compact demo people. uuid is the stable key; child rows hang off it. */
$people = require __DIR__ . '/../db/seeds/demo_people.php';

try {
    $pdo = Db::connect(Db::ROLE_MIGRATE);

    // Resolve school_id by name once.
    $schoolMap = [];
    foreach ($pdo->query('SELECT school_id, name FROM school')->fetchAll() as $r) {
        $schoolMap[$r['name']] = (int) $r['school_id'];
    }

    echo count($people) . " demo people to seed" . ($dryRun ? " (dry run)\n" : "\n");
    if ($dryRun) {
        foreach ($people as $p) {
            $sid = $schoolMap[$p['school']] ?? null;
            echo sprintf("  %-22s %-10s %s\n", $p['first'] . ' ' . $p['last'], $p['status'], $sid ? '' : "(school '{$p['school']}' not found)");
        }
        echo "Dry run complete.\n";
        exit(0);
    }

    $upsertPerson = $pdo->prepare(
        'INSERT INTO person
          (person_uuid, person_type, status, first_name, middle_name, last_name, preferred_name,
           dob, gender, ethnicity_source, ethnicity_code, alsde_id, employee_id, primary_school_id,
           hire_date, end_date, username, email, username_assigned_at, username_locked,
           source_of_record, notes)
         VALUES
          (:uuid, :type, :status, :first, :middle, :last, :preferred,
           :dob, :gender, :eth_src, :eth_code, :alsde, :emp, :school_id,
           :hire, :end, :username, :email, :assigned_at, :locked,
           :sor, :notes)
         ON DUPLICATE KEY UPDATE
           person_type=VALUES(person_type), status=VALUES(status), first_name=VALUES(first_name),
           middle_name=VALUES(middle_name), last_name=VALUES(last_name), preferred_name=VALUES(preferred_name),
           dob=VALUES(dob), gender=VALUES(gender), ethnicity_source=VALUES(ethnicity_source),
           ethnicity_code=VALUES(ethnicity_code), alsde_id=VALUES(alsde_id), employee_id=VALUES(employee_id),
           primary_school_id=VALUES(primary_school_id), hire_date=VALUES(hire_date), end_date=VALUES(end_date),
           username=VALUES(username), email=VALUES(email), username_assigned_at=VALUES(username_assigned_at),
           username_locked=VALUES(username_locked), source_of_record=VALUES(source_of_record), notes=VALUES(notes)'
    );
    $findId = $pdo->prepare('SELECT person_id FROM person WHERE person_uuid = ?');
    $insSrc = $pdo->prepare(
        'INSERT INTO person_source_id (person_id, system, source_key, is_active)
         VALUES (:pid, :system, :key, :active)
         ON DUPLICATE KEY UPDATE person_id=VALUES(person_id), is_active=VALUES(is_active), last_seen=CURRENT_TIMESTAMP'
    );
    $insAssign = $pdo->prepare(
        'INSERT INTO assignment (person_id, school_id, title, fte, is_primary, effective_date, end_date, source)
         VALUES (:pid, :school_id, :title, :fte, :primary, :eff, :end, :source)'
    );
    $insLife = $pdo->prepare(
        'INSERT INTO lifecycle_event (person_id, event_type, detail, occurred_at, actor)
         VALUES (:pid, :etype, :detail, :at, :actor)'
    );
    $upsertSync = $pdo->prepare(
        'INSERT INTO account_sync_status
           (person_id, person_uuid, destination, dest_type, last_action, last_status, last_sync_at, message)
         VALUES (:pid, :uuid, :dest, :dtype, :action, :status, :at, :msg)
         ON DUPLICATE KEY UPDATE person_id=VALUES(person_id), dest_type=VALUES(dest_type),
           last_action=VALUES(last_action), last_status=VALUES(last_status),
           last_sync_at=VALUES(last_sync_at), message=VALUES(message)'
    );

    $count = 0;
    foreach ($people as $p) {
        $schoolId = $schoolMap[$p['school']] ?? null;
        $pdo->beginTransaction();
        try {
            $upsertPerson->execute([
                ':uuid' => $p['uuid'], ':type' => $p['type'], ':status' => $p['status'],
                ':first' => $p['first'], ':middle' => $p['middle'] ?: null, ':last' => $p['last'],
                ':preferred' => $p['preferred'] ?: null, ':dob' => $p['dob'] ?: null, ':gender' => $p['gender'] ?: null,
                ':eth_src' => $p['eth_src'] ?: null, ':eth_code' => $p['eth_code'] ?: null, ':alsde' => $p['alsde'] ?: null,
                ':emp' => $p['emp'] ?: null, ':school_id' => $schoolId,
                ':hire' => $p['hire'] ?: null, ':end' => $p['end'] ?: null,
                ':username' => $p['username'] ?: null, ':email' => $p['email'] ?: null,
                ':assigned_at' => $p['username'] ? $p['hire'] . ' 06:00:00' : null,
                ':locked' => $p['username'] ? 1 : 0,
                ':sor' => $p['sor'], ':notes' => $p['notes'] ?: null,
            ]);
            $findId->execute([$p['uuid']]);
            $pid = (int) $findId->fetchColumn();

            // Rebuild child rows for this demo person.
            $pdo->prepare('DELETE FROM assignment WHERE person_id = ?')->execute([$pid]);
            $pdo->prepare('DELETE FROM lifecycle_event WHERE person_id = ?')->execute([$pid]);

            foreach ($p['sources'] as [$sys, $key, $active]) {
                $insSrc->execute([':pid' => $pid, ':system' => $sys, ':key' => $key, ':active' => $active]);
            }
            foreach ($p['assignments'] as $a) {
                $sid = $schoolMap[$a['school']] ?? $schoolId;
                if ($sid === null) {
                    continue;
                }
                $insAssign->execute([
                    ':pid' => $pid, ':school_id' => $sid, ':title' => $a['title'], ':fte' => $a['fte'],
                    ':primary' => $a['primary'] ? 1 : 0, ':eff' => $a['eff'] ?: null, ':end' => $a['end'] ?: null,
                    ':source' => $a['source'] ?? 'nextgen',
                ]);
            }
            foreach ($p['lifecycle'] as $ev) {
                $insLife->execute([
                    ':pid' => $pid, ':etype' => $ev['type'],
                    ':detail' => json_encode(['summary' => $ev['summary']], JSON_UNESCAPED_SLASHES),
                    ':at' => $ev['at'], ':actor' => $ev['actor'],
                ]);
            }
            foreach ($p['sync'] as $sy) {
                $upsertSync->execute([
                    ':pid' => $pid, ':uuid' => $p['uuid'], ':dest' => $sy['dest'], ':dtype' => $sy['dtype'],
                    ':action' => $sy['action'], ':status' => $sy['status'], ':at' => $sy['at'], ':msg' => $sy['msg'] ?? null,
                ]);
            }

            $pdo->commit();
            $count++;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    echo "Seeded {$count} demo people (with crosswalk, assignments, lifecycle, sync status).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Demo seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
