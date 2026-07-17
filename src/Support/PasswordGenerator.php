<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generate a temporary password for a newly created account that is secure yet
 * human-readable and easy to type / read aloud — the value is handed to a person
 * (help desk reads it to a new hire, or it's printed on a first-logon slip), so a
 * random symbol soup is the wrong shape. Instead it is a short passphrase:
 *
 *     two Title-cased words from a curated bank
 *   + a few (2–4) random alphanumeric characters (always ≥1 digit, ≥1 letter)
 *   + one special character
 *
 *   e.g.  MapleOtter7q3@   RiverFalcon84k!   CedarHarbor6m#
 *
 * Used when IDM sets the initial AD password itself (the Adaxes Business Rules
 * OneSync relied on do NOT fire on REST API events, so the reconciler sets the
 * password + "must change at next logon" directly after a create). The value is
 * handed to Adaxes once and stored encrypted on the golden record; the user
 * changes it at first logon.
 *
 * Complexity: the Title-cased words supply an uppercase and a lowercase letter,
 * the alnum run guarantees a digit, and the suffix supplies a symbol — so every
 * result satisfies AD's default 3-of-5 complexity policy (in fact all four of
 * upper/lower/digit/symbol). The random characters draw from unambiguous
 * alphabets (no 0/O, 1/l/I) so a temp password read off a screen isn't mistyped;
 * word boundaries stay visible because each word is Title-cased. Everything
 * random comes from a CSPRNG (random_int).
 */
final class PasswordGenerator
{
    // Random-character alphabets, ambiguous glyphs removed (no 0/O, 1/l/I) so a
    // value read aloud or off a screen isn't mistyped.
    private const LOWER  = 'abcdefghijkmnopqrstuvwxyz'; // no l
    private const DIGIT  = '23456789';                  // no 0, 1
    // Special characters chosen to be easy to name ("bang", "at", "hash", …) and
    // present on every keyboard; nothing shell/CSV-hostile like quotes or commas.
    private const SYMBOL = '!@#$%^&*?+=';

    /**
     * A curated bank of short, common, easy-to-spell words — unique, lowercase
     * (Title-cased at assembly). No offensive terms, no homophone traps, nothing
     * shorter than 3 or longer than 7 letters — the point is that a person can
     * hear it, remember it for the length of a hallway, and type it once. ~180
     * words ≈ 7.5 bits each; two distinct words plus the random suffix put a
     * value comfortably past AD's complexity floor for a must-change temp.
     *
     * @var list<string>
     */
    private const WORDS = [
        // animals
        'otter', 'falcon', 'badger', 'beaver', 'bison', 'cobra', 'coyote', 'dolphin',
        'eagle', 'ferret', 'gecko', 'heron', 'iguana', 'jaguar', 'kestrel', 'lemur',
        'lynx', 'marten', 'moose', 'narwhal', 'ocelot', 'osprey', 'panda', 'puffin',
        'quail', 'rabbit', 'raccoon', 'raven', 'salmon', 'sparrow', 'stork', 'tiger',
        'turtle', 'walrus', 'weasel', 'wombat', 'zebra', 'bobcat', 'condor', 'egret',
        'finch', 'gopher', 'hawk', 'jackal', 'koala', 'llama', 'mantis', 'newt',
        // trees & plants
        'maple', 'cedar', 'birch', 'willow', 'aspen', 'spruce', 'poplar', 'alder',
        'hazel', 'laurel', 'linden', 'sequoia', 'juniper', 'cypress', 'dogwood', 'fern',
        'ivy', 'clover', 'thistle', 'violet', 'dahlia', 'zinnia', 'lupine', 'aster',
        // nature & geography
        'river', 'harbor', 'canyon', 'meadow', 'summit', 'ridge', 'valley', 'delta',
        'glacier', 'lagoon', 'prairie', 'tundra', 'mesa', 'fjord', 'island', 'reef',
        'cavern', 'geyser', 'marsh', 'oasis', 'plateau', 'basin', 'dune', 'grotto',
        // weather & sky
        'comet', 'meteor', 'nebula', 'aurora', 'zenith', 'eclipse', 'thunder', 'breeze',
        'frost', 'cinder', 'ember', 'monsoon', 'cyclone', 'tempest', 'drizzle', 'rainbow',
        // gems, metals & colors
        'amber', 'garnet', 'jasper', 'opal', 'quartz', 'topaz', 'zircon', 'onyx',
        'copper', 'cobalt', 'pewter', 'bronze', 'silver', 'crimson', 'indigo', 'scarlet',
        'auburn', 'russet', 'teal', 'maroon', 'olive', 'coral', 'khaki',
        // food you'd know
        'almond', 'walnut', 'cashew', 'pecan', 'mango', 'papaya', 'cherry', 'apricot',
        'melon', 'ginger', 'nutmeg', 'saffron', 'basil', 'pepper', 'quince',
        // objects & things
        'anchor', 'beacon', 'lantern', 'compass', 'kettle', 'satchel', 'trellis', 'bridge',
        'castle', 'cottage', 'harvest', 'marble', 'pebble', 'ribbon', 'saddle', 'tunnel',
        'wagon', 'window', 'anvil', 'chisel', 'hammer', 'ladder', 'paddle',
        // abstract & pleasant
        'ballad', 'echo', 'fable', 'jubilee', 'melody', 'prism', 'rhythm', 'signal',
        'spark', 'vector', 'whisper', 'zephyr', 'legend', 'mosaic',
    ];

