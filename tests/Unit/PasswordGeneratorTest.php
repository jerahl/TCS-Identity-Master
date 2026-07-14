<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\PasswordGenerator;
use PHPUnit\Framework\TestCase;

/**
 * PasswordGenerator produces strong temp passwords for new AD accounts: at least
 * one character from each complexity class (so AD's default policy is satisfied),
 * a floor length, no ambiguous glyphs, and a different value each call.
 */
final class PasswordGeneratorTest extends TestCase
{
    public function testMeetsComplexityAndMinimumLength(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $pw = PasswordGenerator::generate();
            self::assertGreaterThanOrEqual(16, strlen($pw));
            self::assertMatchesRegularExpression('/[A-Z]/', $pw, 'needs an uppercase');
            self::assertMatchesRegularExpression('/[a-z]/', $pw, 'needs a lowercase');
            self::assertMatchesRegularExpression('/[0-9]/', $pw, 'needs a digit');
            self::assertMatchesRegularExpression('/[!@#$%^&*()\-_=+]/', $pw, 'needs a symbol');
        }
    }

    public function testExcludesAmbiguousGlyphs(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $pw = PasswordGenerator::generate();
            // 0/O, 1/l/I are excluded so a temp password isn't mistyped.
            self::assertDoesNotMatchRegularExpression('/[0O1lI]/', $pw);
        }
    }

    public function testHonorsRequestedLengthAboveTheFloor(): void
    {
        self::assertSame(32, strlen(PasswordGenerator::generate(32)));
        // Below the floor is clamped up.
        self::assertGreaterThanOrEqual(16, strlen(PasswordGenerator::generate(4)));
    }

    public function testProducesDistinctValues(): void
    {
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $seen[PasswordGenerator::generate()] = true;
        }
        // Collisions across 100 draws of a 20-char CSPRNG password are astronomically
        // unlikely — a tiny spread would signal a broken generator.
        self::assertGreaterThan(95, count($seen));
    }
}
