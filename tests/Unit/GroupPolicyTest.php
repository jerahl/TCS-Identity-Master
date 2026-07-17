<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GroupPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GroupPolicy — the Phase 4 AD group membership rules from the OneSync Faculty
 * destination: All-Faculty for everyone, a per-school Everyone group (with the
 * UP/UPE→UPES, RQS→RQES, STC→CO, … naming exceptions), Transportation for bus drivers, one M365
 * license group (A1 for a keyword/type set, else A3), and one Raptor role group.
 * Pure logic — group names are injected so assertions don't depend on env.
 */
final class GroupPolicyTest extends TestCase
{
    private function policy(): GroupPolicy
    {
        return new GroupPolicy('All-Faculty', 'Transportation', '-Everyone', 'A1', 'A3');
    }

    /** @param array{0:string,1:string,2:string,3:bool} $_ */
    private function desired(string $title, string $type, string $token, bool $trans): array
    {
        return $this->policy()->desiredGroups($title, $type, $token, $trans);
    }

    public function testEveryoneGetsAllFaculty(): void
    {
        self::assertContains('All-Faculty', $this->desired('Teacher', 'faculty', 'CO', false));
    }

    public function testPerSchoolEveryoneGroupFromOuToken(): void
    {
        self::assertContains('CO-Everyone', $this->desired('Teacher', 'faculty', 'CO', false));
        self::assertContains('BHS-Everyone', $this->desired('Teacher', 'faculty', 'BHS', false));
    }

    public function testEveryoneGroupNamingExceptions(): void
    {
        $p = $this->policy();
        // OU token → Everyone group prefix. Both spellings of University Place /
        // Rock Quarry map to the same group, so it's correct either way.
        self::assertSame('UPES-Everyone', $p->everyoneGroup('UP'));
        self::assertSame('UPES-Everyone', $p->everyoneGroup('UPE'));
        self::assertSame('RQES-Everyone', $p->everyoneGroup('RQS'));
        self::assertSame('RQES-Everyone', $p->everyoneGroup('RQES'));
        self::assertSame('OAKD-Everyone', $p->everyoneGroup('OKD'));
        self::assertSame('OAKH-Everyone', $p->everyoneGroup('OKH'));
        self::assertSame('CPS-Everyone', $p->everyoneGroup('CES'));
        self::assertSame('CO-Everyone', $p->everyoneGroup('STC'));
        // A building with no exception keeps its own token.
        self::assertSame('BHS-Everyone', $p->everyoneGroup('BHS'));
        self::assertContains('UPES-Everyone', $this->desired('Teacher', 'faculty', 'UP', false));
        self::assertContains('CO-Everyone', $this->desired('Teacher', 'faculty', 'STC', false));
    }

    public function testNoSchoolTokenMeansNoEveryoneGroup(): void
    {
        $groups = $this->desired('Bus Driver', 'staff', '', true);
        foreach ($groups as $g) {
            self::assertStringNotContainsString('-Everyone', $g);
        }
    }

    public function testTransportationGroupForBusDrivers(): void
    {
        self::assertContains('Transportation', $this->desired('Bus Driver', 'staff', '', true));
        self::assertNotContains('Transportation', $this->desired('Teacher', 'faculty', 'CO', false));
    }

