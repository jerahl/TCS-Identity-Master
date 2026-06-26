<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\Importer;
use App\Matching\Matcher;
use PHPUnit\Framework\TestCase;

/**
 * The non-person account filter (PowerSchool Admin / Lookup rows). Matches on
 * first OR last name against a normalized exclude list.
 */
final class ImportExcludeTest extends TestCase
{
    /** @return string[] */
    private function exclude(): array
    {
        return array_map(static fn(string $s) => Matcher::norm($s), ['admin', 'lookup']);
    }

    public function testExcludesByFirstOrLastName(): void
    {
        self::assertTrue(Importer::nameExcluded('Admin', 'Account', $this->exclude()));
        self::assertTrue(Importer::nameExcluded('PS', 'Lookup', $this->exclude()));
        self::assertTrue(Importer::nameExcluded('ADMIN', 'ADMIN', $this->exclude()), 'case-insensitive');
    }

    public function testKeepsRealPeople(): void
    {
        self::assertFalse(Importer::nameExcluded('Jennifer', 'Marsh', $this->exclude()));
        // A real surname that merely contains the token is NOT excluded (exact match).
        self::assertFalse(Importer::nameExcluded('Bob', 'Adminson', $this->exclude()));
    }

    public function testEmptyListExcludesNothing(): void
    {
        self::assertFalse(Importer::nameExcluded('Admin', 'Lookup', []));
    }
}
