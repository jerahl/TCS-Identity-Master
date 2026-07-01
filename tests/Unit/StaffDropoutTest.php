<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Staff drop-out tracking: PersonWriter::deactivateMissingSourceIds flags the
 * crosswalk ids that were active but absent from a full feed run, mirroring the
 * student drop-out logic. Backed by in-memory SQLite so the update + audit paths
 * really run. Person status is never touched here — that stays a human decision.
 */
final class StaffDropoutTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person_source_id (
            id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT, source_key TEXT, is_active INTEGER DEFAULT 1)');
        $db->exec('CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (
            id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT, detail TEXT, actor TEXT,
            occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    /** @param array<int,array{0:int,1:string,2:string,3?:int}> $rows [person_id, system, source_key, is_active?] */
    private function seed(PDO $db, array $rows): void
    {
        $ins = $db->prepare('INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (:p, :s, :k, :a)');
        foreach ($rows as $r) {
            $ins->execute([':p' => $r[0], ':s' => $r[1], ':k' => $r[2], ':a' => $r[3] ?? 1]);
        }
    }

    private function activeKeys(PDO $db, string $system = 'nextgen'): array
    {
        $stmt = $db->prepare("SELECT source_key FROM person_source_id WHERE system = :s AND is_active = 1 ORDER BY source_key");
        $stmt->execute([':s' => $system]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testDeactivatesOnlyKeysAbsentFromTheFeed(): void
    {
        $db = $this->db();
        $this->seed($db, [
            [1, 'nextgen', 'E100'],
            [2, 'nextgen', 'E200'],
            [3, 'nextgen', 'E300'],
            [3, 'ad', 'E300'],          // non-nextgen row must never be touched
        ]);
        $writer = new PersonWriter($db, new AuditService($db));

        // Feed carried E100 + E200; E300 is gone.
        $res = $writer->deactivateMissingSourceIds('nextgen', ['E100' => true, 'E200' => true], 'system:test');

        self::assertSame(3, $res['active']);
        self::assertSame(1, $res['candidates']);
        self::assertSame(1, $res['deactivated']);
        self::assertFalse($res['blocked']);
        self::assertSame(['E100', 'E200'], $this->activeKeys($db), 'only E300 deactivated');
        // The AD row keyed E300 stays active (system-scoped).
        self::assertSame(['E300'], $this->activeKeys($db, 'ad'));
        // Audited + put on the person timeline.
        self::assertSame('1', (string) $db->query("SELECT COUNT(*) FROM audit_log WHERE entity='source_id' AND action='update'")->fetchColumn());
        self::assertSame('1', (string) $db->query("SELECT COUNT(*) FROM lifecycle_event WHERE person_id=3")->fetchColumn());
    }

    public function testDryRunComputesButWritesNothing(): void
    {
        $db = $this->db();
        $this->seed($db, [[1, 'nextgen', 'E100'], [2, 'nextgen', 'E200']]);
        $writer = new PersonWriter($db, new AuditService($db));

        $res = $writer->deactivateMissingSourceIds('nextgen', ['E100' => true], 'system:test', apply: false);

        self::assertSame(1, $res['candidates']);
        self::assertSame(0, $res['deactivated']);
        self::assertSame(['E100', 'E200'], $this->activeKeys($db), 'dry-run must not deactivate');
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testGuardBlocksMassDeactivationFromTruncatedFeed(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [$i, 'nextgen', 'E' . $i];
        }
        $this->seed($db, $rows);
        $writer = new PersonWriter($db, new AuditService($db));

        // Feed only carried 5 of 30 (25 would drop = 83% > 20% default ratio).
        $seen = ['E1' => true, 'E2' => true, 'E3' => true, 'E4' => true, 'E5' => true];
        $res = $writer->deactivateMissingSourceIds('nextgen', $seen, 'system:test');

        self::assertTrue($res['blocked']);
        self::assertSame(25, $res['candidates']);
        self::assertSame(0, $res['deactivated']);
        self::assertCount(30, $this->activeKeys($db), 'nothing deactivated when guard trips');
    }

    public function testGuardIgnoredForSmallPopulations(): void
    {
        $db = $this->db();
        $this->seed($db, [[1, 'nextgen', 'E100'], [2, 'nextgen', 'E200'], [3, 'nextgen', 'E300']]);
        $writer = new PersonWriter($db, new AuditService($db));

        // 3 active (< guardMinActive of 20), feed empty -> all 3 drop despite 100%.
        $res = $writer->deactivateMissingSourceIds('nextgen', [], 'system:test');

        self::assertFalse($res['blocked']);
        self::assertSame(3, $res['deactivated']);
        self::assertSame([], $this->activeKeys($db));
    }

    public function testAlreadyInactiveKeysAreNotReprocessed(): void
    {
        $db = $this->db();
        $this->seed($db, [
            [1, 'nextgen', 'E100'],
            [2, 'nextgen', 'E200', 0], // already inactive
        ]);
        $writer = new PersonWriter($db, new AuditService($db));

        // Feed carried E100 only; E200 is already inactive so it's not a candidate.
        $res = $writer->deactivateMissingSourceIds('nextgen', ['E100' => true], 'system:test');

        self::assertSame(1, $res['active']);
        self::assertSame(0, $res['candidates']);
        self::assertSame(0, $res['deactivated']);
    }
}
