<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns the last direct Google Workspace sync run (a service_run row, job='google')
 * into a view model for the Outputs-page summary: an overall highlight line plus a
 * breakdown of what the sync did (created / name pushed / suspended / moved OU /
 * licensed / unlicensed) and what needs attention (errors + license-blocked).
 *
 * Pure — takes the row, reads its counts_json (written by bin/sync_google.php),
 * and never touches the DB — so it's trivially unit-testable. Mirrors
 * AdaxesSyncSummary; the Google run records a flat counts map (no per-phase
 * breakdown), so this presents a single ordered list of non-zero counts.
 */
final class GoogleSyncSummary
{
    /** The order counts are shown; 'eligible' is surfaced separately as a headline. */
    private const COUNT_ORDER = [
        'created', 'pushed', 'suspended', 'moved', 'licensed', 'unlicensed',
        'license_blocked', 'in_sync', 'manual_override', 'no_email', 'no_account', 'errors',
    ];

    private const COUNT_LABELS = [
        'created'         => 'Created',
        'pushed'          => 'Name pushed',
        'suspended'       => 'Suspended',
        'moved'           => 'Moved OU',
        'licensed'        => 'Licensed',
        'unlicensed'      => 'Unlicensed',
        'license_blocked' => 'License blocked (no seat)',
        'in_sync'         => 'Already in sync',
        'manual_override' => 'Manual override (skipped)',
        'no_email'        => 'No golden email',
        'no_account'      => 'No account',
        'errors'          => 'Errors',
    ];

    /** Counts that represent a change actually applied this run. */
    private const ACTION_KEYS = ['created', 'pushed', 'suspended', 'moved', 'licensed', 'unlicensed'];

    /**
     * @param array<string,mixed>|null $row a service_run row (ServiceRunLog::last('google'))
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

        $errors        = (int) ($counts['errors'] ?? 0);
        $licenseBlocked = (int) ($counts['license_blocked'] ?? 0);

        $actions = 0;
        foreach (self::ACTION_KEYS as $k) {
            $actions += (int) ($counts[$k] ?? 0);
        }

        $cells = [];
        foreach (self::COUNT_ORDER as $ck) {
            $v = (int) ($counts[$ck] ?? 0);
            if ($v === 0) {
                continue;
            }
            $cells[] = ['label' => self::COUNT_LABELS[$ck] ?? ucfirst($ck), 'key' => $ck, 'value' => $v];
        }

        return [
            'status'    => (string) ($row['status'] ?? ''),
            'when'      => str_replace('T', ' ', (string) ($row['finished_at'] ?? $row['started_at'] ?? '')),
            'origin'    => (string) ($row['origin'] ?? ''),
            'actor'     => (string) ($row['actor'] ?? ''),
            'message'   => (string) ($row['message'] ?? ''),
            'eligible'  => (int) ($counts['eligible'] ?? 0),
            'errors'    => $errors,
            'attention' => $errors + $licenseBlocked,
            'actions'   => $actions,
            'cells'     => $cells,
        ];
    }
}
