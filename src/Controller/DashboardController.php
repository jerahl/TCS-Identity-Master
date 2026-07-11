<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Import\OneSyncResultImporter;
use App\Service\DashboardService;
use App\Service\GoogleWorkspaceService;

/**
 * Home / health dashboard: KPIs, recent activity, last feed per source, and the
 * failed-sync rollup.
 */
final class DashboardController extends Controller
{
    private DashboardService $dash;

    public function __construct(?DashboardService $dash = null)
    {
        parent::__construct();
        $this->dash = $dash ?? new DashboardService();
    }

    public function index(): string
    {
        $feeds = $this->dash->feeds();
        $studentSync = $this->dash->studentSync();
        // Direct provisioning (IDM is authoritative): AD via Adaxes, Google direct.
        $adSync = $this->dash->directSyncHealth('adaxes', self::adaxesConfigured());
        $googleSync = $this->dash->directSyncHealth('google', (new GoogleWorkspaceService())->configured());

        return $this->render('dashboard/index', [
            'kpis'        => $this->dash->kpis(),
            'activity'    => $this->dash->recentActivity(),
            'feeds'       => $feeds,
            'failedSyncs' => $this->dash->failedSyncs(),
            'adSync'      => $adSync,
            'googleSync'  => $googleSync,
            'studentSync' => $studentSync,
            'alerts'      => $this->buildAlerts($adSync, $googleSync, $feeds, $studentSync),
        ], 'home', 'Dashboard', 'Dashboard — TCS Identity Master');
    }

    /** AD (Adaxes) is set up once a base URL and a read credential are present. */
    private static function adaxesConfigured(): bool
    {
        return trim((string) Config::get('ADAXES_BASE_URL', '')) !== ''
            && (trim((string) Config::get('ADAXES_TOKEN', '')) !== ''
                || (trim((string) Config::get('ADAXES_USERNAME', '')) !== '' && trim((string) Config::get('ADAXES_PASSWORD', '')) !== ''));
    }

    /** Staleness/failure banners for the direct syncs, students, and feeds. */
    private function buildAlerts(array $adSync, array $googleSync, array $feeds, array $studentSync): array
    {
        $alerts = [];
        foreach ([['Active Directory', 'bin/adaxes_sync.php', $adSync], ['Google Workspace', 'bin/sync_google.php', $googleSync]] as [$name, $cli, $h]) {
            if (!$h['configured']) {
                continue; // integration not set up → nothing to alert on
            }
            if (($h['status'] ?? null) === 'failed') {
                $alerts[] = "The last {$name} sync failed ({$h['label']}) — check {$cli}.";
            } elseif ($h['state'] === 'stale') {
                $alerts[] = "{$name} sync looks stale — last run {$h['label']} (expected within {$h['staleHours']}h). Has cron run {$cli}?";
            }
        }
        // OneSync DB sync alerts only while the sync is still in use (pre-cutover).
        if (OneSyncResultImporter::syncEnabled()) {
            $syncHealth = $this->dash->syncHealth();
            if ($syncHealth['state'] === 'never') {
                $alerts[] = 'The OneSync DB sync has not run yet — no provisioning results pulled. Run bin/import_onesync_db.php.';
            } elseif (($syncHealth['status'] ?? null) === 'failed') {
                $alerts[] = "The last OneSync DB sync failed ({$syncHealth['label']}) — provisioning results may be stale. Check bin/import_onesync_db.php.";
            } elseif ($syncHealth['state'] === 'stale') {
                $alerts[] = "OneSync DB sync looks stale — last run {$syncHealth['label']} (expected within {$syncHealth['staleHours']}h). Has cron run bin/import_onesync_db.php?";
            }
        }
        if (($studentSync['status'] ?? null) === 'failed') {
            $alerts[] = 'The last students sync failed — students may be stale in OneSync. Check bin/import_students.php.';
        } elseif (($studentSync['state'] ?? '') === 'stale') {
            $alerts[] = "Students sync looks stale — last run {$studentSync['label']}. Has bin/import_students.php run?";
        }
        foreach ($feeds as $f) {
            if (($f['fresh_state'] ?? '') === 'stale') {
                $alerts[] = "{$f['label']} feed is stale — last import {$f['fresh_label']}.";
            }
        }
        return $alerts;
    }
}
