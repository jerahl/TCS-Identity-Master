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
            'disableCandidates' => $this->dash->disableCandidates(),
            'syncHealth'  => $syncHealth,
            'studentSync' => $studentSync,
            'alerts'      => $this->buildAlerts($syncHealth, $feeds, $studentSync),
        ], 'home', 'Dashboard', 'Dashboard — TCS Identity Master');
    }

    /** Staleness banners: OneSync not run / write-back stale, and stale feeds. */
    private function buildAlerts(array $syncHealth, array $feeds, array $studentSync): array
    {
        $alerts = [];
        if ($syncHealth['state'] === 'never') {
            $alerts[] = 'OneSync has not written any provisioning status yet.';
        } elseif ($syncHealth['state'] === 'stale') {
            $alerts[] = "OneSync write-back looks stale — last status {$syncHealth['label']} (expected within {$syncHealth['staleHours']}h). Has the sync run?";
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
