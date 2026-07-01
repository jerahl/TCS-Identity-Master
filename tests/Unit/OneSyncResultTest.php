<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\OneSyncResultImporter;
use PHPUnit\Framework\TestCase;

/**
 * The OneSync export-log enum decoding (inferred from the live value spread:
 * actionStatus 3 dominates = Success, 4 = Fail, 10 = Skipped).
 */
final class OneSyncResultTest extends TestCase
{
    public function testStatusMapping(): void
    {
        self::assertSame('Success', OneSyncResultImporter::status(3));
        self::assertSame('Fail', OneSyncResultImporter::status(4));
        self::assertSame('Skipped', OneSyncResultImporter::status(10));
        self::assertSame('New', OneSyncResultImporter::status(0));
        self::assertSame('New', OneSyncResultImporter::status(99));
    }

    public function testActionMapping(): void
    {
        self::assertSame('Add', OneSyncResultImporter::action(1));
        self::assertSame('Disable', OneSyncResultImporter::action(3));
        self::assertSame('NoChange', OneSyncResultImporter::action(0));
        self::assertSame('Edit', OneSyncResultImporter::action(11)); // update / default
    }

    public function testDestTypeMapping(): void
    {
        self::assertSame('ActiveDirectory', OneSyncResultImporter::destType(3));
        self::assertSame('GSuite', OneSyncResultImporter::destType(5));
        self::assertSame('CSV', OneSyncResultImporter::destType(2));
        self::assertNull(OneSyncResultImporter::destType(99));
    }

    public function testSourceIdsReadsBothFeeds(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS=31');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY=32');
        putenv('ONESYNC_DB_SOURCE_ID'); // legacy unset

        self::assertSame([31, 32], OneSyncResultImporter::sourceIds());

        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
    }

    public function testSourceIdsFallsBackToLegacyAndDedupes(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY=32');
        putenv('ONESYNC_DB_SOURCE_ID=32'); // duplicate of faculty — must collapse

        self::assertSame([32], OneSyncResultImporter::sourceIds());

        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
        putenv('ONESYNC_DB_SOURCE_ID');
    }

    public function testSourceIdsEmptyWhenNoneConfigured(): void
    {
        putenv('ONESYNC_DB_SOURCE_ID_STUDENTS');
        putenv('ONESYNC_DB_SOURCE_ID_FACULTY');
        putenv('ONESYNC_DB_SOURCE_ID');

        self::assertSame([], OneSyncResultImporter::sourceIds());
    }
}
