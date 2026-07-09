<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GroupPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GroupPolicy — the Phase 4 AD group membership rules from the OneSync Faculty
 * destination: All-Faculty for everyone, a per-school Everyone group (with the
 * RQES→RQS / UPE→UP naming exceptions), Transportation for bus drivers, one M365
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
        // RQES→RQS and UPE→UP.
        self::assertSame('RQS-Everyone', $this->policy()->everyoneGroup('RQES'));
        self::assertSame('UP-Everyone', $this->policy()->everyoneGroup('UPE'));
        self::assertContains('RQS-Everyone', $this->desired('Teacher', 'faculty', 'RQES', false));
        self::assertContains('UP-Everyone', $this->desired('Teacher', 'faculty', 'UPE', false));
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

    public function testExactlyOneRaptorAndOneLicenseGroup(): void
    {
        $groups = $this->desired('Principal', 'faculty', 'CO', false);
        $raptor = array_filter($groups, static fn($g) => str_starts_with($g, 'Raptor_'));
        self::assertCount(1, $raptor);
        $license = array_filter($groups, static fn($g) => in_array($g, ['A1', 'A3'], true));
        self::assertCount(1, $license);
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

    public function testIsManaged(): void
    {
        $p = $this->policy();
        self::assertTrue($p->isManaged('All-Faculty'));
        self::assertTrue($p->isManaged('Transportation'));
        self::assertTrue($p->isManaged('A1'));
        self::assertTrue($p->isManaged('Raptor_ClientAdmin'));
        self::assertTrue($p->isManaged('CO-Everyone'));
        self::assertTrue($p->isManaged('rqs-everyone')); // case-insensitive
        // Groups IDM does not own are never removed.
        self::assertFalse($p->isManaged('Domain Users'));
        self::assertFalse($p->isManaged('VPN-Users'));
    }
}
