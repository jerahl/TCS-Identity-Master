<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LoginsReportService;
use App\Support\Csrf;

/**
 * The Logins export (view + CSV) — the golden record in the columns of the manual
 * Logins spreadsheet, so onboarding staff pull new/changed employees from the IDM
 * instead of re-keying them from NextGen. Read-only; gated at 'view'.
 */
final class LoginsController extends Controller
{
    private LoginsReportService $report;

    public function __construct(?LoginsReportService $report = null)
    {
        parent::__construct();
        $this->report = $report ?? new LoginsReportService();
    }

    /** Read the report filters from the query string (shared by index + csv). */
    private function filters(): array
    {
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, LoginsReportService::STATUSES, true)) {
            $status = 'all';
        }
        return [
            'status' => $status,
            'school' => (string) ($_GET['school'] ?? 'all'),
            'from'   => (string) ($_GET['from'] ?? ''),
            'to'     => (string) ($_GET['to'] ?? ''),
            'q'      => (string) ($_GET['q'] ?? ''),
        ];
    }

    public function index(): string
    {
        $filters = $this->filters();
        $rows = $this->report->rows($filters);

        return $this->render('logins/index', [
            'rows'          => $rows,
            'columns'       => LoginsReportService::columns(),
            'filters'       => $filters,
            'schoolOptions' => $this->report->schoolOptions(),
            'csrf'          => Csrf::token(),
        ], 'logins', 'Logins export', 'Logins export — TCS Identity Master');
    }

    /**
     * The same rows as a CSV download. Streamed with fputcsv so quoting/escaping
     * is correct; Content-Disposition makes the browser save it.
     */
    public function csv(): string
    {
        $columns = LoginsReportService::columns();
        $rows = $this->report->rows($this->filters());

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, array_values($columns));
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[] = (string) ($row[$key] ?? '');
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $filename = 'logins-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        return $csv;
    }
}
