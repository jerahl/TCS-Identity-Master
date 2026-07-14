<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use RuntimeException;

/**
 * Fire the direct Google Workspace sync on demand from the web, without blocking
 * the request. The sync does a live Google lookup per person, so it can run for
 * minutes — far too long to run synchronously in an HTTP request (it ties up a
 * PHP-FPM worker and exhausts pm.max_children, so nginx returns 504). Instead this
 * asks systemd to start the oneshot unit (deploy/idm-google-sync.service) with
 * `--no-block`, so it returns immediately and the job runs in the background under
 * systemd; the CLI (bin/sync_google.php) records its own service_run row, which the
 * Services page reads for the summary.
 *
 * Mirrors AdaxesRunService / VpnControlService: `sudo -n systemctl start --no-block
 * <unit>` with no shell (argv array, so an operator-set unit name can't smuggle
 * arguments), off unless GOOGLE_RUN_ENABLED=true, and the host must grant the web
 * user (www-data) a NOPASSWD sudoers rule for exactly that command
 * (deploy/idm-google-run.sudoers). Never throws — start() returns a result
 * envelope. The runner is injectable so the logic is unit-testable without root.
 */
final class GoogleRunService
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
        $this->enabled = $enabled ?? Config::bool('GOOGLE_RUN_ENABLED', false);
        $this->unit = trim($unit ?? (string) Config::get('GOOGLE_SERVICE_UNIT', 'idm-google-sync.service'));
        $this->sudo = (string) Config::get('GOOGLE_SUDO_BIN', 'sudo');
        $this->systemctl = (string) Config::get('GOOGLE_SYSTEMCTL_BIN', 'systemctl');
        $this->timeout = $timeout ?? max(5, Config::int('GOOGLE_RUN_TIMEOUT', 15));
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
    public function startCommand(): array
    {
        // --no-block: enqueue the oneshot and return at once (don't wait for the
        // whole sync, which can take minutes).
        return [$this->sudo, '-n', $this->systemctl, 'start', '--no-block', $this->unit];
    }

    /**
     * Start the Google sync in the background. Never throws — always a result envelope.
     *
     * @return array{ok:bool,error:?string,output:string}
     */
    public function start(): array
    {
        if (!$this->enabled) {
            return ['ok' => false, 'error' => 'On-demand Google sync is disabled — set GOOGLE_RUN_ENABLED=true.', 'output' => ''];
        }
        if (!self::isValidUnit($this->unit)) {
            return ['ok' => false, 'error' => 'Refusing to act on an invalid service unit name.', 'output' => ''];
        }

        try {
            $res = ($this->runner)($this->startCommand());
        } catch (\Throwable $e) {
            error_log('[idm] google run: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not execute the start command on this host.', 'output' => ''];
        }

        $code = (int) ($res['code'] ?? 1);
        $out = trim((string) ($res['out'] ?? ''));
        if ($code !== 0) {
            $hint = $out !== '' ? $out : 'systemctl exited with code ' . $code
                . ' — check the sudoers rule for the web user (deploy/idm-google-run.sudoers).';
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
