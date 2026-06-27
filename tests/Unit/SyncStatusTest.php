<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\SyncStatusImporter;
use PHPUnit\Framework\TestCase;

/**
 * Normalization of OneSync export-log values into the account_sync_status enums,
 * and destination-type derivation.
 */
final class SyncStatusTest extends TestCase
{
    public function testActionNormalization(): void
    {
        self::assertSame('Add', SyncStatusImporter::normalizeAction('add'));
        self::assertSame('Edit', SyncStatusImporter::normalizeAction('Update'));
        self::assertSame('Disable', SyncStatusImporter::normalizeAction('DISABLE'));
        self::assertSame('NoChange', SyncStatusImporter::normalizeAction('no change'));
        self::assertNull(SyncStatusImporter::normalizeAction('frobnicate'));
    }

    public function testStatusNormalization(): void
    {
        self::assertSame('Success', SyncStatusImporter::normalizeStatus('succeeded'));
        self::assertSame('Fail', SyncStatusImporter::normalizeStatus('FAILED'));
        self::assertSame('Fail', SyncStatusImporter::normalizeStatus('error'));
        self::assertSame('Skipped', SyncStatusImporter::normalizeStatus('skip'));
        self::assertNull(SyncStatusImporter::normalizeStatus('weird'));
    }

    public function testDestTypeDerivation(): void
    {
        self::assertSame('GSuite', SyncStatusImporter::deriveDestType('Google Workspace'));
        self::assertSame('ActiveDirectory', SyncStatusImporter::deriveDestType('Faculty Active Directory'));
        self::assertSame('CSV', SyncStatusImporter::deriveDestType('Raptor'));
        self::assertSame('CSV', SyncStatusImporter::deriveDestType('PowerSchool'));
        self::assertNull(SyncStatusImporter::deriveDestType('Mystery System'));
        // Explicit value wins.
        self::assertSame('Custom', SyncStatusImporter::deriveDestType('Anything', 'Custom'));
    }
}
