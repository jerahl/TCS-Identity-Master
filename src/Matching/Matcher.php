<?php

declare(strict_types=1);

namespace App\Matching;

use App\Import\NormalizedRow;

/**
 * The person matcher — the risky, critical part of the pipeline. Pure logic: it
 * takes a normalized row and a MatchLookup and returns a MatchDecision. No SQL,
 * no side effects, fully unit-testable.
 *
 * Tiers, strongest key first (first hit wins):
 *   1. existing person_source_id (system, source_key)  -> AUTO  (exact, score 100)
 *   2. employee_id, name-corroborated                  -> AUTO if exactly one holder
 *                                                          AND the name agrees; a
 *                                                          collision (id maps to
 *                                                          >1 person, or the name
 *                                                          differs) -> REVIEW
 *   3. full name + DOB                                  -> score; AUTO if >= threshold
 *                                                          AND unambiguous, else REVIEW
 *   4. full name only (no corroborating DOB)            -> REVIEW, NEVER auto
 *   - no candidate at all                               -> NEW
 *
 * employee_id is NOT a globally unique keyspace: nextgen/powerschool share the
 * real HR number, but the sub/contractor feeds overload the column with their own
 * SubID/ContractorID, so an id can collide across two different humans. Tier 2
 * therefore corroborates by name before auto-linking — a bare id match is not
 * enough to overwrite a golden record.
 *
 * A candidate requires BOTH first and last name to match exactly — a shared last
 * name or first-initial alone is never a candidate (kills the Smith/Jones flood).
 *
 * Hard guarantee (guardrail + tested): a name-only match NEVER auto-links,
 * regardless of the configured threshold. Auto on the name tier requires a
 * DOB-confirmed ('name+dob') basis.
 *
 * Scoring (name tier), deterministic:
 *   base 75  : first AND last name both match exactly
 *   +25      : DOB present on both sides AND equal
 *   -40      : DOB present on both sides AND different   (likely a different human)
 *   So a full name + DOB match = 100; a name-only match (DOB missing on a side)
 *   = 75 but basis stays 'name_only'; a name match with conflicting DOB = 35.
 */
final class Matcher
{
    public function __construct(private readonly float $autoThreshold = 90.0)
    {
    }

