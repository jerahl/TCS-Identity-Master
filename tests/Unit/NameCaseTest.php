<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\NameCase;
use PHPUnit\Framework\TestCase;

/**
 * The pure name-casing helper: conventional "first letter capital" output for
 * the messy casings that arrive in HR feeds, with the common exceptions
 * (McDonald, O'Brien, hyphenated, Roman-numeral suffix) handled and ordinary
 * surnames left alone.
 */
final class NameCaseTest extends TestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function names(): array
    {
        return [
            'all upper'        => ['JAMES', 'James'],
            'all lower'        => ['smith', 'Smith'],
            'already correct'  => ['James', 'James'],
            'mixed junk'       => ['mCdOnALd', 'McDonald'],
            'mc lower'         => ['mcdonald', 'McDonald'],
            'mc upper'         => ['MCDONALD', 'McDonald'],
            'mc already'       => ['McDonald', 'McDonald'],
            'apostrophe'       => ["o'brien", "O'Brien"],
            'apostrophe upper' => ["O'BRIEN", "O'Brien"],
            'hyphenated'       => ['smith-jones', 'Smith-Jones'],
            'hyphen upper'     => ['SMITH-JONES', 'Smith-Jones'],
            'apostrophe+hyph'  => ["o'neal-smith", "O'Neal-Smith"],
            'multi word'       => ['de la cruz', 'De La Cruz'],
            'roman suffix'     => ['iii', 'III'],
            'name with suffix' => ['john iii', 'John III'],
            'roman-ish surname'=> ['dill', 'Dill'],   // not a whitelisted suffix — left as a word
            'accented'         => ['JOSÉ', 'José'],
            'extra whitespace' => ['  james    smith  ', 'James Smith'],
            'empty'            => ['', ''],
            'whitespace only'  => ['   ', ''],
        ];
    }

    /** @dataProvider names */
    public function testFormat(string $in, string $expected): void
    {
        self::assertSame($expected, NameCase::format($in));
    }

    public function testIdempotent(): void
    {
        foreach (self::names() as [$in, $expected]) {
            self::assertSame($expected, NameCase::format(NameCase::format($in)));
        }
    }
}
