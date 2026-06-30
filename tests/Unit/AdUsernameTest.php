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

    public function testDetectFormatFromHeaders(): void
    {
        self::assertSame('teachers', AdUsernameImporter::detectFormat(['TEACHERS.ID' => '8422', 'TEACHERS.TeacherLoginID' => 'kabraham']));
        self::assertSame('ad', AdUsernameImporter::detectFormat(['uniqueId' => 'T8422', 'sAMAccountName' => 'kabraham']));
        // Adaxes "Employee List" export: detected by Object GUID / pre-Win2000 logon.
        self::assertSame('employee_list', AdUsernameImporter::detectFormat([
            'First name' => 'A', 'Email' => 'a@x.org', 'Logon Name (pre-Windows 2000)' => 'a',
            'Employee ID' => '1', 'Object GUID' => '06f33027-ef8e-4bf2-89ed-661b51fbb4bd', 'Name' => 'A B',
        ]));
    }
}
