<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Http\Security;
use App\Sync\Freshness;
use RuntimeException;

/**
 * Read-only view of the host's security posture, for the admin "Security" page —
 * the runtime state of the controls scripts/harden-debian12.sh configures:
 * the ufw firewall, fail2ban (jails + currently-banned IPs), the effective sshd
 * policy, automatic security updates, AppArmor, and auditd. It also reports the
 * app's own HTTP hardening (HTTPS enforcement, HSTS, CSP).
 *
 * Most of those facts need root to read (`ufw status`, `fail2ban-client status`,
 * `sshd -T`), so — exactly like VpnControlService's restart path — this runs a
 * small, fixed allowlist of commands through `sudo -n` with no shell (argv
 * arrays, so nothing can smuggle extra arguments), each bounded by a timeout, and
 * is OFF unless SECURITY_STATUS_ENABLED is set. The host must also grant the web
 * user (www-data) a NOPASSWD sudoers rule for exactly those read-only commands
 * (see deploy/idm-security-status.sudoers).
 *
 * It never throws: a disabled feature, a missing sudoers rule, or a tool that
 * isn't installed surfaces as an 'unavailable'/'unknown' card, not a 500. The
 * command runner is injectable so the parsers are unit-testable without root.
 *
 * State vocabulary: 'ok' | 'warn' | 'down' | 'disabled' | 'unknown'.
 */
final class SecurityStatusService
{
    private bool $enabled;
    private string $sudo;
    /** @var array<string,string> tool => absolute path */
    private array $bins;
    private int $timeout;
    /** @var callable(array):array{code:int,out:string} */
    private $runner;
    private bool $useSudo;
    private string $snapshotFile;
    private int $maxAge;

