<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use App\Service\AuditService;
use PDO;

/**
 * Remove the legacy AD ids from the crosswalk.
 *
 * The early one-time link (import_ad_usernames.php, TEACHERS/AD-export formats)
 * recorded the AD account as a uniqueId — "T" + TEACHERS.ID, e.g. T13305. The
 * Adaxes "Employee List" import records the real objectGUID (e.g.
 * 2b6160e2-ad91-419c-8960-cf672c75528f). Re-running both leaves some people with
 * two `person_source_id` rows under system 'ad'. The objectGUID is the one the
 * live verification resolves by, so the legacy uniqueId is dead weight.
 *
 * This removes the legacy "T#####" AD rows. By default it only does so for people
 * who ALSO have a non-legacy (GUID) AD id, so nobody is left without an AD link;
 * pass $all = true to drop legacy ids even when they're the only AD id on file
 * (e.g. accounts the Employee List import didn't cover). Writes nothing on
 * --dry-run. Runs as the MIGRATE role (one-time ops; it also writes audit rows).
 */
final class AdIdCleanup
{
    private PDO $db;
    private AuditService $audit;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_MIGRATE);
        $this->audit = $audit ?? new AuditService($this->db);
    }

    /** The legacy AD uniqueId form recorded by the early import: "T" + digits. */
    public static function isLegacyId(string $key): bool
    {
        return (bool) preg_match('/^[Tt]\d+$/', trim($key));
    }

    /**
     * @return array{dry_run:bool, removed:int, kept:int, persons:int, orphans:int, outcomes:array<int,array{person_id:int,action:string,detail:string}>}
     */
    public function run(bool $dryRun = false, bool $all = false, ?string $actor = null): array
    {
        $actor ??= 'system:cleanup_ad_ids';

        $rows = $this->db->query(
            "SELECT id, person_id, source_key FROM person_source_id WHERE system = 'ad' ORDER BY person_id, id"
        )->fetchAll();

        /** @var array<int,array<int,array<string,mixed>>> $byPerson */
        $byPerson = [];
        foreach ($rows as $r) {
            $byPerson[(int) $r['person_id']][] = $r;
        }

        $plan = [];      // persons whose legacy ids we'll remove
        $kept = 0;
        $orphans = 0;
        $outcomes = [];

        foreach ($byPerson as $pid => $adRows) {
            $legacy = [];
            $modern = [];
            foreach ($adRows as $r) {
                if (self::isLegacyId((string) $r['source_key'])) {
                    $legacy[] = $r;
                } else {
                    $modern[] = $r;
                }
            }

            if ($legacy === []) {
                $kept += count($modern);
                continue;
            }
            if ($modern === [] && !$all) {
                // Only legacy id(s) and not --all: leave it so the person keeps an
                // AD link. Re-run the Employee List import to give them a GUID.
                $orphans++;
                $kept += count($legacy);
                $outcomes[] = ['person_id' => (int) $pid, 'action' => 'kept_only_legacy',
                    'detail' => 'only legacy AD id(s): ' . self::keys($legacy) . ' — skipped (use --all to remove)'];
                continue;
            }

            $plan[] = ['pid' => (int) $pid, 'legacy' => $legacy, 'modern' => $modern];
            $kept += count($modern);
            $outcomes[] = ['person_id' => (int) $pid, 'action' => $dryRun ? 'would_remove' : 'removed',
                'detail' => 'legacy ' . self::keys($legacy) . ($modern !== [] ? ' (kept ' . self::keys($modern) . ')' : '')];
        }

        $ids = [];
        foreach ($plan as $p) {
            foreach ($p['legacy'] as $r) {
                $ids[] = (int) $r['id'];
            }
        }
        $removed = count($ids);

        if (!$dryRun && $ids !== []) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $this->db->prepare("DELETE FROM person_source_id WHERE id IN ($placeholders)")->execute($chunk);
            }
            foreach ($plan as $p) {
                $keys = self::keys($p['legacy']);
                $this->audit->log('source_id', (int) $p['pid'], 'delete',
                    ['ad_legacy_ids' => array_map(static fn($r) => (string) $r['source_key'], $p['legacy'])],
                    ['ad_ids_kept' => array_map(static fn($r) => (string) $r['source_key'], $p['modern'])],
                    $actor);
                $this->audit->lifecycle((int) $p['pid'], 'update',
                    ['summary' => "Removed legacy AD id(s): {$keys} (superseded by objectGUID)."], $actor);
            }
        }

        return ['dry_run' => $dryRun, 'removed' => $removed, 'kept' => $kept,
            'persons' => count($plan), 'orphans' => $orphans, 'outcomes' => $outcomes];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function keys(array $rows): string
    {
        return implode(', ', array_map(static fn($r) => (string) $r['source_key'], $rows));
    }
}
