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
        self::assertSame('14774', AdUsernameImporter::stripLeadingT('T14774'));
        self::assertSame('14774', AdUsernameImporter::stripLeadingT('t14774'));
        self::assertSame('1001', AdUsernameImporter::stripLeadingT(' T1001 '));
    }

    public function testLeavesNonPrefixedValueAlone(): void
    {
        self::assertSame('14774', AdUsernameImporter::stripLeadingT('14774'));
        self::assertSame('', AdUsernameImporter::stripLeadingT(''));
    }

    public function testResolvesUniqueIdThroughSchoolStaffMap(): void
    {
        // AD uniqueId T8422 -> SCHOOLSTAFF.dcid 8422 -> Users_DCID 6260 (PS key).
        $tmp = tempnam(sys_get_temp_dir(), 'idm_ss');
        file_put_contents($tmp,
            "SCHOOLSTAFF.dcid,SCHOOLSTAFF.Users_DCID\r8422,6260\r9001,7001\r");
        $imp = new AdUsernameImporter(self::fakePdo());
        $imp->loadSchoolStaff($tmp);
        unlink($tmp);

        self::assertSame('6260', $imp->resolvePsId('T8422'), 'translates via SchoolStaff');
        self::assertSame('7001', $imp->resolvePsId('t9001'));
        // Unknown dcid falls back to the stripped value unchanged.
        self::assertSame('5555', $imp->resolvePsId('T5555'));
    }

    /** A throwaway in-memory PDO so the importer constructs without a real DB. */
    private static function fakePdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }
}
