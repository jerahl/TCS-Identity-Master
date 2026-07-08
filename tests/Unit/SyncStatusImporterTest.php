<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\SyncStatusImporter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * applyEvent() — the direct-provisioning reflection path (used by
 * GoogleProvisioner). A direct write bypasses OneSync, so this is the only way
 * those writes reach account_sync_status / account_sync_event.
 *
 * The upsert itself uses MySQL's ON DUPLICATE KEY UPDATE (identical to the
 * sibling OneSyncResultImporter, which writes the same tables), so — as in
 * OneSyncResultTest — the DB write is not exercised against SQLite here. These
 * cover the portable logic: enum normalization, dest-type derivation, the
 * required-key skip guard, and the dry-run no-op (which touches no SQL).
 */
final class SyncStatusImporterTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('CREATE TABLE person (person_id INTEGER PRIMARY KEY, person_uuid TEXT)');
        $this->db->exec('CREATE TABLE account_sync_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INT NULL, person_uuid TEXT, destination TEXT,
            dest_type TEXT NULL, last_action TEXT NULL, last_status TEXT NULL, last_sync_at TEXT NULL,
            message TEXT NULL, UNIQUE (person_uuid, destination))');
        $this->db->exec('CREATE TABLE account_sync_event (
            id INTEGER PRIMARY KEY AUTOINCREMENT, person_uuid TEXT, destination TEXT, action TEXT NULL,
            status TEXT NULL, message TEXT NULL, occurred_at TEXT NULL)');
        $this->db->exec("INSERT INTO person (person_id, person_uuid) VALUES (1, 'uuid-1')");
    }

    public function testNormalizeActionAndStatus(): void
    {
        self::assertSame('Add', SyncStatusImporter::normalizeAction('create'));
        self::assertSame('Edit', SyncStatusImporter::normalizeAction('UPDATE'));
        self::assertSame('Disable', SyncStatusImporter::normalizeAction('disable'));
        self::assertNull(SyncStatusImporter::normalizeAction('bogus'));

        self::assertSame('Success', SyncStatusImporter::normalizeStatus('ok'));
        self::assertSame('Fail', SyncStatusImporter::normalizeStatus('error'));
        self::assertSame('Skipped', SyncStatusImporter::normalizeStatus('skip'));
        self::assertNull(SyncStatusImporter::normalizeStatus('weird'));
    }

    public function testDeriveDestType(): void
    {
        self::assertSame('GSuite', SyncStatusImporter::deriveDestType('Google Workspace'));
        self::assertSame('ActiveDirectory', SyncStatusImporter::deriveDestType('Faculty AD'));
        self::assertSame('CSV', SyncStatusImporter::deriveDestType('Raptor'));
        // Explicit type wins over the label heuristic.
        self::assertSame('GSuite', SyncStatusImporter::deriveDestType('Anything', 'GSuite'));
        self::assertNull(SyncStatusImporter::deriveDestType('Something Else'));
    }

    public function testMissingKeysAreSkipped(): void
    {
        $imp = new SyncStatusImporter($this->db);
        self::assertSame('skipped', $imp->applyEvent(['destination' => 'Google Workspace'])['outcome']);
        self::assertSame('skipped', $imp->applyEvent(['uniqueId' => 'uuid-1'])['outcome']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM account_sync_status')->fetchColumn());
    }

    public function testDryRunWritesNothing(): void
    {
        $imp = new SyncStatusImporter($this->db);
        $r = $imp->applyEvent([
            'uniqueId' => 'uuid-1', 'destination' => 'Google Workspace',
            'action' => 'Add', 'actionStatus' => 'Success',
        ], dryRun: true);
        self::assertSame('upserted', $r['outcome']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM account_sync_status')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM account_sync_event')->fetchColumn());
    }
}
