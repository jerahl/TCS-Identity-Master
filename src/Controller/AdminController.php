<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Import\OneSyncResultImporter;
use App\Import\StudentImporter;
use App\Service\AuditService;
use App\Service\DashboardService;
use App\Service\ServiceRunLog;
use App\Service\ServiceStatusService;
use App\Support\Csrf;
use App\Sync\FeedSync;

/**
 * Admin "Services" page: one place to see the health of every moving part
 * (databases, OneSync source/write-back, SFTP feeds, PowerSchool ODBC, VPN),
 * the last run of each background job (feed imports, students sync, OneSync DB
 * sync), and — for admins — buttons to run those jobs on demand.
 *
 * Reads are read-only aggregation (ServiceStatusService + DashboardService +
 * ServiceRunLog). The three run actions execute the same code paths as the
 * cron/CLI jobs, synchronously in the request (as the existing feed-pull action
 * already does), each wrapped in a service_run row and an audit entry. All are
 * admin-gated by the route guard and CSRF-protected.
 */
final class AdminController extends Controller
{
    private ServiceStatusService $status;
    private DashboardService $dash;
    private ServiceRunLog $runs;
    private AuditService $audit;

    public function __construct(
        ?ServiceStatusService $status = null,
        ?DashboardService $dash = null,
        ?ServiceRunLog $runs = null,
        ?AuditService $audit = null
    ) {
        parent::__construct();
        $this->status = $status ?? new ServiceStatusService();
        $this->dash = $dash ?? new DashboardService();
        $this->runs = $runs ?? new ServiceRunLog();
        $this->audit = $audit ?? new AuditService();
    }

    public function index(): string
    {
        return $this->render('admin/index', [
            'services'     => $this->status->services(),
            'feeds'        => $this->safe(fn() => $this->dash->feeds(), []),
            'studentSync'  => $this->safe(fn() => $this->dash->studentSync(), []),
            'onesyncLast'  => $this->runs->last('onesync_db'),
            'recentRuns'   => $this->runs->recent(20),
            'runLogError'  => $this->runs->unavailableReason(),
            'canRunFeeds'  => FeedSync::configuredSources() !== [] || FeedSync::powerSchoolOdbcEnabled(),
            'canRunStudents' => FeedSync::powerSchoolOdbcEnabled(),
            'canRunOnesync'  => OneSyncResultImporter::sourceIds() !== []
                && trim((string) Config::get('ONESYNC_DB_HOST', '')) !== '',
            'csrf'         => Csrf::token(),
        ], 'admin', 'Configuration  /  Services', 'Services — TCS Identity Master');
    }

    /** Pull feed files from SFTP and import PowerSchool from Oracle (admin). */
    public function runFeeds(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/admin'));
        }
        $sources = FeedSync::configuredSources();
        $psOdbc = FeedSync::powerSchoolOdbcEnabled();
        if ($sources === [] && !$psOdbc) {
            $this->flash('No feeds configured (set SFTP_HOST + SFTP_<source>_DIR, and/or PS_ODBC_* for PowerSchool).');
            return $this->redirect(url('/admin'));
        }

