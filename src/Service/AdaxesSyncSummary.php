<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns the last Adaxes reconciler run (a service_run row, job='adaxes') into a
 * view model for the Outputs-page summary: an overall highlight line plus a
 * per-phase breakdown of what the sync did (created / correlated / edited /
 * expired / group adds+removes) and what needs attention (errors + review).
 *
 * Pure — takes the row, reads its counts_json (written by bin/adaxes_sync.php),
 * and never touches the DB — so it's trivially unit-testable.
 */
final class AdaxesSyncSummary
{
    private const PHASE_LABELS = [
        'disable' => 'Disable — expire leavers',
        'edit'    => 'Edit — attributes & OU',
        'create'  => 'Create & correlate',
        'groups'  => 'Group membership',
    ];

    /** The order counts are shown within a phase. */
    private const COUNT_ORDER = [
        'applied', 'created', 'correlated', 'rehired', 'archived', 'unarchived',
        'added', 'removed',
        'review', 'capped', 'blocked', 'candidates', 'noop', 'skipped', 'errors',
    ];

    /** Generic count labels; 'applied' is relabelled per phase (see labelFor). */
    private const COUNT_LABELS = [
        'created'    => 'Created',
        'correlated' => 'Correlated',
        'rehired'    => 'Rehired',
        'archived'   => 'Archived (leaver complete)',
        'unarchived' => 'Unarchived (returned)',
        'added'      => 'Groups added',
        'removed'    => 'Groups removed',
        'review'     => 'Needs review',
        'capped'     => 'Capped',
        'blocked'    => 'Blocked',
        'candidates' => 'Candidates',
        'noop'       => 'No change',
        'skipped'    => 'Skipped',
        'errors'     => 'Errors',
    ];

    /** What "applied" means in each phase. */
    private const APPLIED_LABELS = [
        'disable' => 'Expired',
        'edit'    => 'Edited',
        'create'  => 'Created',
        'groups'  => 'Synced',
    ];

    /**
     * @param array<string,mixed>|null $row a service_run row (ServiceRunLog::last('adaxes'))
     * @return array<string,mixed>|null null when the sync has never run
     */
    public static function fromRun(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $counts = [];
        $cj = $row['counts_json'] ?? null;
        if (is_string($cj) && trim($cj) !== '') {
            $decoded = json_decode($cj, true);
            if (is_array($decoded)) {
                $counts = $decoded;
            }
        }
        $phasesRaw = is_array($counts['phases'] ?? null) ? $counts['phases'] : [];

        $errors    = (int) ($counts['errors'] ?? 0);
        $attention = $errors;
        $actions   = 0;
        $phases    = [];

        foreach (['disable', 'edit', 'create', 'groups'] as $key) {
            if (!isset($phasesRaw[$key]) || !is_array($phasesRaw[$key])) {
                continue;
            }
            $pc = $phasesRaw[$key];
            $cells = [];
            foreach (self::COUNT_ORDER as $ck) {
                $v = (int) ($pc[$ck] ?? 0);
                if ($v === 0) {
                    continue;
                }
                $cells[] = ['label' => self::labelFor($key, $ck), 'key' => $ck, 'value' => $v];
            }
            $attention += (int) ($pc['review'] ?? 0);
            foreach (['applied', 'created', 'correlated', 'rehired', 'added', 'removed'] as $a) {
                $actions += (int) ($pc[$a] ?? 0);
            }
            $phases[] = [
                'key'     => $key,
                'label'   => self::PHASE_LABELS[$key] ?? $key,
                'cells'   => $cells,
                'errors'  => (int) ($pc['errors'] ?? 0),
                'blocked' => !empty($pc['blocked']),
            ];
        }

        return [
            'status'       => (string) ($row['status'] ?? ''),
            'when'         => str_replace('T', ' ', (string) ($row['finished_at'] ?? $row['started_at'] ?? '')),
            'origin'       => (string) ($row['origin'] ?? ''),
            'actor'        => (string) ($row['actor'] ?? ''),
            'message'      => (string) ($row['message'] ?? ''),
            'writeEnabled' => !empty($counts['write_enabled']),
            'errors'       => $errors,
            'attention'    => $attention,
            'actions'      => $actions,
            'phases'       => $phases,
        ];
    }

    /** Label for a phase's count key ('applied' means a different verb per phase). */
    private static function labelFor(string $phase, string $key): string
    {
        if ($key === 'applied') {
            return self::APPLIED_LABELS[$phase] ?? 'Applied';
        }
        return self::COUNT_LABELS[$key] ?? ucfirst($key);
    }
}
