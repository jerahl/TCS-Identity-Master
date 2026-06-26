<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\PowerSchoolBundle;
use PHPUnit\Framework\TestCase;

/**
 * The 3-file PowerSchool join: one person per USERS.dcid, every TEACHERS.ID
 * collected, school assignments from SchoolStaff with one primary.
 */
final class PowerSchoolBundleTest extends TestCase
{
    public function testCombinesMultiSchoolUser(): void
    {
        // Darby Allen: 4 TEACHERS rows (one per school), Users_DCID 1011.
        $users = [
            ['USERS.dcid' => '1011', 'USERS.First_Name' => 'Darby', 'USERS.Middle_Name' => 'K', 'USERS.Last_Name' => 'Allen',
             'USERS.HomeSchoolId' => '160', 'USERS.TeacherNumber' => '12924', 'USERS.Title' => 'Teacher',
             'U_DEF_EXT_USERS.staff_classification' => 'Certified', 'S_USR_X.hiredate' => '', 'S_AL_USR_X.exit_date' => ''],
        ];
        $teachers = [
            ['TEACHERS.ID' => '1011', 'TEACHERS.dcid' => '1011', 'TEACHERS.Users_DCID' => '1011', 'TEACHERS.TeacherNumber' => '12924', 'TEACHERS.First_Name' => 'Darby', 'TEACHERS.Last_Name' => 'Allen'],
            ['TEACHERS.ID' => '2901', 'TEACHERS.dcid' => '2901', 'TEACHERS.Users_DCID' => '1011', 'TEACHERS.TeacherNumber' => '12924', 'TEACHERS.First_Name' => 'Darby', 'TEACHERS.Last_Name' => 'Allen'],
            ['TEACHERS.ID' => '2902', 'TEACHERS.dcid' => '2902', 'TEACHERS.Users_DCID' => '1011', 'TEACHERS.TeacherNumber' => '12924', 'TEACHERS.First_Name' => 'Darby', 'TEACHERS.Last_Name' => 'Allen'],
        ];
        $staff = [
            ['SCHOOLSTAFF.dcid' => '1011', 'SCHOOLSTAFF.Users_DCID' => '1011', 'SCHOOLSTAFF.SchoolID' => '6000'],
            ['SCHOOLSTAFF.dcid' => '2901', 'SCHOOLSTAFF.Users_DCID' => '1011', 'SCHOOLSTAFF.SchoolID' => '160'],
            ['SCHOOLSTAFF.dcid' => '2902', 'SCHOOLSTAFF.Users_DCID' => '1011', 'SCHOOLSTAFF.SchoolID' => '75'],
        ];

        $out = PowerSchoolBundle::combine($users, $teachers, $staff);
        self::assertCount(1, $out);
        $ps = $out[0];

        self::assertSame('1011', $ps->usersDcid);
        self::assertSame('12924', $ps->employeeId, 'employee id = TeacherNumber');
        self::assertSame('Darby', $ps->firstName);
        self::assertSame(['1011', '2901', '2902'], $ps->teacherIds, 'every TEACHERS.ID collected');
        self::assertCount(3, $ps->schools);

        // HomeSchoolId 160 is the primary; exactly one primary overall.
        self::assertSame('160', $ps->primarySchoolCode());
        self::assertCount(1, array_filter($ps->schools, static fn($s) => $s['primary']));
    }

    public function testSelectByKindPrefersCanonicalFilename(): void
    {
        // MultipleID.csv also has TEACHERS.* columns; TeachersID.csv must win.
        $teacherHeader = ['TEACHERS.ID' => '', 'TEACHERS.Users_DCID' => ''];
        $headers = [
            '/feeds/ps/Users_export.csv'      => ['USERS.dcid' => ''],
            '/feeds/ps/MultipleID.csv'        => $teacherHeader,
            '/feeds/ps/TeachersID.csv'        => $teacherHeader,
            '/feeds/ps/SchoolStaff_export.csv' => ['SCHOOLSTAFF.dcid' => ''],
        ];
        $pick = PowerSchoolBundle::selectByKind($headers);

        self::assertSame('/feeds/ps/TeachersID.csv', $pick['teachers'], 'TeachersID beats MultipleID');
        self::assertSame('/feeds/ps/Users_export.csv', $pick['users']);
        self::assertSame('/feeds/ps/SchoolStaff_export.csv', $pick['schoolstaff']);
    }

    public function testSelectByKindFallsBackToFirstWhenNoNameMatch(): void
    {
        $headers = ['/x/odd_name.csv' => ['TEACHERS.ID' => '']];
        self::assertSame('/x/odd_name.csv', PowerSchoolBundle::selectByKind($headers)['teachers']);
    }

    public function testFallsBackToTeacherNamesAndFirstPrimary(): void
    {
        // No USERS row, no HomeSchoolId -> names from TEACHERS, first school primary.
        $teachers = [
            ['TEACHERS.ID' => '500', 'TEACHERS.dcid' => '500', 'TEACHERS.Users_DCID' => '77', 'TEACHERS.TeacherNumber' => '900', 'TEACHERS.First_Name' => 'Sam', 'TEACHERS.Last_Name' => 'Rivera'],
        ];
        $staff = [
            ['SCHOOLSTAFF.dcid' => '500', 'SCHOOLSTAFF.Users_DCID' => '77', 'SCHOOLSTAFF.SchoolID' => '55'],
        ];
        $out = PowerSchoolBundle::combine([], $teachers, $staff);

        self::assertCount(1, $out);
        self::assertSame('Sam', $out[0]->firstName);
        self::assertSame('Rivera', $out[0]->lastName);
        self::assertSame('55', $out[0]->primarySchoolCode());
        self::assertTrue($out[0]->schools[0]['primary']);
    }
}
