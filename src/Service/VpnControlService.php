<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use RuntimeException;

/**
 * The one write path for the VPN tunnel: restart its systemd unit on this box.
 *
 * The rest of the VPN feature is strictly read-only — VpnMonitorService relays
 * the pseast-vpn-monitor snapshot and never touches the tunnel. This service is
 * the deliberate exception: editors/admins can ask systemd to restart the unit
 * when the monitor shows it down. It runs `sudo systemctl restart <unit>` with
 * no shell (argv array, so an operator-set unit name can't smuggle arguments),
 * and is off unless VPN_CONTROL_ENABLED is set — the host must also grant the
 * web user (www-data) a NOPASSWD sudoers rule for exactly that command
 * (see deploy/idm-vpn-restart.sudoers).
 *
 * Like VpnMonitorService it never throws: restart() returns a result envelope so
 * a misconfigured host or a failing systemctl surfaces as a message, not a 500.
 * The command runner is injectable so the logic is unit-testable without root.
 */
final class VpnControlService
{
    private bool $enabled;
    private string $unit;
    private string $sudo;
    private string $systemctl;
    private int $timeout;
    /** @var callable(array):array{code:int,out:string} */
    private $runner;

    /** @param callable(array):array{code:int,out:string}|null $runner runs an argv array, returns exit code + combined output */
    public function __construct(
        ?bool $enabled = null,
        ?string $unit = null,
        ?int $timeout = null,
        ?callable $runner = null
    ) {
        $this->enabled = $enabled ?? Config::bool('VPN_CONTROL_ENABLED', false);
        $this->unit = trim($unit ?? (string) Config::get('VPN_SERVICE_UNIT', 'openconnect-pseast.service'));
        $this->sudo = (string) Config::get('VPN_SUDO_BIN', 'sudo');
        $this->systemctl = (string) Config::get('VPN_SYSTEMCTL_BIN', 'systemctl');
        $this->timeout = $timeout ?? max(5, Config::int('VPN_CONTROL_TIMEOUT', 30));
        $this->runner = $runner ?? fn(array $cmd): array => $this->run($cmd);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    /** The argv the runner executes — exposed for tests/diagnostics. */
    public function restartCommand(): array
    {
        return [$this->sudo, '-n', $this->systemctl, 'restart', $this->unit];
    }

    /**
     * Restart the VPN service. Never throws — always a result envelope.
     *
     * @return array{ok:bool,error:?string,output:string}
     */
    public function restart(): array
    {
        if (!$this->enabled) {
            return ['ok' => false, 'error' => 'VPN restart is disabled — set VPN_CONTROL_ENABLED=true.', 'output' => ''];
        }
        if (!self::isValidUnit($this->unit)) {
            return ['ok' => false, 'error' => 'Refusing to act on an invalid service unit name.', 'output' => ''];
        }

        try {
            $res = ($this->runner)($this->restartCommand());
        } catch (\Throwable $e) {
            error_log('[idm] vpn restart: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not execute the restart command on this host.', 'output' => ''];
        }

        $code = (int) ($res['code'] ?? 1);
        $out = trim((string) ($res['out'] ?? ''));
        if ($code !== 0) {
            $hint = $out !== '' ? $out : 'systemctl exited with code ' . $code
                . ' — check the sudoers rule for the web user (deploy/idm-vpn-restart.sudoers).';
            return ['ok' => false, 'error' => $hint, 'output' => $out];
        }
        return ['ok' => true, 'error' => null, 'output' => $out];
    }

    /**
     * A systemd unit name — letters, digits, and @ . _ - only. Restricting it
     * keeps a stray config value from turning into extra systemctl arguments.
     */
    public static function isValidUnit(string $unit): bool
    {
        return $unit !== '' && (bool) preg_match('/^[A-Za-z0-9@._-]+$/', $unit);
    }

    /**
     * Run a command as an argv array (no shell), capturing combined stdout+stderr
     * with a hard timeout so a hung systemctl can't wedge the request.
     *
     * @param string[] $cmd
     * @return array{code:int,out:string}
     */
    private function run(array $cmd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open failed to start ' . ($cmd[0] ?? '?'));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $code = null;
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                $out .= "\n(timed out after {$this->timeout}s)";
                $code = 124;
                break;
            }
            usleep(50000);
        }
        // Drain anything buffered between the last read and exit.
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($proc);

        return ['code' => $code ?? 1, 'out' => $out];
    }
}
