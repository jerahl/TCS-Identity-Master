<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generate a strong, random temporary password for a newly created account.
 *
 * Used when IDM sets the initial AD password itself (the Adaxes Business Rules
 * that OneSync relied on do NOT fire on REST API events, so the reconciler sets
 * the password + "must change at next logon" directly after a create). The value
 * is handed to Adaxes once and stored encrypted on the golden record; the user
 * changes it at first logon.
 *
 * Guarantees at least one character from each of four classes (upper, lower,
 * digit, symbol) so the result satisfies AD's default password-complexity policy,
 * then fills the rest from the full alphabet and shuffles with a CSPRNG. Ambiguous
 * glyphs (0/O, 1/l/I) are excluded so a temp password read aloud or off a screen
 * isn't mistyped.
 */
final class PasswordGenerator
{
    private const UPPER  = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I, O
    private const LOWER  = 'abcdefghijkmnopqrstuvwxyz'; // no l
    private const DIGIT  = '23456789';                  // no 0, 1
    private const SYMBOL = '!@#$%^&*()-_=+';

    /** Minimum length we will ever emit, regardless of the requested length. */
    private const MIN_LENGTH = 16;

    public static function generate(int $length = 20): string
    {
        $length = max(self::MIN_LENGTH, $length);
        $all = self::UPPER . self::LOWER . self::DIGIT . self::SYMBOL;

        // One guaranteed character from each class (complexity), then fill.
        $chars = [
            self::pick(self::UPPER),
            self::pick(self::LOWER),
            self::pick(self::DIGIT),
            self::pick(self::SYMBOL),
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = self::pick($all);
        }

        // Fisher–Yates with random_int so the guaranteed chars aren't always first.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /** One uniformly-random character from $alphabet using a CSPRNG. */
    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
