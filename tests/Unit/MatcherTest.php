<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\NormalizedRow;
use App\Matching\InMemoryMatchLookup;
use App\Matching\Matcher;
use App\Matching\MatchDecision;
use PHPUnit\Framework\TestCase;

/**
 * Thorough coverage of the matcher — the risky part. Exercises every tier, the
 * name-only-never-auto guarantee, the intern→employee link, a same-named
 * different person kept separate, ambiguity, and tier ordering.
 */
final class MatcherTest extends TestCase
{
    private function row(array $o): NormalizedRow
    {
        return new NormalizedRow(
            system: $o['system'] ?? 'nextgen',
            sourceKey: $o['sourceKey'] ?? 'SK1',
            firstName: $o['first'] ?? 'Jane',
            lastName: $o['last'] ?? 'Doe',
            dob: $o['dob'] ?? null,
            employeeId: $o['emp'] ?? null,
        );
    }

    public function testTier1SourceIdAutoMatches(): void
    {
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(10, 'Jane', 'Doe', '1990-01-01');
        $lk->addSourceId('nextgen', 'SK1', 10);

        $d = (new Matcher(90))->match($this->row(['sourceKey' => 'SK1']), $lk);
        self::assertSame(MatchDecision::AUTO, $d->action);
        self::assertSame(10, $d->personId);
        self::assertSame('source_id', $d->basis);
    }

    public function testTier2EmployeeIdAutoMatchesWhenNameAgrees(): void
    {
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(20, 'John', 'Smith', null, '15241');

        $d = (new Matcher(90))->match($this->row(['first' => 'John', 'last' => 'Smith', 'emp' => '15241', 'sourceKey' => 'NEW']), $lk);
        self::assertSame(MatchDecision::AUTO, $d->action);
        self::assertSame(20, $d->personId);
        self::assertSame('employee_id', $d->basis);
    }

    public function testTier2EmployeeIdCollisionWithDifferentNameGoesToReview(): void
    {
        // A sub's SubID happens to equal a teacher's employee number, but they are
        // different people. The bare id must NOT auto-link (that would rename the
        // teacher's golden record) — it goes to review with the clash surfaced.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(20, 'John', 'Smith', null, '4471');   // the teacher

        $d = (new Matcher(90))->match($this->row(['first' => 'Maria', 'last' => 'Lopez', 'emp' => '4471', 'sourceKey' => 'SUB-1']), $lk);
        self::assertSame(MatchDecision::REVIEW, $d->action, 'id match with a different name must never auto-link');
        self::assertNull($d->personId);
        self::assertSame('employee_id_conflict', $d->basis);
        self::assertSame(20, $d->candidates[0]['person_id'], 'the clashing person is surfaced for review');
    }

