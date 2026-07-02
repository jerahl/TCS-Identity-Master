<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\LoginsReportService;
use PHPUnit\Framework\TestCase;

/**
 * The pure projection that turns a golden-record row into the Logins spreadsheet
 * columns — MI formatting, board-approval combining, To-Position fallback, and the
 * From (prior-assignment) derivation. DB-free: exercises project() directly.
 */
final class LoginsReportServiceTest extends TestCase
{
    /**
     * A DB-shaped row as rows() would fetch it, with sensible defaults.
     * Uses array_merge so an override can explicitly set a field to null.
     */
    private function dbRow(array $o = []): array
    {
        return array_merge([
            'person_id'           => 1,
            'person_uuid'         => 'uuid-1',
            'first_name'          => 'Jane',
            'middle_name'         => null,
            'last_name'           => 'Doe',
            'employee_id'         => '12345',
            'dob'                 => '1990-04-01',
            'gender'              => 'Female',
            'ethnicity_source'    => 'White',
            'alsde_id'            => null,
            'position_number'     => null,
            'status'              => 'active',
            'board_approval_date' => null,
            'board_approval_note' => null,
            'effective_date'      => '2026-08-01',
            'end_date'            => null,
            'to_school'           => 'Central High School',
            'to_title'            => 'Teacher',
            'from_title'          => null,
            'from_school'         => null,
        ], $o);
    }

    public function testColumnsAreTheFourteenLoginsFieldsInOrder(): void
    {
        $cols = LoginsReportService::columns();
        self::assertSame(
            ['last_name', 'first_mi', 'from_school', 'from_position', 'to_position',
             'to_school', 'effective_date', 'end_date', 'board_approval', 'employee_id',
             'dob', 'gender', 'race', 'alsde_id'],
            array_keys($cols)
        );
        self::assertSame('Board Approval', $cols['board_approval']);
        self::assertSame('ALSDE ID', $cols['alsde_id']);
    }

    public function testFirstNameMiUsesMiddleInitial(): void
    {
        $row = LoginsReportService::project($this->dbRow(['first_name' => 'Jane', 'middle_name' => 'quinn']));
        self::assertSame('Jane Q.', $row['first_mi']);
    }

    public function testFirstNameMiOmitsInitialWhenNoMiddleName(): void
    {
        $row = LoginsReportService::project($this->dbRow(['first_name' => 'Jane', 'middle_name' => null]));
        self::assertSame('Jane', $row['first_mi']);
    }

    public function testRaceMapsFromEthnicitySource(): void
    {
        $row = LoginsReportService::project($this->dbRow(['ethnicity_source' => 'Black or African American']));
        self::assertSame('Black or African American', $row['race']);
    }

    public function testBoardApprovalCombinesDateAndNote(): void
    {
        $row = LoginsReportService::project($this->dbRow([
            'board_approval_date' => '2026-06-15', 'board_approval_note' => 'Item 4.2',
        ]));
        self::assertSame('2026-06-15 (Item 4.2)', $row['board_approval']);
    }

    public function testBoardApprovalNoteOnlyWhenNoDate(): void
    {
        $row = LoginsReportService::project($this->dbRow([
            'board_approval_date' => null, 'board_approval_note' => 'pending',
        ]));
        self::assertSame('pending', $row['board_approval']);
    }

    public function testBoardApprovalBlankWhenNeither(): void
    {
        $row = LoginsReportService::project($this->dbRow());
        self::assertSame('', $row['board_approval']);
    }

    public function testToPositionFallsBackToPositionNumberWhenNoTitle(): void
    {
        $row = LoginsReportService::project($this->dbRow(['to_title' => null, 'position_number' => 'POS-7']));
        self::assertSame('POS-7', $row['to_position']);
    }

    public function testTransferShowsFromSchoolAndPosition(): void
    {
        $row = LoginsReportService::project($this->dbRow([
            'from_school' => 'East Elementary', 'from_title' => 'Aide',
            'to_school'   => 'Central High School', 'to_title' => 'Teacher',
        ]));
        self::assertSame('East Elementary', $row['from_school']);
        self::assertSame('Aide', $row['from_position']);
        self::assertSame('Central High School', $row['to_school']);
        self::assertSame('Teacher', $row['to_position']);
    }

    public function testNewHireHasBlankFromColumns(): void
    {
        // A brand-new hire has no prior (non-primary) assignment: From is blank.
        $row = LoginsReportService::project($this->dbRow(['from_school' => null, 'from_title' => null]));
        self::assertSame('', $row['from_school']);
        self::assertSame('', $row['from_position']);
    }
}
