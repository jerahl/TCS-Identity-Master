<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Thrown when an incoming feed row carries a school *name* that doesn't match
 * any known school. Name-based school mapping is strict: an unmatched name
 * fails the row (surfaced as an import error) rather than silently dropping the
 * building, so the operator either fixes the feed's spelling or adds the school
 * to the reference table before re-importing.
 */
final class UnmatchedSchoolException extends RuntimeException
{
}