        $actor = $this->currentUser()['name'];
        $runId = $this->runs->start('feeds', 'manual', $actor);
        $counts = ['downloaded' => 0, 'imported' => 0, 'errors' => 0];
        try {
            if ($sources !== []) {
                $t = FeedSync::fromConfig()->run($sources, false, true, $actor)['totals'];
                $counts['downloaded'] += (int) $t['downloaded'];
                $counts['imported']   += (int) $t['imported'];
                $counts['errors']     += (int) $t['errors'];
            }
            if ($psOdbc) {
                $ps = FeedSync::importPowerSchoolOdbc(false, $actor);
                if ($ps !== null) {
                    $counts['imported'] += (int) $ps['imported'];
                    $counts['errors']   += (int) $ps['errors'];
                }
            }
            $summary = "downloaded {$counts['downloaded']}, imported {$counts['imported']}, errors {$counts['errors']}";
            $this->runs->finish($runId, $counts['errors'] > 0 ? 'failed' : 'complete', $counts, $summary);
            $this->auditRun('feeds', $counts['errors'] > 0 ? 'failed' : 'success', $summary, $actor);
            $this->flash("Feed pull: {$summary}.");
        } catch (\Throwable $e) {
            error_log('[idm] admin feed run: ' . $e->getMessage());
            $this->runs->finish($runId, 'failed', $counts, $e->getMessage());
            $this->auditRun('feeds', 'failed', $e->getMessage(), $actor);
            $this->flash('Feed pull failed: ' . $e->getMessage());
        }
        return $this->redirect(url('/admin'));
    }

    /** Pull active students from PowerSchool (Oracle ODBC) and stage them (admin). */
    public function runStudents(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/admin'));
        }
        if (!FeedSync::powerSchoolOdbcEnabled()) {
            $this->flash('PowerSchool ODBC is not configured (set PS_ODBC_*) — students sync is unavailable.');
            return $this->redirect(url('/admin'));
        }

        $actor = $this->currentUser()['name'];
        $runId = $this->runs->start('students', 'manual', $actor);
        try {
            $result = (new StudentImporter())->runFromOdbc(false);
            $c = $result['counts'];
            $summary = sprintf('rows %d · inserted %d · updated %d · deactivated %d · skipped %d',
                $c['total'], $c['inserted'], $c['updated'], $c['deactivated'], $c['skipped']);
            $this->runs->finish($runId, 'complete', $c, $summary);
            $this->auditRun('students', 'success', $summary, $actor);
            $this->flash("Students sync: {$summary}.");
        } catch (\Throwable $e) {
            error_log('[idm] admin students run: ' . $e->getMessage());
            $this->runs->finish($runId, 'failed', [], $e->getMessage());
            $this->auditRun('students', 'failed', $e->getMessage(), $actor);
            $this->flash('Students sync failed: ' . $e->getMessage());
        }
        return $this->redirect(url('/admin'));
    }

    /** Pull OneSync provisioning results from OneSync's DB into account_sync_status (admin). */
    public function runOnesyncDb(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/admin'));
        }
        if (OneSyncResultImporter::sourceIds() === [] || trim((string) Config::get('ONESYNC_DB_HOST', '')) === '') {
            $this->flash('OneSync DB sync is not configured (set ONESYNC_DB_* and ONESYNC_DB_SOURCE_ID_*).');
            return $this->redirect(url('/admin'));
        }

        $actor = $this->currentUser()['name'];
        $runId = $this->runs->start('onesync_db', 'manual', $actor);
        try {
            $result = (new OneSyncResultImporter())->run(false, $actor);
            $c = $result['counts'];
            $summary = sprintf('users %d · rows %d · upserted %d · activated %d · failed %d · no-person %d · errors %d',
                $c['users'], $c['rows'], $c['upserted'], $c['activated'], $c['failed'], $c['no_person'], $c['errors']);
            if (isset($result['note'])) {
                $summary = $result['note'];
            }
            $this->runs->finish($runId, $c['errors'] > 0 ? 'failed' : 'complete', $c, $summary);
            $this->auditRun('onesync_db', $c['errors'] > 0 ? 'failed' : 'success', $summary, $actor);
            $this->flash("OneSync DB sync: {$summary}.");
        } catch (\Throwable $e) {
            error_log('[idm] admin onesync_db run: ' . $e->getMessage());
            $this->runs->finish($runId, 'failed', [], $e->getMessage());
            $this->auditRun('onesync_db', 'failed', $e->getMessage(), $actor);
            $this->flash('OneSync DB sync failed: ' . $e->getMessage());
        }
        return $this->redirect(url('/admin'));
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
            error_log('[idm] admin audit: ' . $e->getMessage());
        }
    }

    /** @template T @param callable():T $fn @param T $default @return T */
    private function safe(callable $fn, $default)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[idm] admin index: ' . $e->getMessage());
            return $default;
        }
    }
}
