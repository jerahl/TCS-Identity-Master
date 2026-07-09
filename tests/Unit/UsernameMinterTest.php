<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\UsernameMinter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * UsernameMinter — the Phase-3 identity policy (docs/adaxes-provisioning-design.md):
 * first-initial + last name, deterministic casing, punctuation stripped, an
 * integer suffix on collision (starting at 1), a 20-char sAMAccountName cap that
 * truncates the last name but never the suffix, and a data-error guard for empty
 * names. Pure logic — the collision check is injected, so no DB or live AD.
 */
final class UsernameMinterTest extends TestCase
{
    /** Nothing is ever taken → the bare base. */
    private static function free(): callable
    {
        return static fn(string $c): bool => false;
    }

    /** The given (case-insensitive) set of names is taken. */
    private static function taken(string ...$names): callable
    {
        $set = array_map('strtolower', $names);
        return static fn(string $c): bool => in_array(strtolower($c), $set, true);
    }

    public function testBaseFormAndCasing(): void
    {
        self::assertSame('JSmith', UsernameMinter::base('John', 'Smith'));
        // Deterministic casing regardless of source casing.
        self::assertSame('JSmith', UsernameMinter::base('john', 'SMITH'));
        self::assertSame('JSmith', UsernameMinter::base('jOHN', 'smith'));
    }

    public function testStripsPunctuationAndSpaces(): void
    {
        // Punctuation/spaces are stripped, then casing is normalized to
        // ucfirst(strtolower()) — deterministic regardless of source casing.
        self::assertSame('SObrien', UsernameMinter::base('Sean', "O'Brien"));
        self::assertSame('MDelacruz', UsernameMinter::base('Maria', 'De La Cruz'));
        self::assertSame('MMaryjane', UsernameMinter::base('Mary', 'Mary-Jane'));
        // A period after the first initial is stripped; the first letter wins.
        self::assertSame('JSmith', UsernameMinter::base('J.', 'Smith'));
    }

    public function testEmptyOrAllStrippedNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsernameMinter::base('John', "'''");
    }

    public function testEmptyFirstNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsernameMinter::base('', 'Smith');
    }

    public function testFirstHolderGetsBareBase(): void
    {
        self::assertSame('JSmith', UsernameMinter::mint('John', 'Smith', self::free()));
    }

    public function testCollisionIncrementsFromOne(): void
    {
        self::assertSame('JSmith1', UsernameMinter::mint('James', 'Smith', self::taken('JSmith')));
        self::assertSame('JSmith2', UsernameMinter::mint('Jane', 'Smith', self::taken('JSmith', 'JSmith1')));
    }

    public function testCollisionCheckIsCaseInsensitiveViaCaller(): void
    {
        // The caller's $isTaken folds case (as the DB/AD checks do).
        self::assertSame('JSmith1', UsernameMinter::mint('John', 'Smith', self::taken('jsmith')));
    }

    public function testLengthCapTruncatesLastNameKeepingInitial(): void
    {
        $u = UsernameMinter::mint('John', 'Superlonglastnamehere', self::free());
        self::assertSame(UsernameMinter::SAM_MAX_LENGTH, strlen($u));
        self::assertSame('JSuperlonglastnamehe', $u); // J + 19 chars = 20
    }

    public function testLengthCapPreservesSuffixByTruncatingMore(): void
    {
        // The base form is taken, so the suffix must survive at the cap: the
        // last-name portion loses a char to make room for the '1'.
        $u = UsernameMinter::mint('John', 'Superlonglastnamehere', self::taken('JSuperlonglastnamehe'));
        self::assertSame(UsernameMinter::SAM_MAX_LENGTH, strlen($u));
        self::assertSame('1', substr($u, -1));
        self::assertSame('JSuperlonglastnameh1', $u); // J + 18 chars + "1" = 20
    }

    public function testEmailAndUpnDerivation(): void
    {
        self::assertSame('JSmith@tusc.k12.al.us', UsernameMinter::emailFor('JSmith', 'tusc.k12.al.us'));
        // Casing of the username is preserved (email = upn = <username>@domain).
        self::assertSame('JSmith1@tusc.k12.al.us', UsernameMinter::emailFor('JSmith1', 'tusc.k12.al.us'));
    }
}
