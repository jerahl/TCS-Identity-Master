<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Format a personal name to conventional "first letter capital" casing, so a
 * feed value like "JAMES", "smith" or "mcDONALD" is stored as "James", "Smith"
 * or "McDonald". Pure and idempotent — a value already in the target casing is
 * returned unchanged, so it is safe to run repeatedly.
 *
 * Rules (chosen to match how U.S. staff names are normally written, not a naive
 * ucwords() that would break the common exceptions):
 *   - Each whitespace-separated word is title-cased; runs of whitespace collapse
 *     to a single space and the string is trimmed.
 *   - Hyphenated and apostrophe parts are title-cased independently, so
 *     "smith-jones" -> "Smith-Jones" and "o'brien" -> "O'Brien".
 *   - A leading "Mc" keeps the following letter capital: "mcdonald" -> "McDonald".
 *   - A generational suffix written in Roman numerals is upper-cased
 *     ("iii" -> "III"); only an explicit whitelist is touched so ordinary
 *     surnames that happen to use Roman-numeral letters (e.g. "Dill") are left
 *     alone.
 *
 * Everything is multibyte-aware (UTF-8) so accented names case correctly.
 */
final class NameCase
{
    /** Generational suffixes to upper-case verbatim (exact, case-insensitive match). */
    private const ROMAN_SUFFIXES = ['II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII', 'XIII'];

    /** Format a full name (may contain several words). Returns '' for a blank input. */
    public static function format(string $name): string
    {
        // Collapse whitespace and trim; bail early on an empty value.
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return '';
        }

        $words = explode(' ', $name);
        foreach ($words as $i => $word) {
            $words[$i] = self::formatWord($word);
        }
        return implode(' ', $words);
    }

    /** Title-case one space-delimited word, handling hyphen/apostrophe compounds. */
    private static function formatWord(string $word): string
    {
        // Preserve the separators (- and ') while casing each part between them.
        $parts = preg_split('/([-\'\x{2019}])/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$word];
        foreach ($parts as $i => $part) {
            // Odd indices are the captured separators — leave them as-is.
            if ($i % 2 === 1) {
                continue;
            }
            $parts[$i] = self::formatPart($part);
        }
        return implode('', $parts);
    }

    /** Case a single alphabetic run (no separators inside). */
    private static function formatPart(string $part): string
    {
        if ($part === '') {
            return '';
        }

        // Generational suffix in Roman numerals -> upper-case (whitelist only).
        if (in_array(mb_strtoupper($part, 'UTF-8'), self::ROMAN_SUFFIXES, true)) {
            return mb_strtoupper($part, 'UTF-8');
        }

        // "Mc" prefix keeps the next letter capital: mcdonald -> McDonald.
        if (mb_strlen($part, 'UTF-8') > 2 && mb_strtolower(mb_substr($part, 0, 2, 'UTF-8'), 'UTF-8') === 'mc') {
            return 'Mc' . self::formatPart(mb_substr($part, 2, null, 'UTF-8'));
        }

        return self::ucfirstMb($part);
    }

    /** Upper-case the first character, lower-case the rest (UTF-8 safe). */
    private static function ucfirstMb(string $s): string
    {
        $first = mb_substr($s, 0, 1, 'UTF-8');
        $rest = mb_substr($s, 1, null, 'UTF-8');
        return mb_strtoupper($first, 'UTF-8') . mb_strtolower($rest, 'UTF-8');
    }
}
