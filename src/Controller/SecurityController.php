<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Service\SecurityStatusService;

/**
 * Admin "Security" page: a read-only dashboard of the host's security posture —
 * firewall (ufw), fail2ban jails and currently-banned IPs, the effective sshd
 * policy, automatic updates, AppArmor, auditd, and the app's own HTTP hardening.
 *
 * It mirrors the controls in scripts/harden-debian12.sh. Every signal is
 * read-only; the page takes no action. The host probes need root, so they run
 * through SecurityStatusService's `sudo -n` allowlist and only when
 * SECURITY_STATUS_ENABLED is set — otherwise the page shows the app-level card
 * plus a "how to enable" notice. Admin-gated by the route guard.
 */
final class SecurityController extends Controller
{
    private SecurityStatusService $security;

    public function __construct(?SecurityStatusService $security = null)
    {
        parent::__construct();
        $this->security = $security ?? new SecurityStatusService();
    }

    public function index(): string
    {
        return $this->render('security/index', [
            'snapshot' => $this->security->snapshot(),
            'sudoersFile' => 'deploy/idm-security-status.sudoers',
            'host' => (string) Config::get('APP_HOST', (string) ($_SERVER['HTTP_HOST'] ?? '')),
        ], 'security', 'Configuration  /  Security', 'Security — TCS Identity Master');
    }
}
