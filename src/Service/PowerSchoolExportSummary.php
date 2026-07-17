<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns the last PowerSchool staff export run (a service_run row, job='ps_export')
 * into a view model for the Outputs-page summary: what the export shipped (new /
 * changed people, total rows, schools) and what needs attention (held-back /
 * rejected exceptions, plus an upload that didn't happen).
 *
 * Pure — takes the row, reads its counts_json (written by
 * bin/export_powerschool.php), and never touches the DB — so it's trivially
 * unit-testable. Mirrors GoogleSyncSummary / AdaxesSyncSummary.
 */
final class PowerSchoolExportSummary
{
    /** The order counts are shown; 'rows' is surfaced separately as a headline. */
    private const COUNT_ORDER = ['new', 'changed', 'schools', 'exceptions'];

    private const COUNT_LABELS = [
        'new'        => 'New users',
        'changed'    => 'Changed users',
        'schools'    => 'Schools',
        'exceptions' => 'Exceptions (held back)',
    ];

    /**
     * @param array<string,mixed>|null $row a service_run row (ServiceRunLog::last('ps_export'))
     * @return array<string,mixed>|null null when the export has never run
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

        $exceptions = (int) ($counts['exceptions'] ?? 0);

        $cells = [];
        foreach (self::COUNT_ORDER as $ck) {
            $v = (int) ($counts[$ck] ?? 0);
            if ($v === 0) {
                continue;
            }
            $cells[] = ['label' => self::COUNT_LABELS[$ck] ?? ucfirst($ck), 'key' => $ck, 'value' => $v];
        }

        return [
            'status'     => (string) ($row['status'] ?? ''),
            'when'       => str_replace('T', ' ', (string) ($row['finished_at'] ?? $row['started_at'] ?? '')),
            'origin'     => (string) ($row['origin'] ?? ''),
            'actor'      => (string) ($row['actor'] ?? ''),
            'message'    => (string) ($row['message'] ?? ''),
            'rows'       => (int) ($counts['rows'] ?? 0),
            'exceptions' => $exceptions,
            'attention'  => $exceptions,
            // exported = people shipped this run (each is one or more rows).
            'exported'   => (int) ($counts['new'] ?? 0) + (int) ($counts['changed'] ?? 0),
            'uploaded'   => !empty($counts['uploaded']),
            'cells'      => $cells,
        ];
    }
}