    /**
     * @param array<string,string>|null $bins override tool paths (ufw, fail2ban, sshd, systemctl)
     * @param callable(array):array{code:int,out:string}|null $runner runs an argv array -> exit code + combined output
     * @param bool|null $useSudo prefix privileged commands with `sudo -n` (web); false when already root (collector)
     * @param string|null $snapshotFile read a pre-collected JSON snapshot from here instead of running commands live
     */
    public function __construct(
        ?bool $enabled = null,
        ?array $bins = null,
        ?int $timeout = null,
        ?callable $runner = null,
        ?string $sudo = null,
        ?bool $useSudo = null,
        ?string $snapshotFile = null
    ) {
        $this->enabled = $enabled ?? Config::bool('SECURITY_STATUS_ENABLED', false);
        $this->sudo = $sudo ?? (string) Config::get('SECURITY_SUDO_BIN', 'sudo');
        $this->bins = $bins ?? [
            'ufw'       => (string) Config::get('SECURITY_UFW_BIN', '/usr/sbin/ufw'),
            'fail2ban'  => (string) Config::get('SECURITY_FAIL2BAN_BIN', '/usr/bin/fail2ban-client'),
            'sshd'      => (string) Config::get('SECURITY_SSHD_BIN', '/usr/sbin/sshd'),
            'systemctl' => (string) Config::get('SECURITY_SYSTEMCTL_BIN', '/usr/bin/systemctl'),
        ];
        $this->timeout = $timeout ?? max(2, Config::int('SECURITY_STATUS_TIMEOUT', 6));
        $this->runner = $runner ?? fn(array $cmd): array => $this->run($cmd);
        $this->useSudo = $useSudo ?? true;
        $this->snapshotFile = $snapshotFile ?? trim((string) Config::get('SECURITY_STATUS_FILE', ''));
        $this->maxAge = max(60, Config::int('SECURITY_SNAPSHOT_MAX_AGE', 600));
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The full snapshot the page renders. The app-level HTTP-hardening card is
     * always computed live (it's per-request and needs no privilege). The host
     * cards come from one of two sources:
     *   - a JSON file written out-of-band by a root collector (bin/security_snapshot.php
     *     via the idm-security-snapshot timer) — the default on a hardened host,
     *     where php-fpm can't spawn processes; or
     *   - live commands run in-request via `sudo -n` when no snapshot file is set.
     *
     * @return array{enabled:bool,source:string,generated_at?:int,cards:list<array>,jails:list<array>,bannedIps:list<array{jail:string,ip:string}>}
     */
    public function snapshot(): array
    {
        $cards = [$this->appHardening()];
        if (!$this->enabled) {
            return ['enabled' => false, 'source' => 'off', 'cards' => $cards, 'jails' => [], 'bannedIps' => []];
        }
        if ($this->snapshotFile !== '') {
            return $this->fromFile($cards);
        }
        $host = $this->hostReport();
        return [
            'enabled'   => true,
            'source'    => 'live',
            'cards'     => array_merge($cards, $host['cards']),
            'jails'     => $host['jails'],
            'bannedIps' => $host['bannedIps'],
        ];
    }

    /**
     * The host-command portion of the snapshot (everything that needs root):
     * firewall, fail2ban, sshd, updates, AppArmor, auditd. This is what the root
     * collector serializes to the snapshot file; the app card is added on read.
     *
     * @return array{cards:list<array>,jails:list<array>,bannedIps:list<array{jail:string,ip:string}>}
     */
    public function hostReport(): array
    {
        [$f2bCard, $jails, $banned] = $this->fail2ban();
        $cards = [
            $this->firewall(),
            $f2bCard,
            $this->ssh(),
            $this->autoUpdates(),
            $this->unit('AppArmor', 'apparmor', 'Mandatory access control confining services.'),
            $this->unit('auditd', 'auditd', 'Kernel audit of auth, sudoers, and SSH config changes.'),
        ];
        return ['cards' => $cards, 'jails' => $jails, 'bannedIps' => $banned];
    }

    /**
     * Read the collector's JSON snapshot and fold it in behind the (live) app
     * card, with a freshness card so a stopped collector is obvious rather than
     * showing stale data as if it were current.
     *
     * @param list<array> $appCards
     */
    private function fromFile(array $appCards): array
    {
        $miss = function (string $detail) use ($appCards): array {
            $appCards[] = $this->card('collector', 'Host status collector', 'unknown', $detail, [['File', $this->snapshotFile]]);
            return ['enabled' => true, 'source' => 'file', 'cards' => $appCards, 'jails' => [], 'bannedIps' => []];
        };
        if (!is_file($this->snapshotFile) || !is_readable($this->snapshotFile)) {
            return $miss('No snapshot yet — is the idm-security-snapshot timer installed and running? (see deploy/)');
        }
        $data = json_decode((string) @file_get_contents($this->snapshotFile), true);
        if (!is_array($data) || !isset($data['cards']) || !is_array($data['cards'])) {
            return $miss('Snapshot file is missing or not valid JSON — check the collector timer.');
        }

        $gen = (int) ($data['generated_at'] ?? 0);
        $age = max(0, time() - $gen);
        $stale = $gen === 0 || $age > $this->maxAge;
        $when = $gen === 0 ? 'unknown' : Freshness::ago($age);
        $freshCard = $this->card(
            'collector',
            'Host status collector',
            $stale ? 'warn' : 'ok',
            $stale
                ? "Snapshot is stale (collected {$when}); the idm-security-snapshot timer may have stopped."
                : "Collected {$when} by the idm-security-snapshot timer.",
            [['Collected', $when], ['Max age', $this->maxAge . 's']]
        );

        return [
            'enabled'      => true,
            'source'       => 'file',
            'generated_at' => $gen,
            'cards'        => array_merge($appCards, [$freshCard], $data['cards']),
            'jails'        => is_array($data['jails'] ?? null) ? $data['jails'] : [],
            'bannedIps'    => is_array($data['bannedIps'] ?? null) ? $data['bannedIps'] : [],
        ];
    }

    // ---- individual cards -----------------------------------------------------

    private function firewall(): array
    {
        $res = $this->exec([$this->bins['ufw'], 'status', 'verbose'], sudo: true);
        if (!$res['ok']) {
            return $this->card('firewall', 'Firewall (ufw)', 'unknown', $this->cmdError($res), []);
        }
        $p = self::parseUfw($res['out']);
        $facts = [];
        if ($p['default_incoming'] !== null) {
            $facts[] = ['Default inbound', $p['default_incoming']];
        }
        if ($p['logging'] !== null) {
            $facts[] = ['Logging', $p['logging']];
        }
        $facts[] = ['Allow rules', (string) count($p['rules'])];
        foreach ($p['rules'] as $rule) {
            $facts[] = ['·', $rule];
        }

        if (!$p['active']) {
            return $this->card('firewall', 'Firewall (ufw)', 'down', 'ufw is INACTIVE — the host is not firewalled.', $facts);
        }
        $state = ($p['default_incoming'] !== null && !str_contains($p['default_incoming'], 'deny')) ? 'warn' : 'ok';
        $detail = $state === 'warn'
            ? 'Active, but the default inbound policy is not deny.'
            : 'Active — default-deny inbound with an explicit allow-list.';
        return $this->card('firewall', 'Firewall (ufw)', $state, $detail, $facts);
    }

    /**
     * @return array{0:array,1:list<array>,2:list<array{jail:string,ip:string}>}
     */
    private function fail2ban(): array
    {
        $res = $this->exec([$this->bins['fail2ban'], 'status'], sudo: true);
        if (!$res['ok']) {
            return [$this->card('fail2ban', 'fail2ban', 'unknown', $this->cmdError($res), []), [], []];
        }
        $jailNames = self::parseFail2banJails($res['out']);
        if ($jailNames === []) {
            return [$this->card('fail2ban', 'fail2ban', 'warn', 'Running, but no jails are enabled.', []), [], []];
        }

        $jails = [];
        $banned = [];
        $totalBanned = 0;
        foreach ($jailNames as $name) {
            // Jail names come from fail2ban's own output; still validate before it
            // reaches the sudo command line (defense in depth against the wildcard rule).
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                continue;
            }
            $jr = $this->exec([$this->bins['fail2ban'], 'status', $name], sudo: true);
            $stat = $jr['ok'] ? self::parseFail2banJail($jr['out']) : ['banned' => 0, 'total_banned' => 0, 'failed' => 0, 'ips' => []];
            $totalBanned += $stat['banned'];
            $jails[] = [
                'name'         => $name,
                'banned'       => $stat['banned'],
                'total_banned' => $stat['total_banned'],
                'failed'       => $stat['failed'],
            ];
            foreach ($stat['ips'] as $ip) {
                $banned[] = ['jail' => $name, 'ip' => $ip];
            }
        }

        $facts = [['Jails', implode(', ', $jailNames)], ['Currently banned', (string) $totalBanned]];
        $detail = $totalBanned > 0
            ? "{$totalBanned} IP" . ($totalBanned === 1 ? '' : 's') . ' currently banned across ' . count($jails) . ' jail(s).'
            : 'Running — ' . count($jails) . ' jail(s), no active bans.';
        return [$this->card('fail2ban', 'fail2ban', 'ok', $detail, $facts), $jails, $banned];
    }

    private function ssh(): array
    {
        $res = $this->exec([$this->bins['sshd'], '-T'], sudo: true);
        if (!$res['ok']) {
            return $this->card('ssh', 'SSH daemon', 'unknown', $this->cmdError($res), []);
        }
        $p = self::parseSshd($res['out']);
        $root = $p['permitrootlogin'] ?? '?';
        $pw = $p['passwordauthentication'] ?? '?';
        $facts = [
            ['Port', $p['port'] ?? '?'],
            ['PermitRootLogin', $root],
            ['PasswordAuthentication', $pw],
        ];
        // Hardened = root login off and password auth off (key-only).
        $rootOff = $root === 'no';
        $pwOff = $pw === 'no';
        $state = ($rootOff && $pwOff) ? 'ok' : 'warn';
        $detail = match (true) {
            $rootOff && $pwOff => 'Key-only auth, root login disabled.',
            !$pwOff && !$rootOff => 'Password auth AND root login are enabled — high exposure.',
            !$pwOff            => 'Password authentication is enabled (brute-force exposure).',
            default            => 'Root login is permitted.',
        };
        return $this->card('ssh', 'SSH daemon', $state, $detail, $facts);
    }

    private function autoUpdates(): array
    {
        $active = $this->unitIsActive('unattended-upgrades');
        $rebootRequired = is_file('/var/run/reboot-required');
        $facts = [
            ['unattended-upgrades', $active === null ? 'unknown' : ($active ? 'active' : 'inactive')],
            ['Reboot required', $rebootRequired ? 'YES' : 'no'],
        ];
        if ($active === false) {
            return $this->card('updates', 'Automatic updates', 'warn', 'unattended-upgrades is not active — security patches may not apply.', $facts);
        }
        if ($rebootRequired) {
            return $this->card('updates', 'Automatic updates', 'warn', 'A pending update needs a reboot to take effect.', $facts);
        }
        if ($active === null) {
            return $this->card('updates', 'Automatic updates', 'unknown', 'Could not determine the unattended-upgrades state.', $facts);
        }
        return $this->card('updates', 'Automatic updates', 'ok', 'unattended-upgrades is active.', $facts);
    }

    /** A systemd unit health card via `systemctl is-active` (no privilege needed). */
    private function unit(string $label, string $unit, string $detailOk): array
    {
        $active = $this->unitIsActive($unit);
        $facts = [['Unit', $unit], ['State', $active === null ? 'unknown' : ($active ? 'active' : 'inactive')]];
        return match ($active) {
            true  => $this->card($unit, $label, 'ok', $detailOk, $facts),
            false => $this->card($unit, $label, 'warn', "{$label} is not active.", $facts),
            default => $this->card($unit, $label, 'unknown', "Could not determine {$label} state.", $facts),
        };
    }

    /** App-level HTTP hardening (from Security + config) — no host access needed. */
    private function appHardening(): array
    {
        $prod = strtolower((string) Config::get('APP_ENV', 'development')) === 'production';
        $https = Security::isHttps();
        $facts = [
            ['Environment', $prod ? 'production' : 'development'],
            ['Request over HTTPS', $https ? 'yes' : 'no'],
            ['HSTS', $prod ? 'sent (production)' : 'off (non-production)'],
            ['CSP', 'sent (strict, script-src self)'],
        ];
        if (!$prod) {
            return $this->card('app_headers', 'App HTTP hardening', 'warn',
                'APP_ENV is not production — HTTPS is not enforced and HSTS is off.', $facts);
        }
        $state = $https ? 'ok' : 'warn';
        $detail = $https
            ? 'HTTPS enforced, HSTS + strict CSP sent on every response.'
            : 'Production, but this request is not HTTPS — check the TLS-terminating proxy.';
        return $this->card('app_headers', 'App HTTP hardening', $state, $detail, $facts);
    }

    // ---- parsers (pure; unit-tested) ------------------------------------------

    /** @return array{active:bool,default_incoming:?string,logging:?string,rules:list<string>} */
    public static function parseUfw(string $out): array
    {
        $active = false;
        $defaultIncoming = null;
        $logging = null;
        $rules = [];
        $inTable = false;
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^Status:\s*(\w+)/i', $t, $m)) {
                $active = strtolower($m[1]) === 'active';
                continue;
            }
            if (preg_match('/^Logging:\s*(.+)$/i', $t, $m)) {
                $logging = trim($m[1]);
                continue;
            }
            if (preg_match('/^Default:\s*(.+)$/i', $t, $m)) {
                // e.g. "deny (incoming), allow (outgoing), disabled (routed)"
                if (preg_match('/(\w+)\s*\(incoming\)/i', $m[1], $mm)) {
                    $defaultIncoming = strtolower($mm[1]);
                }
                continue;
            }
            if (preg_match('/^To\s+Action\s+From/i', $t)) {
                $inTable = true;
                continue;
            }
            if ($inTable) {
                if (str_starts_with($t, '--')) {
                    continue;
                }
                // Collapse runs of whitespace so the rule reads cleanly.
                $rules[] = (string) preg_replace('/\s{2,}/', '  ', $t);
            }
        }
        return ['active' => $active, 'default_incoming' => $defaultIncoming, 'logging' => $logging, 'rules' => $rules];
    }

    /** @return list<string> jail names */
    public static function parseFail2banJails(string $out): array
    {
        // [ \t]* (not \s*) so an empty list can't let the capture cross the newline.
        if (!preg_match('/Jail list:[ \t]*(.*)$/mi', $out, $m)) {
            return [];
        }
        $names = array_map('trim', preg_split('/[,\s]+/', trim($m[1])) ?: []);
        return array_values(array_filter($names, static fn(string $n): bool => $n !== ''));
    }

    /** @return array{banned:int,total_banned:int,failed:int,ips:list<string>} */
    public static function parseFail2banJail(string $out): array
    {
        $int = static function (string $label) use ($out): int {
            return preg_match('/' . preg_quote($label, '/') . ':\s*(\d+)/i', $out, $m) ? (int) $m[1] : 0;
        };
        $ips = [];
        // [ \t]* (not \s*) so an empty ban list doesn't swallow the next line's text.
        if (preg_match('/Banned IP list:[ \t]*(.*)$/mi', $out, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $ip) {
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        return [
            'banned'       => $int('Currently banned'),
            'total_banned' => $int('Total banned'),
            'failed'       => $int('Currently failed'),
            'ips'          => $ips,
        ];
    }

    /**
     * `sshd -T` prints "key value" per line (keys lowercased). We only keep the
     * few directives the dashboard reports.
     *
     * @return array<string,string>
     */
    public static function parseSshd(string $out): array
    {
        $want = ['port', 'permitrootlogin', 'passwordauthentication'];
        $found = [];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if ($parts === false || count($parts) < 2) {
                continue;
            }
            $key = strtolower($parts[0]);
            if (in_array($key, $want, true) && !isset($found[$key])) {
                $found[$key] = trim($parts[1]);
            }
        }
        return $found;
    }

    // ---- helpers --------------------------------------------------------------

    /** @return bool|null active? (null = couldn't tell) */
    private function unitIsActive(string $unit): ?bool
    {
        $res = $this->exec([$this->bins['systemctl'], 'is-active', $unit], sudo: false);
        $out = strtolower(trim($res['out']));
        // is-active exits non-zero for inactive/failed but still prints the state.
        if ($out === 'active') {
            return true;
        }
        if (in_array($out, ['inactive', 'failed', 'deactivating', 'activating'], true)) {
            return $out === 'activating';
        }
        return null;
    }

    private function card(string $key, string $label, string $state, string $detail, array $facts): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail, 'facts' => $facts];
    }

    private function cmdError(array $res): string
    {
        $out = trim((string) ($res['out'] ?? ''));
        if ($out !== '') {
            return 'Command failed: ' . $out;
        }
        $hint = $this->useSudo
            ? 'check the sudoers rule for the web user (deploy/idm-security-status.sudoers), or use the collector timer.'
            : 'is the tool installed and is the collector running as root?';
        return 'Command failed (exit ' . (int) ($res['code'] ?? 1) . ') — ' . $hint;
    }

    /**
     * Run one allowlisted command, optionally via `sudo -n`. Never throws.
     *
     * @param string[] $cmd
     * @return array{ok:bool,code:int,out:string}
     */
    private function exec(array $cmd, bool $sudo): array
    {
        if ($sudo && $this->useSudo) {
            $cmd = array_merge([$this->sudo, '-n'], $cmd);
        }
        try {
            $res = ($this->runner)($cmd);
        } catch (\Throwable $e) {
            error_log('[idm] security status: ' . $e->getMessage());
            return ['ok' => false, 'code' => 1, 'out' => ''];
        }
        $code = (int) ($res['code'] ?? 1);
        return ['ok' => $code === 0, 'code' => $code, 'out' => (string) ($res['out'] ?? '')];
    }

    /**
     * Run a command as an argv array (no shell), capturing combined stdout+stderr
     * with a hard timeout so a hung tool can't wedge the request. Mirrors
     * VpnControlService's runner.
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
