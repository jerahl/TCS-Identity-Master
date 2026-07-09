<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PersonWriter;
use App\Service\AuditService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PersonWriter::linkAdAccount — adopting username/email/UPN from a matched AD
 * account. Fills a blank golden value AND overwrites a differing one so the
 * record matches AD (case-insensitive, so a casing-only difference is a no-op),
 * locks the username, activates a pending person, and leaves the record
 * untouched on a unique clash. (The objectGUID crosswalk write uses MySQL-only
 * SQL, so these tests drive the field logic with no GUID.)
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

    public function testOverwritesDifferingValuesToMatchAd(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO person (person_id, username, email, upn, username_locked, status)
                   VALUES (1, 'existing', 'old@tusc.k12.al.us', 'old@tusc.k12.al.us', 1, 'active')");

        $notes = $this->writer($db)->linkAdAccount(1, [
            'username' => 'adname', 'email' => 'ad@tusc.k12.al.us', 'upn' => 'ad@tusc.k12.al.us',
        ], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('adname', $p['username']);            // overwritten to AD
        self::assertSame('ad@tusc.k12.al.us', $p['email']);
        self::assertSame('ad@tusc.k12.al.us', $p['upn']);
        self::assertSame(1, (int) $p['username_locked']);      // stays locked
        self::assertSame('active', $p['status']);              // active is not re-activated
        self::assertNotEmpty($notes);
        self::assertStringContainsString('username changed to adname', implode('; ', $notes));
    }

    public function testMatchingValuesAreNoOpCaseInsensitively(): void
    {
        $db = $this->db();
        // Golden already matches AD apart from casing -> nothing to write.
        $db->exec("INSERT INTO person (person_id, username, email, upn, username_locked, status)
                   VALUES (1, 'jsmith', 'jsmith@tusc.k12.al.us', 'jsmith@tusc.k12.al.us', 1, 'active')");

        $notes = $this->writer($db)->linkAdAccount(1, [
            'username' => 'JSmith', 'email' => 'JSmith@tusc.k12.al.us', 'upn' => 'JSmith@tusc.k12.al.us',
        ], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('jsmith', $p['username']);            // unchanged (casing-only diff)
        self::assertSame([], $notes);
    }

    public function testFillsBlankAndLeavesMatchingFieldAlone(): void
    {
        $db = $this->db();
        // username already matches AD; email empty -> only email filled.
        $db->exec("INSERT INTO person (person_id, username, email, upn, username_locked, status)
                   VALUES (1, 'jsmith', '', '', 1, 'active')");

        $notes = $this->writer($db)->linkAdAccount(1, ['username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us'], 'tester');

        $p = $this->person($db, 1);
        self::assertSame('jsmith', $p['username']);
        self::assertSame('jsmith@tusc.k12.al.us', $p['email']);
        self::assertSame(['email set to jsmith@tusc.k12.al.us'], $notes);
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
