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
        return $this->render('dashboard/index', [
            'kpis'        => $this->dash->kpis(),
            'activity'    => $this->dash->recentActivity(),
            'feeds'       => $this->dash->feeds(),
            'failedSyncs' => $this->dash->failedSyncs(),
        ], 'home', 'Dashboard', 'Dashboard — TCS Identity Master');
    }
}
