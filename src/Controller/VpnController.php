<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditService;
use App\Service\VpnControlService;
use App\Service\VpnMonitorService;
use App\Support\Csrf;

/**
 * VPN status page: relays the read-only pseast-vpn-monitor snapshot (service
 * state, tunnel, DB route + reachability, recent logs) and its uptime history.
 *
 * The page is view-only except for one action: editors/admins can restart the
 * VPN's systemd unit (restart()), gated server-side on the `edit` capability,
 * CSRF-protected, audited, and only offered when VPN_CONTROL_ENABLED is set.
 * Everything else here observes; only restart acts.
 */
final class VpnController extends Controller
{
    private VpnMonitorService $vpn;
    private VpnControlService $control;
    private AuditService $audit;

    public function __construct(
        ?VpnMonitorService $vpn = null,
        ?VpnControlService $control = null,
        ?AuditService $audit = null
    ) {
        parent::__construct();
        $this->vpn = $vpn ?? new VpnMonitorService();
        $this->control = $control ?? new VpnControlService();
        $this->audit = $audit ?? new AuditService();
    }

    public function index(): string
    {
        return $this->render('vpn/index', [
            'configured'     => $this->vpn->configured(),
            'monitorUrl'     => $this->vpn->baseUrl(),
            'status'         => $this->vpn->snapshot(),
            'history'        => $this->vpn->history(),
            'controlEnabled' => $this->control->enabled(),
            'serviceUnit'    => $this->control->unit(),
            'csrf'           => Csrf::token(),
        ], 'vpn', 'VPN status', 'VPN status — TCS Identity Master');
    }

    /** Restart the VPN systemd unit (edit capability; enforced by the route guard). */
    public function restart(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/vpn'));
        }

        $unit = $this->control->unit();
        $result = $this->control->restart();

        // Operational action — no person/entity row, so log under 'config'.
        $this->audit->log('config', null, 'update', null, [
            'action' => 'vpn-restart',
            'unit'   => $unit,
            'result' => $result['ok'] ? 'success' : 'failed',
            'detail' => $result['ok'] ? null : $result['error'],
        ], $this->currentUser()['name']);

        if ($result['ok']) {
            $this->flash("Restart requested for {$unit}. Give it a few seconds, then refresh to see it reconnect.");
        } else {
            $this->flash('VPN restart failed: ' . $result['error']);
        }
        return $this->redirect(url('/vpn'));
    }
}