    public function match(NormalizedRow $row, MatchLookup $lookup): MatchDecision
    {
        if (!$row->isMatchable()) {
            return new MatchDecision(
                MatchDecision::SKIPPED, null, 0.0, 'none',
                'Missing required field (source key, first or last name).'
            );
        }

        // Tier 1 — existing source id (exact).
        $pid = $lookup->findPersonIdBySourceId($row->sourceSystem(), $row->sourceKey);
        if ($pid !== null) {
            return new MatchDecision(MatchDecision::AUTO, $pid, 100.0, 'source_id',
                "Exact match on existing {$row->sourceSystem()} source id {$row->sourceKey}.");
        }

        // Tiers 3 + 4 — name (+ DOB) scoring.
        $rowFirst = self::norm($row->firstName);
        $rowLast = self::norm($row->lastName);

        // Tier 2 — employee id, WITH name corroboration. employee_id is not a
        // globally unique keyspace: nextgen/powerschool share the real HR number
        // (same person across HR systems), but the sub/contractor feeds overload
        // the column with their own SubID/ContractorID. So a raw id match can be a
        // collision (a sub's SubID numerically equal to a teacher's employee
        // number) or a typo pointing at someone else — and auto-linking would
        // rename a real person's golden record. Auto-link ONLY when exactly one
        // person carries the id AND their name agrees; otherwise route to review.
        if ($row->employeeId !== null && trim($row->employeeId) !== '') {
            $empMatches = $lookup->findPersonsByEmployeeId(trim($row->employeeId));
            if (count($empMatches) === 1
                && self::norm($empMatches[0]['first_name']) === $rowFirst
                && self::norm($empMatches[0]['last_name']) === $rowLast
                && $rowFirst !== '' && $rowLast !== ''
            ) {
                return new MatchDecision(MatchDecision::AUTO, (int) $empMatches[0]['person_id'], 100.0, 'employee_id',
                    "Match on employee id {$row->employeeId} (name corroborated).");
            }
            if ($empMatches !== []) {
                // Id matched but the name disagrees, or the id maps to more than
                // one person — a likely collision/typo. Surface for human review
                // instead of silently merging two different people.
                $candidates = array_map(
                    static fn(array $m) => ['person_id' => (int) $m['person_id'], 'score' => 60.0, 'basis' => 'employee_id_conflict'],
                    $empMatches
                );
                $reason = count($empMatches) > 1
                    ? sprintf('employee id %s maps to %d people — ambiguous, needs review.', $row->employeeId, count($empMatches))
                    : sprintf('employee id %s matched but the name differs — possible id collision, needs review.', $row->employeeId);
                return new MatchDecision(MatchDecision::REVIEW, null, 60.0, 'employee_id_conflict', $reason, $candidates);
            }
        }

        $scored = [];
        foreach ($lookup->findByLastName($row->lastName) as $cand) {
            $cFirst = self::norm($cand['first_name']);
            $cLast = self::norm($cand['last_name']);
            // BOTH first and last name must match exactly (after normalization).
            // A shared last name alone — or a mere first-initial match — is NOT a
            // candidate: the district has many Smiths and Joneses, and an initial
            // match flooded the review queue with people who aren't the same human.
            if ($cLast === '' || $cLast !== $rowLast) {
                continue;
            }
            if ($cFirst === '' || $rowFirst === '' || $cFirst !== $rowFirst) {
                continue;
            }

            $dobBoth = $row->dob !== null && $row->dob !== '' && !empty($cand['dob']);
            $dobEqual = $dobBoth && $row->dob === $cand['dob'];

            // Full-name match = 75; DOB confirmation lifts it to 100; a conflicting
            // DOB drops it to 35 (strong evidence of a different person).
            $score = 75.0 + ($dobEqual ? 25.0 : ($dobBoth ? -40.0 : 0.0));

            $basis = $dobEqual ? 'name+dob' : 'name_only';
            $scored[] = ['person_id' => (int) $cand['person_id'], 'score' => $score, 'basis' => $basis];
        }

        if ($scored === []) {
            return new MatchDecision(MatchDecision::NEW, null, 0.0, 'none',
                'No existing person matched — will create a new pending record.');
        }

        // Sort candidates strongest first.
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

        $dobConfirmed = array_values(array_filter($scored, static fn($c) => $c['basis'] === 'name+dob'));

        // AUTO is only possible on a DOB-confirmed basis, above threshold, and
        // unambiguous (a single clear top candidate). Name-only never gets here.
        if ($dobConfirmed !== []) {
            $top = $dobConfirmed[0];
            $tie = count(array_filter($dobConfirmed, static fn($c) => $c['score'] === $top['score'])) > 1;
            if ($top['score'] >= $this->autoThreshold && !$tie) {
                return new MatchDecision(MatchDecision::AUTO, $top['person_id'], $top['score'], 'name+dob',
                    sprintf('name + DOB match, score %.0f ≥ threshold %.0f.', $top['score'], $this->autoThreshold));
            }
        }

        // Otherwise human review. Basis reflects the strongest evidence present.
        $basis = $dobConfirmed !== [] ? 'name+dob' : 'name_only';
        $reason = $basis === 'name+dob'
            ? sprintf('name + DOB match below threshold or ambiguous (%d candidate(s)).', count($scored))
            : sprintf('name-only match (%d candidate(s)) — never auto-linked.', count($scored));

        return new MatchDecision(MatchDecision::REVIEW, null, $scored[0]['score'], $basis, $reason, $scored);
    }

    /** Normalize a name for comparison: lowercase, strip non-alphanumerics, collapse spaces. */
    public static function norm(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }
}