    /**
     * A readable temp passphrase: $words Title-cased words + a run of $alnum
     * random alphanumeric characters (2–4 when not given, always containing at
     * least one digit and one letter) + one special character.
     *
     * Defaults (2 words, a random 2–4 alnum run, 1 symbol) match what the AD
     * create path wants; the parameters exist mainly for testing and future
     * callers that need a longer value.
     */
    public static function generate(int $words = 2, ?int $alnum = null): string
    {
        $words = max(1, $words);
        $alnumCount = $alnum ?? random_int(2, 4);
        $alnumCount = max(2, $alnumCount); // keep room for the guaranteed digit + letter

        // Distinct words so a value is never "RiverRiver…"; Title-cased so the
        // word boundaries stay visible even without a separator.
        $out = '';
        foreach (self::pickWords($words) as $w) {
            $out .= ucfirst($w);
        }

        return $out . self::alnumRun($alnumCount) . self::pick(self::SYMBOL);
    }

    /**
     * $count distinct words drawn uniformly (CSPRNG) from the bank. Falls back to
     * allowing repeats only if more words are requested than the bank holds.
     *
     * @return list<string>
     */
    private static function pickWords(int $count): array
    {
        $bank = self::WORDS;
        $n = count($bank);

        if ($count >= $n) {
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $out[] = $bank[random_int(0, $n - 1)];
            }
            return $out;
        }

        $picked = [];
        $out = [];
        while (count($out) < $count) {
            $i = random_int(0, $n - 1);
            if (isset($picked[$i])) {
                continue;
            }
            $picked[$i] = true;
            $out[] = $bank[$i];
        }
        return $out;
    }

    /**
     * A run of $count alphanumeric characters (lowercase letters + digits, no
     * ambiguous glyphs) guaranteed to contain at least one digit and at least one
     * letter. $count is assumed ≥ 2 (see generate()).
     *
     * The run always LEADS with the digit: it gives a clean visual boundary
     * between the word part and the suffix ("MapleOtter7q3@" reads as a name then
     * a number), which is exactly the human-readability we're after, and it means
     * the digit-and-letter guarantee holds without shuffling word/run characters
     * together. The remaining characters are random and order-shuffled.
     */
    private static function alnumRun(int $count): string
    {
        $pool = self::LOWER . self::DIGIT;
        $lead = self::pick(self::DIGIT);          // guaranteed digit + boundary
        $rest = [self::pick(self::LOWER)];        // guaranteed letter
        for ($i = count($rest); $i < $count - 1; $i++) {
            $rest[] = self::pick($pool);
        }
        // Shuffle the remainder so the guaranteed letter isn't always first.
        for ($i = count($rest) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$rest[$i], $rest[$j]] = [$rest[$j], $rest[$i]];
        }
        return $lead . implode('', $rest);
    }

    /** One uniformly-random character from $alphabet using a CSPRNG. */
    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
