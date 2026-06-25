<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * The matcher's verdict for one incoming row.
 *
 * action:
 *   - 'auto_match'   strong key (source_id / employee_id / name+DOB ≥ threshold);
 *                    apply to personId automatically.
 *   - 'needs_review' ambiguous (name+DOB below threshold, or name-only); create
 *                    match_candidate rows for human confirmation. NEVER auto.
 *   - 'new'          no candidate at all; create a new pending person.
 *   - 'skipped'      row not matchable (missing required fields).
 */
final class MatchDecision
{
    public const AUTO = 'auto_match';
    public const REVIEW = 'needs_review';
    public const NEW = 'new';
    public const SKIPPED = 'skipped';

    /** @param array<int,array{person_id:int,score:float,basis:string}> $candidates */
    public function __construct(
        public readonly string $action,
        public readonly ?int $personId,
        public readonly float $score,
        public readonly string $basis,
        public readonly string $reason,
        public readonly array $candidates = [],
    ) {
    }

    public function isAuto(): bool
    {
        return $this->action === self::AUTO;
    }
}
