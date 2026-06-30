<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PersonWriter::linkAdAccount — backfilling username/email/UPN from a matched AD
 * account. Fill-only (never overwrites a present value), locks the username,
 * activates a pending person, and leaves the record untouched on a unique clash.
 * (The objectGUID crosswalk write uses MySQL-only SQL, so these tests drive the
 * field-fill logic with no GUID.)
 */
final class AdLinkAccountTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, username TEXT UNIQUE, email TEXT UNIQUE, upn TEXT,
            username_locked INTEGER DEFAULT 0, username_assigned_at TEXT, status TEXT)');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INTEGER, event_type TEXT,
            detail TEXT, actor TEXT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    private function person(PDO $db, int $id): array
    {
        $s = $db->prepare('SELECT * FROM person WHERE person_id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch();
    }

    private function writer(PDO $db): PersonWriter
    {
        return new PersonWriter($db, new AuditService($db));
    }

    public function testFillsEmptyFieldsLocksUsernameAndActivates(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, upn, status) VALUES (1, '', '', '', 'pending')");

        $notes = $this->writer($db)->linkAdAccount(1, [
            'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
        ], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('jsmith', $p['username']);
        self::assertSame('jsmith@tusc.k12.al.us', $p['email']);
        self::assertSame('jsmith@tusc.k12.al.us', $p['upn']);
        self::assertSame(1, (int) $p['username_locked']);
        self::assertSame('active', $p['status']);          // pending -> active
        self::assertNotEmpty($notes);
    }

    public function testNeverOverwritesPresentValues(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, upn, username_locked, status)
                   VALUES (1, 'existing', 'keep@tusc.k12.al.us', 'keep@tusc.k12.al.us', 1, 'active')");

        $notes = $this->writer($db)->linkAdAccount(1, [
            'username' => 'adname', 'email' => 'ad@tusc.k12.al.us', 'upn' => 'ad@tusc.k12.al.us',
        ], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('existing', $p['username']);       // untouched
        self::assertSame('keep@tusc.k12.al.us', $p['email']);
        self::assertSame([], $notes);                       // nothing changed
    }

    public function testFillsOnlyTheMissingField(): void
    {
        $db = $this->db();
        // username already set; email empty -> only email filled.
        $db->exec("INSERT INTO person (person_id, username, email, upn, username_locked, status)
                   VALUES (1, 'jsmith', '', '', 1, 'active')");

        $this->writer($db)->linkAdAccount(1, ['username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us'], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('jsmith', $p['username']);
        self::assertSame('jsmith@tusc.k12.al.us', $p['email']);
    }

    public function testUniqueClashLeavesRecordUnchanged(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, status) VALUES (2, 'other', 'taken@tusc.k12.al.us', 'active')");
        $db->exec("INSERT INTO person (person_id, username, email, upn, status) VALUES (1, '', '', '', 'pending')");

        // AD email collides with person 2's email -> the whole fill is rejected.
        $notes = $this->writer($db)->linkAdAccount(1, [
            'username' => 'jsmith', 'email' => 'taken@tusc.k12.al.us',
        ], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('', $p['username']);   // not set — update was atomic
        self::assertSame('', $p['email']);
        self::assertSame('pending', $p['status']);
        self::assertStringContainsString('already in use', $notes[0]);
    }

    public function testIdempotentSecondRunNoChanges(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, upn, status) VALUES (1, '', '', '', 'pending')");
        $w = $this->writer($db);
        $w->linkAdAccount(1, ['username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us'], 'tester');
        $notes = $w->linkAdAccount(1, ['username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us'], 'tester');
        self::assertSame([], $notes); // already filled -> no-op
    }
}
