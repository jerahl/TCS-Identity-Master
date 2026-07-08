<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DashboardService;

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
        $syncHealth = $this->dash->syncHealth();
        $studentSync = $this->dash->studentSync();

        return $this->render('dashboard/index', [
            'kpis'        => $this->dash->kpis(),
            'activity'    => $this->dash->recentActivity(),
            'feeds'       => $feeds,
            'failedSyncs' => $this->dash->failedSyncs(),
            'syncHealth'  => $syncHealth,
            'studentSync' => $studentSync,
            'alerts'      => $this->buildAlerts($syncHealth, $feeds, $studentSync),
        ], 'home', 'Dashboard', 'Dashboard — TCS Identity Master');
    }

    /** Staleness banners: OneSync DB sync not run / stale / failed, and stale feeds. */
    private function buildAlerts(array $syncHealth, array $feeds, array $studentSync): array
    {
        $alerts = [];
        if ($syncHealth['state'] === 'never') {
            $alerts[] = 'The OneSync DB sync has not run yet — no provisioning results pulled. Run bin/import_onesync_db.php.';
        } elseif (($syncHealth['status'] ?? null) === 'failed') {
            $alerts[] = "The last OneSync DB sync failed ({$syncHealth['label']}) — provisioning results may be stale. Check bin/import_onesync_db.php.";
        } elseif ($syncHealth['state'] === 'stale') {
            $alerts[] = "OneSync DB sync looks stale — last run {$syncHealth['label']} (expected within {$syncHealth['staleHours']}h). Has cron run bin/import_onesync_db.php?";
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
