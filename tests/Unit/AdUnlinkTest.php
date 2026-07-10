<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PersonWriter::unlinkUsername — undo a bad identity assignment (HR typo'd the
 * name or employee id): clear username/email/upn + the lock so it can re-mint,
 * and deactivate the AD crosswalk so a stale objectGUID no longer resolves here.
 */
final class AdUnlinkTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (person_id INTEGER PRIMARY KEY, username TEXT UNIQUE, email TEXT UNIQUE, upn TEXT,
            username_locked INTEGER DEFAULT 0, username_assigned_at TEXT, status TEXT, updated_by TEXT)');
        $db->exec('CREATE TABLE person_source_id (id INTEGER PRIMARY KEY, person_id INTEGER, system TEXT, source_key TEXT, is_active INTEGER DEFAULT 1)');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT,
            detail TEXT, actor TEXT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    private function writer(PDO $db): PersonWriter
    {
        return new PersonWriter($db, new AuditService($db));
    }

    public function testClearsIdentityUnlocksAndRemovesCrosswalk(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, upn, username_locked, status)
                   VALUES (1, 'jsmith', 'jsmith@tusc.k12.al.us', 'jsmith@tusc.k12.al.us', 1, 'active')");
        // One active AD link and one already-inactive one — both must go.
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1, 'ad', 'the-guid', 1)");
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1, 'ad', 'old-guid', 0)");
        // A non-AD crosswalk must be left alone.
        $db->exec("INSERT INTO person_source_id (person_id, system, source_key, is_active) VALUES (1, 'nextgen', '12345', 1)");

        $notes = $this->writer($db)->unlinkUsername(1, 'admin', 'HR typo');
        self::assertNotEmpty($notes);

        $p = $db->query('SELECT * FROM person WHERE person_id = 1')->fetch();
        self::assertNull($p['username']);
        self::assertNull($p['email']);
        self::assertNull($p['upn']);
        self::assertSame(0, (int) $p['username_locked']);

        // The AD crosswalk rows are GONE (not merely deactivated); nextgen stays.
        self::assertSame(0, (int) $db->query("SELECT COUNT(*) FROM person_source_id WHERE person_id = 1 AND system = 'ad'")->fetchColumn());
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM person_source_id WHERE person_id = 1 AND system = 'nextgen'")->fetchColumn());

        // A lifecycle event records the reason.
        $detail = $db->query('SELECT detail FROM lifecycle_event WHERE person_id = 1')->fetchColumn();
        self::assertStringContainsString('HR typo', (string) $detail);
        self::assertStringContainsString('removed', (string) $detail);
    }

    public function testNoOpWhenNothingLinked(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, upn, status) VALUES (1, NULL, NULL, NULL, 'pending')");
        $notes = $this->writer($db)->unlinkUsername(1, 'admin');
        self::assertSame([], $notes);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM lifecycle_event')->fetchColumn());
    }
}
