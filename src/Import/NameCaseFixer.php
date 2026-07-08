<?php

declare(strict_types=1);

namespace App\Import;

use App\Db;
use App\Service\AuditService;
use App\Support\NameCase;
use PDO;

/**
 * ONE-TIME / maintenance: normalize the casing of every person's first and last
 * name to conventional "first letter capital" form (see App\Support\NameCase),
 * so records that came in as "JAMES SMITH" or "james smith" are stored as
 * "James Smith".
 *
 * Only rows whose casing actually changes are written; each change is audited
 * (audit_log before/after) and recorded on the person's timeline
 * (lifecycle_event), matching the rest of the write paths. Idempotent — a second
 * run finds nothing to do. Writes nothing on --dry-run. Runs as the MIGRATE role
 * (a one-time op that also writes audit rows), like AdIdCleanup.
 *
 * Scope is deliberately first_name / last_name only, per the request; middle and
 * preferred names are left untouched.
 */
final class NameCaseFixer
{
    private PDO $db;
    private AuditService $audit;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->db = $db ?? Db::connect(Db::ROLE_MIGRATE);
        $this->audit = $audit ?? new AuditService($this->db);
    }

    /**
     * @return array{dry_run:bool, changed:int, scanned:int, outcomes:array<int,array{person_id:int,from:string,to:string}>}
     */
    public function run(bool $dryRun = false, ?string $actor = null): array
    {
        $actor ??= 'system:fix_name_case';

        $rows = $this->db->query(
            'SELECT person_id, first_name, last_name FROM person ORDER BY person_id'
        )->fetchAll();

        $update = $this->db->prepare(
            'UPDATE person SET first_name = :first, last_name = :last, updated_by = :actor WHERE person_id = :id'
        );

        $outcomes = [];
        foreach ($rows as $r) {
            $pid = (int) $r['person_id'];
            $oldFirst = (string) $r['first_name'];
            $oldLast = (string) $r['last_name'];
            $newFirst = NameCase::format($oldFirst);
            $newLast = NameCase::format($oldLast);

            if ($newFirst === $oldFirst && $newLast === $oldLast) {
                continue; // already correctly cased
            }

            $outcomes[] = [
                'person_id' => $pid,
                'from'      => trim($oldFirst . ' ' . $oldLast),
                'to'        => trim($newFirst . ' ' . $newLast),
            ];

            if ($dryRun) {
                continue;
            }

            $update->execute([':first' => $newFirst, ':last' => $newLast, ':actor' => $actor, ':id' => $pid]);

            $before = ['first_name' => $oldFirst, 'last_name' => $oldLast];
            $after = ['first_name' => $newFirst, 'last_name' => $newLast];
            $this->audit->log('person', $pid, 'update', $before, $after, $actor);
            $this->audit->lifecycle($pid, 'update', ['summary' => 'Normalized name casing.'], $actor);
        }

        return [
            'dry_run'  => $dryRun,
            'changed'  => count($outcomes),
            'scanned'  => count($rows),
            'outcomes' => $outcomes,
        ];
    }
}
