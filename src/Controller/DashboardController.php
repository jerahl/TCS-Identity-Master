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

        return $this->render('dashboard/index', [
            'kpis'        => $this->dash->kpis(),
            'activity'    => $this->dash->recentActivity(),
            'feeds'       => $feeds,
            'failedSyncs' => $this->dash->failedSyncs(),
            'syncHealth'  => $syncHealth,
            'alerts'      => $this->buildAlerts($syncHealth, $feeds),
        ], 'home', 'Dashboard', 'Dashboard — TCS Identity Master');
    }

    /** Staleness banners: OneSync not run / write-back stale, and stale feeds. */
    private function buildAlerts(array $syncHealth, array $feeds): array
    {
        $alerts = [];
        if ($syncHealth['state'] === 'never') {
            $alerts[] = 'OneSync has not written any provisioning status yet.';
        } elseif ($syncHealth['state'] === 'stale') {
            $alerts[] = "OneSync write-back looks stale — last status {$syncHealth['label']} (expected within {$syncHealth['staleHours']}h). Has the sync run?";
        }
        foreach ($feeds as $f) {
            if (($f['fresh_state'] ?? '') === 'stale') {
                $alerts[] = "{$f['label']} feed is stale — last import {$f['fresh_label']}.";
            }
        }
        return $alerts;
    }
}