    #[DataProvider('a1Cases')]
    public function testM365A1Assignment(string $title, string $type): void
    {
        $groups = $this->desired($title, $type, 'CO', false);
        self::assertContains('A1', $groups);
        self::assertNotContains('A3', $groups);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function a1Cases(): array
    {
        return [
            'CNP'          => ['CNP Worker', 'staff'],
            'custodian'    => ['Head Custodian', 'staff'],
            'bus driver'   => ['Bus Driver', 'staff'],
            'aide'         => ['Teacher Aide', 'staff'],
            'sub in title' => ['Substitute Teacher', 'staff'],
            'intern title' => ['Intern', 'faculty'],
            'sro phrase'   => ['School Resource Officer', 'contractor'],
            'contractor'   => ['Vendor Rep', 'contractor'],
            'sub type'     => ['Filler', 'sub'],
            'intern type'  => ['Filler', 'intern'],
        ];
    }

    public function testM365A3IsTheDefaultForRegularStaff(): void
    {
        $groups = $this->desired('Teacher - Math', 'faculty', 'CO', false);
        self::assertContains('A3', $groups);
        self::assertNotContains('A1', $groups);
    }

    #[DataProvider('raptorCases')]
    public function testRaptorRoleAssignment(string $title, string $expected): void
    {
        self::assertContains($expected, $this->desired($title, 'faculty', 'CO', false));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function raptorCases(): array
    {
        return [
            'principal'        => ['Principal - High', 'Raptor_BuildingAdmin'],
            'it computer tech' => ['IT Computer Tech', 'Raptor_BuildingAdmin'],
            'it tech super'    => ['IT Technician Supervisor', 'Raptor_ClientAdmin'],
            'safety contractor'=> ['Safety Contractor', 'Raptor_ClientAdmin'],
            'director of tech' => ['Director of Technology', 'Raptor_ClientAdmin'],
            'secretary'        => ['Secretary', 'Raptor_EntryAdmin'],
            'bookkeeper'       => ['Bookkeeper', 'Raptor_EntryAdmin'],
            'network admin'    => ['Network Administrator', 'Raptor_GlobalAdmin'],
            'security spec'    => ['Security Specialist', 'Raptor_GlobalAdmin'],
            'default'          => ['Teacher', 'Raptor_EmergencyManagementUser'],
        ];
    }

    public function testRaptorOverrideForcesGroupRegardlessOfTitle(): void
    {
        // A Teacher would earn only the EmergencyManagement fallback; the override
        // grants Raptor_ClientAdmin instead (an exception by user).
        $groups = $this->policy()->desiredGroups('Teacher - Math', 'faculty', 'CO', false, 'clientadmin');
        self::assertContains('Raptor_ClientAdmin', $groups);
        self::assertNotContains('Raptor_EmergencyManagementUser', $groups);
        // Still exactly one Raptor group.
        self::assertCount(1, array_filter($groups, static fn($g) => str_starts_with($g, 'Raptor_')));
    }

    public function testRaptorOverrideNoneExcludesEveryRaptorGroup(): void
    {
        // 'none' removes the person from all Raptor groups even though the title
        // (Principal) would normally earn BuildingAdmin.
        $groups = $this->policy()->desiredGroups('Principal', 'faculty', 'CO', false, 'none');
        self::assertSame([], array_filter($groups, static fn($g) => str_starts_with($g, 'Raptor_')));
    }

    public function testRaptorOverrideEmptyIsAutomaticByTitle(): void
    {
        $groups = $this->policy()->desiredGroups('Principal', 'faculty', 'CO', false, '');
        self::assertContains('Raptor_BuildingAdmin', $groups);
    }

    public function testRaptorOverrideUnknownKeyFailsSafeToTitle(): void
    {
        $groups = $this->policy()->desiredGroups('Principal', 'faculty', 'CO', false, 'bogus-role');
        self::assertContains('Raptor_BuildingAdmin', $groups);
    }

    public function testRaptorRoleOptionsAndValidation(): void
    {
        $opts = $this->policy()->raptorRoleOptions();
        // The three exceptions the request names, plus automatic + none.
        foreach (['', 'buildingadmin', 'clientadmin', 'entryadmin', 'none'] as $key) {
            self::assertArrayHasKey($key, $opts);
        }
        self::assertSame('Raptor_ClientAdmin', $opts['clientadmin']);

        self::assertTrue($this->policy()->isValidRaptorOverride(''));
        self::assertTrue($this->policy()->isValidRaptorOverride('none'));
        self::assertTrue($this->policy()->isValidRaptorOverride('CLIENTADMIN')); // case-insensitive
        self::assertFalse($this->policy()->isValidRaptorOverride('bogus-role'));
    }

    public function testExactlyOneRaptorRoleAndOneLicenseGroup(): void
    {
        // The Raptor ROLE is exactly one (StudentSafeUser is additive, see below).
        $groups = $this->desired('Principal', 'faculty', 'CO', false);
        $roles = array_filter(
            $groups,
            static fn($g) => str_starts_with($g, 'Raptor_') && $g !== 'Raptor_StudentSafeUser',
        );
        self::assertCount(1, $roles);
        $license = array_filter($groups, static fn($g) => in_array($g, ['A1', 'A3'], true));
        self::assertCount(1, $license);
    }

    #[DataProvider('studentSafeCases')]
    public function testStudentSafeUserIsAdditiveByTitle(string $title): void
    {
        // Granted ON TOP OF the person's Raptor role, not instead of it.
        $groups = $this->desired($title, 'faculty', 'CO', false);
        self::assertContains('Raptor_StudentSafeUser', $groups);
    }

    /** @return array<string,array{0:string}> */
    public static function studentSafeCases(): array
    {
        return [
            'principal'           => ['Principal - High'],
            'assistant principal' => ['Assistant Principal'],
            'social worker'       => ['School Social Worker'],
            'counselor'           => ['Guidance Counselor'],
            'plural counselors'   => ['Counselors'],
        ];
    }

    public function testPrincipalKeepsRoleAndAlsoGetsStudentSafeUser(): void
    {
        // A Principal earns BuildingAdmin by title AND StudentSafeUser additively.
        $groups = $this->desired('Principal', 'faculty', 'CO', false);
        self::assertContains('Raptor_BuildingAdmin', $groups);
        self::assertContains('Raptor_StudentSafeUser', $groups);
    }

    public function testNonQualifyingTitleGetsNoStudentSafeUser(): void
    {
        $groups = $this->desired('Teacher - Math', 'faculty', 'CO', false);
        self::assertNotContains('Raptor_StudentSafeUser', $groups);
    }

    public function testStudentSafeUserSuppressedByNoneOverride(): void
    {
        // 'none' opts out of EVERY Raptor group, including the additive one.
        $groups = $this->policy()->desiredGroups('Principal', 'faculty', 'CO', false, 'none');
        self::assertNotContains('Raptor_StudentSafeUser', $groups);
        self::assertSame([], array_filter($groups, static fn($g) => str_starts_with($g, 'Raptor_')));
    }

    public function testStudentSafeUserStillGrantedWhenRoleOverridden(): void
    {
        // A forced role override changes only the exclusive role; StudentSafeUser
        // stays title-driven, so a Principal forced to ClientAdmin keeps it.
        $groups = $this->policy()->desiredGroups('Principal', 'faculty', 'CO', false, 'clientadmin');
        self::assertContains('Raptor_ClientAdmin', $groups);
        self::assertContains('Raptor_StudentSafeUser', $groups);
        self::assertNotContains('Raptor_BuildingAdmin', $groups);
    }

    public function testStudentSafeUserIsInManagedAndFixedSets(): void
    {
        self::assertContains('Raptor_StudentSafeUser', $this->policy()->fixedManagedGroups());
        self::assertArrayHasKey('raptor_studentsafeuser', $this->policy()->managedGroups(['CO']));
    }

    public function testFullFacultyMembershipSet(): void
    {
        self::assertSame(
            ['All-Faculty', 'CO-Everyone', 'A3', 'Raptor_EmergencyManagementUser'],
            $this->desired('Teacher - Math', 'faculty', 'CO', false),
        );
    }

    public function testBusDriverMembershipSet(): void
    {
        self::assertSame(
            ['All-Faculty', 'Transportation', 'A1', 'Raptor_EmergencyManagementUser'],
            $this->desired('Bus Driver', 'staff', '', true),
        );
    }

    public function testManagedSetIsExactAndCaseInsensitive(): void
    {
        // The managed set = fixed groups + the Everyone group for each KNOWN
        // building token; anything else is custom/manual and never removed.
        $managed = $this->policy()->managedGroups(['CO', 'RQES', 'BHS']);

        foreach (['all-faculty', 'transportation', 'a1', 'a3', 'raptor_clientadmin', 'raptor_emergencymanagementuser'] as $g) {
            self::assertArrayHasKey($g, $managed);
        }
        self::assertArrayHasKey('co-everyone', $managed);
        self::assertArrayHasKey('rqes-everyone', $managed);  // RQES → RQES-Everyone
        self::assertArrayHasKey('bhs-everyone', $managed);

        // Not a known building, and not a fixed group → NOT managed (left alone).
        self::assertArrayNotHasKey('ems-everyone', $managed);
        self::assertArrayNotHasKey('domain users', $managed);
        self::assertArrayNotHasKey('vpn-users', $managed);
    }

    public function testFixedManagedGroups(): void
    {
        $fixed = $this->policy()->fixedManagedGroups();
        self::assertContains('All-Faculty', $fixed);
        self::assertContains('Raptor_EmergencyManagementUser', $fixed);
        self::assertNotContains('CO-Everyone', $fixed); // per-school, not fixed
    }
}
