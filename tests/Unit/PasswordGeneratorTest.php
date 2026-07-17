<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\PasswordGenerator;
use PHPUnit\Framework\TestCase;

/**
 * PasswordGenerator produces a secure but human-readable temp password for new
 * AD accounts: Title-cased words from a bank + a short alphanumeric run + one
 * special character — satisfying AD complexity, avoiding ambiguous glyphs, and
 * differing every call.
 */
final class PasswordGeneratorTest extends TestCase
{
    public function testShapeIsWordsThenAlnumThenSpecial(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $pw = PasswordGenerator::generate();
            // Two Title-cased words, 2–4 lowercase/digit characters, one special.
            self::assertMatchesRegularExpression(
                '/^(?:[A-Z][a-z]+){2}[a-z2-9]{2,4}[!@#$%^&*?+=]$/',
                $pw,
                "unexpected shape: {$pw}"
            );
        }
    }

    public function testMeetsAdComplexityClasses(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $pw = PasswordGenerator::generate();
            self::assertMatchesRegularExpression('/[A-Z]/', $pw, 'needs an uppercase (word caps)');
            self::assertMatchesRegularExpression('/[a-z]/', $pw, 'needs a lowercase');
            self::assertMatchesRegularExpression('/[0-9]/', $pw, 'needs a digit');
            self::assertMatchesRegularExpression('/[!@#$%^&*?+=]/', $pw, 'needs a symbol');
        }
    }

    public function testExcludesAmbiguousGlyphsInTheRandomRun(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $pw = PasswordGenerator::generate();
            // The random alnum run (the tail before the final symbol) must carry
            // no ambiguous glyphs: no 0/1 digits and no lowercase l.
            $run = substr($pw, 0, -1);                 // drop the trailing symbol
            $run = preg_replace('/^(?:[A-Z][a-z]+)+/', '', $run); // drop the words
            self::assertDoesNotMatchRegularExpression('/[01l]/', (string) $run, "run had an ambiguous glyph: {$pw}");
        }
    }

    public function testAlnumRunLengthTracksTheRequestedCount(): void
    {
        for ($n = 2; $n <= 6; $n++) {
            $pw = PasswordGenerator::generate(2, $n);
            $run = preg_replace('/^(?:[A-Z][a-z]+)+/', '', substr($pw, 0, -1));
            self::assertSame($n, strlen((string) $run), "run should be {$n} chars: {$pw}");
            // Always at least one digit and one letter in the run.
            self::assertMatchesRegularExpression('/[2-9]/', (string) $run);
            self::assertMatchesRegularExpression('/[a-z]/', (string) $run);
        }
    }

    public function testWordCountIsHonoredAndWordsAreDistinct(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $pw = PasswordGenerator::generate(3, 3);
            preg_match_all('/[A-Z][a-z]+/', substr($pw, 0, -4), $m);
            self::assertCount(3, $m[0], "expected 3 words: {$pw}");
            self::assertSame($m[0], array_values(array_unique($m[0])), "words repeated: {$pw}");
        }
    }

    public function testProducesDistinctValues(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[PasswordGenerator::generate()] = true;
        }
        // Collisions across 200 draws of a word-pair + random suffix are
        // astronomically unlikely — a tiny spread would signal a broken generator.
        self::assertGreaterThan(195, count($seen));
    }
}