    public function testTier2EmployeeIdMappingToMultiplePeopleGoesToReview(): void
    {
        // Two golden records already carry the same employee_id (a pre-existing
        // collision). An incoming id match is ambiguous → review, never auto.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(21, 'John', 'Smith', null, '4471');
        $lk->addPerson(22, 'John', 'Smith', null, '4471');

        $d = (new Matcher(90))->match($this->row(['first' => 'John', 'last' => 'Smith', 'emp' => '4471', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::REVIEW, $d->action);
        self::assertNull($d->personId);
        self::assertSame('employee_id_conflict', $d->basis);
        self::assertCount(2, $d->candidates);
    }

    public function testTier2EmployeeIdTypoFallsThroughToNameTierWhenNoHolder(): void
    {
        // A mistyped id that no one holds must not block the name tiers: the row
        // still gets a fair chance to match by name+DOB (here: no name match → NEW).
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(20, 'John', 'Smith', '1980-01-01', '15241');

        $d = (new Matcher(90))->match($this->row(['first' => 'Brand', 'last' => 'Newperson', 'emp' => '99999', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::NEW, $d->action);
        self::assertNull($d->personId);
    }

    public function testTier3NameDobExactAutoMatchesAboveThreshold(): void
    {
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(30, 'Maria', 'Lopez', '1988-07-04');

        $d = (new Matcher(90))->match($this->row(['first' => 'Maria', 'last' => 'Lopez', 'dob' => '1988-07-04', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::AUTO, $d->action);
        self::assertSame(30, $d->personId);
        self::assertSame('name+dob', $d->basis);
        self::assertSame(100.0, $d->score);
    }

    public function testFirstInitialOnlyIsNotACandidate(): void
    {
        // "M" Lopez vs incoming "Maria" Lopez: first names don't match exactly,
        // so even with a matching DOB this is NOT a candidate -> NEW (no review).
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(31, 'M', 'Lopez', '1988-07-04');

        $d = (new Matcher(90))->match($this->row(['first' => 'Maria', 'last' => 'Lopez', 'dob' => '1988-07-04', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::NEW, $d->action);
        self::assertNull($d->personId);
    }

    public function testSharedLastNameDifferentFirstIsNotACandidate(): void
    {
        // The Smith/Jones flood: a different first name with the same last name
        // must NOT become a review candidate.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(32, 'Jane', 'Smith', '1980-05-05');
        $lk->addPerson(33, 'Bob', 'Smith', '1975-01-01');

        $d = (new Matcher(90))->match($this->row(['first' => 'John', 'last' => 'Smith', 'dob' => '1990-02-02', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::NEW, $d->action, 'same last name, different first name is not a match');
        self::assertNull($d->personId);
    }

    public function testFullNameOnlyGoesToReview(): void
    {
        // Exact first + last, candidate has no DOB on file -> name_only review.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(34, 'Maria', 'Lopez', null);

        $d = (new Matcher(90))->match($this->row(['first' => 'Maria', 'last' => 'Lopez', 'dob' => '1988-07-04', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::REVIEW, $d->action);
        self::assertSame('name_only', $d->basis);
        self::assertSame(75.0, $d->score);
        self::assertSame(34, $d->candidates[0]['person_id']);
    }

    public function testNameOnlyNeverAutoLinksEvenWithZeroThreshold(): void
    {
        // Candidate has no DOB -> name-only. Threshold 0 must STILL not auto.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(40, 'Elena', 'Ruiz', null);

        $d = (new Matcher(0))->match($this->row(['first' => 'Elena', 'last' => 'Ruiz', 'dob' => '2001-06-23', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::REVIEW, $d->action, 'name-only must never auto-link');
        self::assertSame('name_only', $d->basis);
        self::assertNull($d->personId);
    }

    public function testInternToEmployeeLinksViaReview(): void
    {
        // Existing intern: source intern_csv:88, no employee_id, no DOB on file.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(3, 'Elena', 'Ruiz', null, null);
        $lk->addSourceId('intern_csv', '88', 3);

        // Incoming NextGen new-hire with the same name and a DOB + new employee id.
        $incoming = $this->row(['system' => 'nextgen', 'sourceKey' => '16002', 'first' => 'Elena', 'last' => 'Ruiz', 'dob' => '2001-06-23', 'emp' => '16002']);
        $d = (new Matcher(90))->match($incoming, $lk);

        self::assertSame(MatchDecision::REVIEW, $d->action);
        self::assertSame('name_only', $d->basis);
        self::assertSame(3, $d->candidates[0]['person_id'], 'should surface the intern as the candidate to confirm');
    }

    public function testSameNameDifferentPersonKeptSeparate(): void
    {
        // Same name, different DOB -> strong evidence they are different humans.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(50, 'John', 'Carter', '1971-11-22');

        $d = (new Matcher(0))->match($this->row(['first' => 'John', 'last' => 'Carter', 'dob' => '1998-03-04', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::REVIEW, $d->action, 'never auto when DOB conflicts, even at threshold 0');
        self::assertSame('name_only', $d->basis);
        self::assertLessThan(50.0, $d->score, 'DOB conflict should depress the score');
    }

    public function testNoCandidateCreatesNew(): void
    {
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(60, 'Someone', 'Else', '1980-01-01');

        $d = (new Matcher(90))->match($this->row(['first' => 'Brand', 'last' => 'Newperson', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::NEW, $d->action);
        self::assertNull($d->personId);
    }

    public function testMissingNameIsSkipped(): void
    {
        $lk = new InMemoryMatchLookup();
        $d = (new Matcher(90))->match($this->row(['first' => '', 'last' => '', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::SKIPPED, $d->action);
    }

    public function testAmbiguousNameDobDoesNotAuto(): void
    {
        // Two people with the same name AND the same DOB -> ambiguous -> review.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(70, 'Chris', 'Park', '1990-09-09');
        $lk->addPerson(71, 'Chris', 'Park', '1990-09-09');

        $d = (new Matcher(90))->match($this->row(['first' => 'Chris', 'last' => 'Park', 'dob' => '1990-09-09', 'sourceKey' => 'X']), $lk);
        self::assertSame(MatchDecision::REVIEW, $d->action);
        self::assertCount(2, $d->candidates);
    }

    public function testSourceIdBeatsConflictingNameMatch(): void
    {
        // Source id points at person 80; a different person 81 shares name+DOB.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(80, 'Pat', 'Quinn', '1985-02-02');
        $lk->addSourceId('nextgen', 'SK1', 80);
        $lk->addPerson(81, 'Pat', 'Quinn', '1985-02-02');

        $d = (new Matcher(90))->match($this->row(['first' => 'Pat', 'last' => 'Quinn', 'dob' => '1985-02-02', 'sourceKey' => 'SK1']), $lk);
        self::assertSame(MatchDecision::AUTO, $d->action);
        self::assertSame(80, $d->personId);
        self::assertSame('source_id', $d->basis);
    }

    public function testReimportOfMultiLocationRowAutoMatchesViaSourceId(): void
    {
        // A multi-location person already linked: a second feed row with the same
        // source key (different school) re-matches the same person (idempotent).
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(90, 'Dana', 'West', '1979-03-03');
        $lk->addSourceId('nextgen', 'EMP90', 90);

        $d = (new Matcher(90))->match($this->row(['sourceKey' => 'EMP90', 'first' => 'Dana', 'last' => 'West']), $lk);
        self::assertSame(MatchDecision::AUTO, $d->action);
        self::assertSame(90, $d->personId);
    }

    public function testNameNormalizationIgnoresCasePunctuationAccents(): void
    {
        // Case-insensitive; punctuation becomes a separator; whitespace collapses.
        self::assertSame(Matcher::norm("O'Brien"), Matcher::norm("o'brien"));
        self::assertSame('o brien', Matcher::norm("O'Brien"));
        self::assertSame('mary jane', Matcher::norm('  Mary   Jane '));
        self::assertSame('josé', Matcher::norm('JOSÉ'));
    }
}
