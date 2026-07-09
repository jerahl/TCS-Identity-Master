<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Mints a brand-new AD username (sAMAccountName) — and the derived email / UPN —
 * for a person who has none, implementing the identity policy in
 * docs/adaxes-provisioning-design.md. In Phase 3 IDM becomes the username
 * authority (retiring OneSync's minter for AD); this is that authority.
 *
 * Pure logic, no I/O: the collision check is injected as a callable so this
 * unit-tests without a DB or a live directory (the same pattern Matcher and
 * WritebackImporter::decide() use). The caller wires $isTaken to the real
 * collision domain — person.username, any locked username, and a live AD search.
 *
 * Policy (see the design doc for the rationale):
 *  - Format: first-name initial + last name, lowercase — John Smith → jsmith.
 *  - Uses the LEGAL first name, never preferred_name (AD convention).
 *  - Strips everything except [A-Za-z] from each part before assembling
 *    (O'Brien → obrien, De La Cruz → delacruz, Mary-Jane → maryjane).
 *  - Deterministic casing: all lowercase, regardless of source casing.
 *  - Collisions append an integer starting at 1 to the base (jsmith, jsmith1, …).
 *  - sAMAccountName caps at 20 chars: the last-name portion is truncated so the
 *    initial and the numeric suffix always survive.
 *  - An empty/all-stripped first or last name is a data error → no mint (throws;
 *    the caller routes the person to review).
 */
final class UsernameMinter
{
    /** sAMAccountName length limit in Active Directory. */
    public const SAM_MAX_LENGTH = 20;

    /**
     * Deterministic base form, pre-collision: "jsmith" (lowercase). Throws when
     * the name can't produce a valid base (empty after stripping non-letters) so
     * the caller routes the person to review rather than minting junk.
     */
    public static function base(string $firstName, string $lastName): string
    {
        $first = self::lettersOnly($firstName);
        $last  = self::lettersOnly($lastName);
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException(
                'Cannot mint a username: first and last name must each contain at least one letter '
                . '(given first=' . var_export($firstName, true) . ', last=' . var_export($lastName, true) . ').'
            );
        }
        return strtolower(substr($first, 0, 1) . $last);
    }

    /**
     * The lowest free candidate for this person, honoring collisions and the
     * 20-char cap. $isTaken($candidate) returns true when the candidate collides
     * anywhere in the collision domain (DB or live AD); the caller wires it to
     * both. The first holder gets the bare base; each subsequent collision gets
     * the next free integer suffix (1, 2, 3, …).
     *
     * @param callable(string):bool $isTaken
     */
    public static function mint(string $firstName, string $lastName, callable $isTaken): string
    {
        $base = self::base($firstName, $lastName);
        // The initial is always one letter; the rest is the (truncatable) last name.
        $initial  = substr($base, 0, 1);
        $lastPart = substr($base, 1);

        for ($n = 0; $n <= self::MAX_COLLISION_TRIES; $n++) {
            $candidate = self::assemble($initial, $lastPart, $n);
            if (!$isTaken($candidate)) {
                return $candidate;
            }
        }
        throw new InvalidArgumentException(
            'Could not find a free username for base "' . $base . '" within '
            . self::MAX_COLLISION_TRIES . ' collision attempts.'
        );
    }

    /** email = upn = <username>@<domain>, preserving the username's cased form. */
    public static function emailFor(string $username, string $domain): string
    {
        return trim($username) . '@' . trim($domain);
    }

    // ---- internals ----------------------------------------------------------

    /**
     * A backstop so a pathological $isTaken (always true) can't spin forever. Far
     * above any real collision count — hundreds of identically-named staff is not
     * a thing, and hitting this signals a bug or a broken collision check.
     */
    private const MAX_COLLISION_TRIES = 9999;

    /**
     * Assemble the nth candidate: initial + last-name portion + numeric suffix,
     * truncating the last-name portion (never the initial or the suffix) so the
     * whole never exceeds SAM_MAX_LENGTH. n=0 is the bare base (no suffix).
     */
    private static function assemble(string $initial, string $lastPart, int $n): string
    {
        $suffix = $n === 0 ? '' : (string) $n;
        $maxLast = self::SAM_MAX_LENGTH - strlen($initial) - strlen($suffix);
        if ($maxLast < 0) {
            $maxLast = 0; // absurd, but never emit a negative-length substring
        }
        return $initial . substr($lastPart, 0, $maxLast) . $suffix;
    }

    /** Strip everything except ASCII letters. */
    private static function lettersOnly(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z]/', '', $value);
    }
}
