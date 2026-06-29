<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\VpnMonitorService;

/**
 * VPN status page: relays the read-only pseast-vpn-monitor snapshot (service
 * state, tunnel, DB route + reachability, recent logs) and its uptime history.
 * View-only — like the dashboard, it observes; it never controls the tunnel.
 */
final class VpnController extends Controller
{
    private VpnMonitorService $vpn;

    public function __construct(?VpnMonitorService $vpn = null)
    {
        parent::__construct();
        $this->vpn = $vpn ?? new VpnMonitorService();
    }

    public function index(): string
    {
        return $this->render('vpn/index', [
            'configured' => $this->vpn->configured(),
            'monitorUrl' => $this->vpn->baseUrl(),
            'status'     => $this->vpn->snapshot(),
            'history'    => $this->vpn->history(),
        ], 'vpn', 'VPN status', 'VPN status — TCS Identity Master');
    }
}
