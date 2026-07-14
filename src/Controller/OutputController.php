<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdaxesRunService;
use App\Service\AdaxesSyncSummary;
use App\Service\AuditService;
use App\Service\GoogleRunService;
use App\Service\GoogleSyncSummary;
use App\Service\GoogleWorkspaceService;
use App\Service\ServiceRunLog;
use App\Service\ServiceStatusService;
use App\Support\Csrf;
use App\Sync\GoogleSync;

/**
 * "Outputs" page: the status of every sync that pushes the golden record OUT to a
 * destination system — Active Directory (via Adaxes) and Google Workspace directly.
 * One place to see when each output sync last ran, what it changed (per-phase for
 * AD, per-count for Google), what needs attention, and — for the right role — to
 * run each on demand.
 *
 * Reads are read-only aggregation (ServiceStatusService + ServiceRunLog +
 * the two *SyncSummary view models). The two run actions fire the background
 * systemd oneshots (a live remote lookup per person is far too slow to run
 * in-request); each CLI records its own service_run row, which this page reads.
 * The AD run is admin-gated, the Google run editor-gated, both CSRF-protected.
 */
final class OutputController extends Controller
{
    private ServiceStatusService $status;
    private ServiceRunLog $runs;
    private AuditService $audit;
    private AdaxesRunService $adaxesRun;
    private GoogleRunService $googleRun;

    public function __construct(
        ?ServiceStatusService $status = null,
        ?ServiceRunLog $runs = null,
        ?AuditService $audit = null,
        ?AdaxesRunService $adaxesRun = null,
        ?GoogleRunService $googleRun = null
    ) {
        parent::__construct();
        $this->status = $status ?? new ServiceStatusService();
        $this->runs = $runs ?? new ServiceRunLog();
        $this->audit = $audit ?? new AuditService();
        $this->adaxesRun = $adaxesRun ?? new AdaxesRunService();
        $this->googleRun = $googleRun ?? new GoogleRunService();
    }

    public function index(): string
    {
        return $this->render('outputs/index', [
            'outputs'          => $this->status->outputs(),
            'adaxesSummary'    => AdaxesSyncSummary::fromRun($this->runs->last('adaxes')),
            'canRunAdaxes'     => $this->adaxesRun->enabled(),
            'adaxesRunning'    => ($this->runs->last('adaxes')['status'] ?? '') === 'running',
            'googleSummary'    => GoogleSyncSummary::fromRun($this->runs->last('google')),
            'googleReady'      => (new GoogleWorkspaceService())->configured(),
            'googleRunEnabled' => $this->googleRun->enabled(),
            'googleRunning'    => ($this->runs->last('google')['status'] ?? '') === 'running',
            'csrf'             => Csrf::token(),
        ], 'outputs', 'Configuration  /  Outputs', 'Outputs — TCS Identity Master');
    }

    /**
     * Fire the Adaxes AD reconciler in the background (admin). A live AD lookup per
     * person can take minutes, so this asks systemd to start the oneshot unit and
     * returns at once; the CLI records its own service_run row, shown in the AD
     * summary. Config-gated on ADAXES_RUN_ENABLED (+ the sudoers rule).
     */
    public function runAdaxes(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/outputs'));
        }
        if (!$this->adaxesRun->enabled()) {
            $this->flash('On-demand AD sync is disabled — set ADAXES_RUN_ENABLED=true (and grant the sudoers rule in deploy/idm-adaxes-run.sudoers).');
            return $this->redirect(url('/outputs'));
        }
        if (($this->runs->last('adaxes')['status'] ?? '') === 'running') {
            $this->flash('An AD sync is already running — wait for it to finish before starting another.');
            return $this->redirect(url('/outputs'));
        }

        $actor = $this->currentUser()['name'];
        $res = $this->adaxesRun->start();
        if ($res['ok']) {
            $this->auditRun('adaxes', 'started', 'manual start via ' . $this->adaxesRun->unit(), $actor);
            $this->flash('AD sync started in the background. Refresh in a minute or two for the summary.');
        } else {
            $this->auditRun('adaxes', 'failed', (string) $res['error'], $actor);
            $this->flash('Could not start the AD sync: ' . $res['error']);
        }
        return $this->redirect(url('/outputs'));
    }

    /**
     * Reconcile the golden record to Google Workspace directly, bypassing OneSync
     * (editor+). Fires the background systemd oneshot (idm-google-sync.service) and
     * returns at once — a live Google lookup per person can take minutes, so running
     * it in-request ties up a PHP-FPM worker and exhausts pm.max_children (nginx 504).
     * The CLI records its own service_run row, shown in the Google summary; a dry-run
     * preview is a CLI operation (php bin/sync_google.php --dry-run). Config-gated on
     * GOOGLE_RUN_ENABLED (+ the sudoers rule in deploy/idm-google-run.sudoers).
     */
    public function runGoogle(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/outputs'));
        }
        if (!(new GoogleSync())->configured()) {
            $this->flash('Direct Google provisioning is off (set GOOGLE_DIRECT_ENABLED=true plus the GOOGLE_SA_* service-account credentials and GOOGLE_ADMIN_SUBJECT).');
            return $this->redirect(url('/outputs'));
        }
        if (!$this->googleRun->enabled()) {
            $this->flash('On-demand Google sync is disabled — set GOOGLE_RUN_ENABLED=true (and grant the sudoers rule in deploy/idm-google-run.sudoers). A dry-run preview is available from the CLI: php bin/sync_google.php --dry-run.');
            return $this->redirect(url('/outputs'));
        }
        if (($this->runs->last('google')['status'] ?? '') === 'running') {
            $this->flash('A Google sync is already running — wait for it to finish before starting another.');
            return $this->redirect(url('/outputs'));
        }

        $actor = $this->currentUser()['name'];
        $res = $this->googleRun->start();
        if ($res['ok']) {
            $this->auditRun('google', 'started', 'manual start via ' . $this->googleRun->unit(), $actor);
            $this->flash('Google sync started in the background. Refresh in a minute or two for the summary.');
        } else {
            error_log('[idm] google run: ' . (string) $res['error']);
            $this->auditRun('google', 'failed', (string) $res['error'], $actor);
            $this->flash('Could not start the Google sync: ' . $res['error']);
        }
        return $this->redirect(url('/outputs'));
    }

    /** Operational action — no person/entity row, so log under 'config'. */
    private function auditRun(string $job, string $result, string $detail, string $actor): void
    {
        try {
            $this->audit->log('config', null, 'update', null, [
                'action' => 'service-run',
                'job'    => $job,
                'result' => $result,
                'detail' => $detail,
            ], $actor);
        } catch (\Throwable $e) {
            error_log('[idm] output audit: ' . $e->getMessage());
        }
    }
}
