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
 *   2. employee_id                                      -> AUTO  (score 100)
 *   3. full name + DOB                                  -> score; AUTO if >= threshold
 *                                                          AND unambiguous, else REVIEW
 *   4. full name only (no corroborating DOB)            -> REVIEW, NEVER auto
 *   - no candidate at all                               -> NEW
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

        // Tier 2 — employee id.
        if ($row->employeeId !== null && trim($row->employeeId) !== '') {
            $pid = $lookup->findPersonIdByEmployeeId(trim($row->employeeId));
            if ($pid !== null) {
                return new MatchDecision(MatchDecision::AUTO, $pid, 100.0, 'employee_id',
                    "Match on employee id {$row->employeeId}.");
            }
        }

        // Tiers 3 + 4 — name (+ DOB) scoring.
        $rowFirst = self::norm($row->firstName);
        $rowLast = self::norm($row->lastName);

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
