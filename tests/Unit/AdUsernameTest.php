<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\AdUsernameImporter;
use PHPUnit\Framework\TestCase;

/**
 * The AD uniqueId -> PowerSchool id derivation for the one-time username link.
 */
final class AdUsernameTest extends TestCase
{
    public function testStripsLeadingT(): void
    {
        self::assertSame('14774', AdUsernameImporter::psIdFromUniqueId('T14774'));
        self::assertSame('14774', AdUsernameImporter::psIdFromUniqueId('t14774'));
        self::assertSame('1001', AdUsernameImporter::psIdFromUniqueId(' T1001 '));
    }

    public function testLeavesNonPrefixedValueAlone(): void
    {
        self::assertSame('14774', AdUsernameImporter::psIdFromUniqueId('14774'));
        self::assertSame('', AdUsernameImporter::psIdFromUniqueId(''));
    }
}
