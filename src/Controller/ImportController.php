<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Import\Importer;
use App\Import\ImportSource;
use App\Http\Upload;
use App\Service\GoogleWorkspaceService;
use App\Service\ImportService;
use App\Support\Csrf;
use App\Sync\FeedSync;
use App\Sync\GoogleSync;

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
            'sources' => ImportSource::webUploadable(),
            'sftpSources' => FeedSync::configuredSources(),
            'psOdbc'  => FeedSync::powerSchoolOdbcEnabled(),
            'googleReady' => (new GoogleWorkspaceService())->configured(),
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
        if (!ImportSource::allowsWebUpload($system)) {
            $this->flash('NextGen imports from the SFTP feed and PowerSchool reads directly from Oracle (ODBC); single-file web upload is disabled for them.');
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

        // Dry run: render the per-row preview of what an import WOULD change
        // (nothing was written) instead of redirecting to a non-existent batch.
        if ($dryRun) {
            return $this->render('import/dryrun', [
                'source'   => ImportSource::for($system),
                'fileName' => $original,
                'counts'   => $c,
                'outcomes' => $result['outcomes'],
            ], 'import', 'Configuration  /  Import & feeds', 'Dry run — TCS Identity Master');
        }

        $summary = sprintf('Imported: %d rows · auto %d · new %d · review %d · skipped %d · errors %d',
            $c['total'], $c['auto_match'], $c['new'], $c['needs_review'], $c['skipped'], $c['errors']);
        $this->flash($summary);

        if ($result['batch_id']) {
            return $this->redirect(url('/import', ['batch' => $result['batch_id']]));
        }
        return $this->redirect(url('/import'));
    }

    /** Pull new feed files from SFTP and import PowerSchool from Oracle (editor+). */
    public function fetch(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/import'));
        }
        $sources = FeedSync::configuredSources();
        $psOdbc = FeedSync::powerSchoolOdbcEnabled();
        if ($sources === [] && !$psOdbc) {
            $this->flash('No feeds configured (set SFTP_HOST + SFTP_<source>_DIR, and/or PS_ODBC_* for PowerSchool).');
            return $this->redirect(url('/import'));
        }
        $actor = $this->currentUser()['name'];
        $downloaded = 0;
        $imported = 0;
        $errors = 0;
        try {
            if ($sources !== []) {
                $t = FeedSync::fromConfig()->run($sources, false, true, $actor)['totals'];
                $downloaded += $t['downloaded'];
                $imported += $t['imported'];
                $errors += $t['errors'];
            }
            if ($psOdbc) {
                $ps = FeedSync::importPowerSchoolOdbc(false, $actor);
                if ($ps !== null) {
                    $imported += $ps['imported'];
                    $errors += $ps['errors'];
                }
            }
            $this->flash("Feed pull: downloaded {$downloaded}, imported {$imported}, errors {$errors}.");
        } catch (\Throwable $e) {
            error_log('[idm] feed fetch: ' . $e->getMessage());
            $this->flash('Feed pull failed: ' . $e->getMessage());
        }
        return $this->redirect(url('/import'));
    }

    /**
     * Reconcile the golden record to Google Workspace directly, bypassing
     * OneSync (editor+). Supports a dry-run (plan only). Config-gated on
     * GOOGLE_DIRECT_ENABLED + GOOGLE_SA_*; honors the threshold guardrail.
     */
    public function googleSync(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/import'));
        }
        $sync = new GoogleSync();
        if (!$sync->configured()) {
            $this->flash('Direct Google provisioning is off (set GOOGLE_DIRECT_ENABLED=true plus the GOOGLE_SA_* service-account credentials and GOOGLE_ADMIN_SUBJECT).');
            return $this->redirect(url('/import'));
        }
        $dryRun = !empty($_POST['dry_run']);
        try {
            $r = $sync->run($dryRun, $this->currentUser()['name']);
            $c = $r['counts'];
            if ($r['blocked']) {
                $this->flash($r['note'] ?? 'Google sync blocked by the threshold guardrail — nothing written.');
            } else {
                $this->flash(sprintf('%s: %d eligible · created %d · pushed %d · suspended %d · moved %d · in-sync %d · no-email %d · errors %d',
                    $dryRun ? 'Google sync (dry run)' : 'Google sync',
                    $c['eligible'], $c['created'], $c['pushed'], $c['suspended'], $c['moved'], $c['in_sync'], $c['no_email'], $c['errors']));
            }
        } catch (\Throwable $e) {
            error_log('[idm] google sync: ' . $e->getMessage());
            $this->flash('Google sync failed: ' . $e->getMessage());
        }
        return $this->redirect(url('/import'));
    }
}
