<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\NameCaseFixer;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Normalizing existing person names to "first letter capital" casing. Backed by
 * in-memory SQLite so the update + audit paths really run.
 */
final class NameCaseFixerTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, updated_by TEXT)');
        $db->exec('CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (
            id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT, detail TEXT, actor TEXT,
            occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');

        $ins = $db->prepare('INSERT INTO person (person_id, first_name, last_name) VALUES (:id, :f, :l)');
        $ins->execute([':id' => 1, ':f' => 'JAMES', ':l' => 'SMITH']);       // both fixed
        $ins->execute([':id' => 2, ':f' => 'mary', ':l' => "o'brien"]);      // both fixed (apostrophe)
        $ins->execute([':id' => 3, ':f' => 'John', ':l' => 'Doe']);          // already correct -> untouched
        $ins->execute([':id' => 4, ':f' => 'ANNA', ':l' => 'McDonald']);     // only first changes

        return $db;
    }

    private function names(PDO $db): array
    {
        return $db->query('SELECT first_name, last_name FROM person ORDER BY person_id')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testDryRunChangesNothing(): void
    {
        $db = $this->db();
        $before = $this->names($db);
        $res = (new NameCaseFixer($db, new AuditService($db)))->run(dryRun: true);

        self::assertSame(4, $res['scanned']);
        self::assertSame(3, $res['changed']);   // persons 1, 2, 4
        self::assertSame($before, $this->names($db), 'dry-run must not write');
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testAppliesAndAudits(): void
    {
        $db = $this->db();
        $res = (new NameCaseFixer($db, new AuditService($db)))->run();

        self::assertSame(3, $res['changed']);
        self::assertSame([
            'James' => 'Smith',
            'Mary'  => "O'Brien",
            'John'  => 'Doe',
            'Anna'  => 'McDonald',
        ], $this->names($db));

        // One audit + one lifecycle row per changed person (3), none for the untouched one.
        self::assertSame('3', (string) $db->query("SELECT COUNT(*) FROM audit_log WHERE entity='person' AND action='update'")->fetchColumn());
        self::assertSame('3', (string) $db->query('SELECT COUNT(*) FROM lifecycle_event')->fetchColumn());
    }

    public function testIdempotentSecondRun(): void
    {
        $db = $this->db();
        $fixer = new NameCaseFixer($db, new AuditService($db));
        $fixer->run();
        $res = $fixer->run();

        self::assertSame(0, $res['changed'], 'a second run finds nothing to change');
    }
}
