<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\AdIdCleanup;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Legacy AD-id cleanup: remove "T#####" crosswalk rows where the real objectGUID
 * now exists, without orphaning people who only have the legacy id (unless
 * --all). Backed by in-memory SQLite so the delete + audit paths really run.
 */
final class AdIdCleanupTest extends TestCase
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

        $ins = $db->prepare("INSERT INTO person_source_id (person_id, system, source_key) VALUES (:p, :s, :k)");
        // person 1: legacy + GUID  -> legacy removed
        $ins->execute([':p' => 1, ':s' => 'ad', ':k' => 'T13305']);
        $ins->execute([':p' => 1, ':s' => 'ad', ':k' => '2b6160e2-ad91-419c-8960-cf672c75528f']);
        // person 2: legacy only    -> kept by default, removed by --all
        $ins->execute([':p' => 2, ':s' => 'ad', ':k' => 'T8422']);
        // person 3: GUID only      -> untouched
        $ins->execute([':p' => 3, ':s' => 'ad', ':k' => 'a1b2c3d4-0000-0000-0000-000000000000']);
        // person 4: two legacy + GUID -> both legacy removed
        $ins->execute([':p' => 4, ':s' => 'ad', ':k' => 'T1']);
        $ins->execute([':p' => 4, ':s' => 'ad', ':k' => 'T2']);
        $ins->execute([':p' => 4, ':s' => 'ad', ':k' => 'ffffffff-1111-2222-3333-444444444444']);
        // a non-AD row that must never be touched
        $ins->execute([':p' => 1, ':s' => 'powerschool', ':k' => 'T13305']);

        return $db;
    }

    private function adKeys(PDO $db): array
    {
        return $db->query("SELECT source_key FROM person_source_id WHERE system='ad' ORDER BY source_key")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testLegacyDetection(): void
    {
        self::assertTrue(AdIdCleanup::isLegacyId('T13305'));
        self::assertTrue(AdIdCleanup::isLegacyId('t8422'));
        self::assertFalse(AdIdCleanup::isLegacyId('2b6160e2-ad91-419c-8960-cf672c75528f'));
        self::assertFalse(AdIdCleanup::isLegacyId('T13A')); // not all-digits after T
        self::assertFalse(AdIdCleanup::isLegacyId(''));
    }

    public function testDryRunChangesNothing(): void
    {
        $db = $this->db();
        $before = $this->adKeys($db);
        $res = (new AdIdCleanup($db, new AuditService($db)))->run(dryRun: true);

        self::assertSame(3, $res['removed']);   // T13305, T1, T2
        self::assertSame(1, $res['orphans']);   // person 2 (T8422 only)
        self::assertSame($before, $this->adKeys($db), 'dry-run must not delete');
    }

    public function testDefaultRemovesLegacyOnlyWhereGuidExists(): void
    {
        $db = $this->db();
        $res = (new AdIdCleanup($db, new AuditService($db)))->run();

        self::assertSame(3, $res['removed']);
        self::assertSame(1, $res['orphans']);
        $keys = $this->adKeys($db);
        self::assertNotContains('T13305', $keys);
        self::assertNotContains('T1', $keys);
        self::assertNotContains('T2', $keys);
        self::assertContains('T8422', $keys, 'only-legacy person kept by default');
        self::assertContains('2b6160e2-ad91-419c-8960-cf672c75528f', $keys);
        // The powerschool row keyed T13305 must survive (system-scoped).
        self::assertSame('1', (string) $db->query("SELECT COUNT(*) FROM person_source_id WHERE system='powerschool' AND source_key='T13305'")->fetchColumn());
        // Audit + lifecycle written for the 2 affected people.
        self::assertSame('2', (string) $db->query("SELECT COUNT(*) FROM audit_log WHERE action='delete'")->fetchColumn());
        self::assertSame('2', (string) $db->query("SELECT COUNT(*) FROM lifecycle_event")->fetchColumn());
    }

    public function testAllAlsoRemovesOnlyLegacy(): void
    {
        $db = $this->db();
        $res = (new AdIdCleanup($db, new AuditService($db)))->run(dryRun: false, all: true);

        self::assertSame(4, $res['removed']); // T13305, T8422, T1, T2
        self::assertSame(0, $res['orphans']);
        $keys = $this->adKeys($db);
        self::assertNotContains('T8422', $keys);
        self::assertSame(['2b6160e2-ad91-419c-8960-cf672c75528f', 'a1b2c3d4-0000-0000-0000-000000000000', 'ffffffff-1111-2222-3333-444444444444'], $keys);
    }
}
