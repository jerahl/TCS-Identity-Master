<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReviewService;
use PHPUnit\Framework\TestCase;

/**
 * The field-by-field comparison that drives the review card's highlighting.
 * Pure logic — no DB.
 */
final class ReviewCompareTest extends TestCase
{
    private function rowsByLabel(array $in, array $cand): array
    {
        $out = [];
        foreach (ReviewService::compareRows($in, $cand) as $r) {
            $out[$r['label']] = $r;
        }
        return $out;
    }

    public function testInternToEmployeeHighlighting(): void
    {
        // Mockup's Elena case: names + school match, DOB/employee/type differ.
        $in = ['first' => 'Elena', 'last' => 'Ruiz', 'dob' => '2001-06-23', 'gender' => 'Female',
               'employee_id' => '16002', 'type' => 'faculty', 'school' => 'University Place Elementary', 'account' => '(none)'];
        $cand = ['first' => 'Elena', 'last' => 'Ruiz', 'dob' => '', 'gender' => 'Female',
                 'employee_id' => '', 'type' => 'intern', 'school' => 'University Place Elementary', 'account' => 'eruiz@tcs.k12.al.us'];

        $rows = $this->rowsByLabel($in, $cand);
        self::assertSame('match', $rows['First name']['match']);
        self::assertSame('match', $rows['Last name']['match']);
        self::assertSame('diff', $rows['Date of birth']['match']);
        self::assertSame('diff', $rows['Employee ID']['match']);
        self::assertSame('diff', $rows['Type']['match']);
        self::assertSame('match', $rows['Primary school']['match']);
        self::assertSame('info', $rows['Existing account']['match']);
    }

    public function testCaseInsensitiveAndEmptyHandling(): void
    {
        $in = ['first' => 'JOHN', 'last' => '', 'dob' => '', 'gender' => '', 'employee_id' => '',
               'type' => '', 'school' => '', 'account' => '(none)'];
        $cand = ['first' => 'john', 'last' => '', 'dob' => '', 'gender' => '', 'employee_id' => '',
                 'type' => '', 'school' => '', 'account' => 'x@y.z'];

        $rows = $this->rowsByLabel($in, $cand);
        self::assertSame('match', $rows['First name']['match'], 'comparison is case-insensitive');
        self::assertSame('match', $rows['Last name']['match'], 'empty == empty is a match');
        self::assertSame('—', $rows['Last name']['a'], 'empty renders as em dash');
        self::assertSame('info', $rows['Existing account']['match']);
    }

    public function testDifferingValuesFlagDiff(): void
    {
        $in = ['first' => 'John', 'last' => 'Carter', 'dob' => '1998-03-04', 'gender' => 'Male',
               'employee_id' => '16040', 'type' => 'staff', 'school' => 'Eastwood', 'account' => '(none)'];
        $cand = ['first' => 'John', 'last' => 'Carter', 'dob' => '1971-11-22', 'gender' => 'Male',
                 'employee_id' => '11203', 'type' => 'faculty', 'school' => 'Central', 'account' => 'jcarter@x'];

        $rows = $this->rowsByLabel($in, $cand);
        self::assertSame('match', $rows['Last name']['match']);
        self::assertSame('diff', $rows['Date of birth']['match']);
        self::assertSame('diff', $rows['Primary school']['match']);
    }
}
