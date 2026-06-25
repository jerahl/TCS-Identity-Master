<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ImportService;

/**
 * Import / feed status: batch history with a drill-in to a batch's staged rows
 * and how each matched.
 */
final class ImportController extends Controller
{
    private ImportService $imports;

    public function __construct(?ImportService $imports = null)
    {
        parent::__construct();
        $this->imports = $imports ?? new ImportService();
    }

    public function index(): string
    {
        $batchId = isset($_GET['batch']) ? (int) $_GET['batch'] : 0;
        $batch = $batchId > 0 ? $this->imports->batch($batchId) : null;
        $staged = $batch !== null ? $this->imports->stagedRows($batchId) : [];

        return $this->render('import/index', [
            'batches' => $batch === null ? $this->imports->batches() : [],
            'batch'   => $batch,
            'staged'  => $staged,
        ], 'import', 'Configuration  /  Import & feeds', 'Import / feeds — TCS Identity Master');
    }
}
