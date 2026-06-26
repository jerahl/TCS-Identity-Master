<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Import\Importer;
use App\Import\ImportSource;
use App\Http\Upload;
use App\Service\ImportService;
use App\Support\Csrf;

/**
 * Import / feed status: batch history with a drill-in to a batch's staged rows
 * and how each matched, plus manual upload-and-import (editor+).
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
            'sources' => ImportSource::all(),
            'csrf'    => Csrf::token(),
        ], 'import', 'Configuration  /  Import & feeds', 'Import / feeds — TCS Identity Master');
    }

    /** Upload a NextGen/PowerSchool CSV and run the importer on it. */
    public function upload(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/import'));
        }

        $system = (string) ($_POST['system'] ?? '');
        if (!ImportSource::exists($system)) {
            $this->flash('Choose a valid source system.');
            return $this->redirect(url('/import'));
        }
        $dryRun = !empty($_POST['dry_run']);

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            $this->flash('No file was uploaded.');
            return $this->redirect(url('/import'));
        }

        $maxBytes = (int) Config::get('UPLOAD_MAX_BYTES', '20971520');
        $error = Upload::validateMeta((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE), (string) ($file['name'] ?? ''), (int) ($file['size'] ?? 0), $maxBytes);
        if ($error !== null) {
            $this->flash($error);
            return $this->redirect(url('/import'));
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            $this->flash('Upload validation failed.');
            return $this->redirect(url('/import'));
        }

        $original = Upload::sanitizeName((string) $file['name']);
        try {
            $result = (new Importer())->run($system, (string) $file['tmp_name'], null, $dryRun, $this->currentUser()['name'], $original);
        } catch (\Throwable $e) {
            error_log('[idm] upload import: ' . $e->getMessage());
            $this->flash('Import failed: ' . $e->getMessage());
            return $this->redirect(url('/import'));
        }

        $c = $result['counts'];
        $summary = sprintf('%s: %d rows · auto %d · new %d · review %d · skipped %d · errors %d',
            $dryRun ? 'Dry run' : 'Imported', $c['total'], $c['auto_match'], $c['new'], $c['needs_review'], $c['skipped'], $c['errors']);
        $this->flash($summary);

        if (!$dryRun && $result['batch_id']) {
            return $this->redirect(url('/import', ['batch' => $result['batch_id']]));
        }
        return $this->redirect(url('/import'));
    }
}
