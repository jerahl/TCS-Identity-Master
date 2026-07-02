<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SecurityStatusService;
use PHPUnit\Framework\TestCase;

/**
 * The security dashboard reads host state through a small allow-list of
 * read-only commands. It must stay OFF (and run nothing) unless enabled, parse
 * ufw / fail2ban / sshd output correctly, aggregate banned IPs, and never pass a
 * malformed jail name to the sudo command line. The command runner is injected
 * so nothing shells out under test.
 */
final class SecurityStatusServiceTest extends TestCase
{
    private const UFW = <<<OUT
        Status: active
        Logging: on (low)
        Default: deny (incoming), allow (outgoing), disabled (routed)
        New profiles: skip

        To                         Action      From
        --                         ------      ----
        22/tcp                     LIMIT IN    Anywhere                   # SSH (rate-limited)
        80/tcp                     ALLOW IN    Anywhere                   # HTTP
        443/tcp                    ALLOW IN    Anywhere                   # HTTPS (app)
        OUT;

    private const JAILS = "Status\n|- Number of jail:\t2\n`- Jail list:\tsshd, recidive\n";

    private const JAIL_SSHD = <<<OUT
        Status for the jail: sshd
        |- Filter
        |  |- Currently failed: 1
        |  |- Total failed:     42
        |  `- File list:        /var/log/auth.log
        `- Actions
           |- Currently banned: 2
           |- Total banned:     17
           `- Banned IP list:   203.0.113.7 198.51.100.9
        OUT;

    private const SSHD = "port 22\npermitrootlogin no\npasswordauthentication no\nx11forwarding no\n";

    public function testDisabledByDefaultRunsNothing(): void
    {
        $ran = false;
        $svc = new SecurityStatusService(false, null, 6, function () use (&$ran) {
            $ran = true;
            return ['code' => 0, 'out' => ''];
        });

        self::assertFalse($svc->enabled());
        $snap = $svc->snapshot();
        self::assertFalse($snap['enabled']);
        self::assertFalse($ran, 'no host command may run when disabled');
        // Only the app-level HTTP-hardening card shows when host probes are off.
        self::assertCount(1, $snap['cards']);
        self::assertSame('app_headers', $snap['cards'][0]['key']);
        self::assertSame([], $snap['bannedIps']);
    }

    public function testSnapshotParsesAndAggregatesWhenEnabled(): void
    {
        $svc = $this->enabledService();
        $snap = $svc->snapshot();

        self::assertTrue($snap['enabled']);
        $byKey = [];
        foreach ($snap['cards'] as $c) {
            $byKey[$c['key']] = $c;
        }
        self::assertSame('ok', $byKey['firewall']['state']);
        self::assertSame('ok', $byKey['ssh']['state']);       // root off + password off
        self::assertSame('ok', $byKey['fail2ban']['state']);

        // Two IPs banned in the sshd jail, aggregated with the jail name.
        self::assertSame(
            [['jail' => 'sshd', 'ip' => '203.0.113.7'], ['jail' => 'sshd', 'ip' => '198.51.100.9']],
            $snap['bannedIps']
        );
        self::assertSame('sshd', $snap['jails'][0]['name']);
        self::assertSame(2, $snap['jails'][0]['banned']);
    }

    public function testPasswordAuthOnDowngradesSshToWarn(): void
    {
        $svc = $this->enabledService(sshd: "port 2222\npermitrootlogin no\npasswordauthentication yes\n");
        $ssh = $this->card($svc->snapshot(), 'ssh');
        self::assertSame('warn', $ssh['state']);
        self::assertSame('2222', $this->fact($ssh, 'Port'));
    }

    public function testInactiveFirewallIsDown(): void
    {
        $svc = $this->enabledService(ufw: "Status: inactive\n");
        self::assertSame('down', $this->card($svc->snapshot(), 'firewall')['state']);
    }

    public function testMalformedJailNameNeverReachesTheCommandLine(): void
    {
        $seenJailArg = false;
        $runner = function (array $cmd) use (&$seenJailArg) {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'fail2ban-client status sshd;rm')) {
                $seenJailArg = true;
            }
            if (str_contains($joined, 'ufw')) {
                return ['code' => 0, 'out' => self::UFW];
            }
            if (str_contains($joined, 'fail2ban-client status sshd;rm')) {
                return ['code' => 0, 'out' => ''];
            }
            if (str_contains($joined, 'fail2ban-client status')) {
                // A jail name with shell/space metacharacters must be rejected.
                return ['code' => 0, 'out' => "`- Jail list:\tsshd;rm -rf, ok-jail\n"];
            }
            if (str_contains($joined, 'sshd -T')) {
                return ['code' => 0, 'out' => self::SSHD];
            }
            return ['code' => 0, 'out' => "active\n"];
        };
        $svc = new SecurityStatusService(true, $this->bins(), 6, $runner, 'sudo', useSudo: true, snapshotFile: '');
        $snap = $svc->snapshot();

        self::assertFalse($seenJailArg, 'a jail name with illegal chars must never be passed to sudo');
        $names = array_column($snap['jails'], 'name');
        self::assertNotContains('sshd;rm', $names);
        self::assertContains('ok-jail', $names);
    }

    public function testCollectorRunsCommandsWithoutSudo(): void
    {
        $cmds = [];
        $runner = function (array $cmd) use (&$cmds) {
            $cmds[] = $cmd;
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'ufw')) {
                return ['code' => 0, 'out' => self::UFW];
            }
            if (str_contains($joined, 'sshd -T')) {
                return ['code' => 0, 'out' => self::SSHD];
            }
            if (str_contains($joined, 'fail2ban-client status')) {
                return ['code' => 0, 'out' => "`- Jail list:\t\n"];
            }
            return ['code' => 0, 'out' => "active\n"];
        };
        // useSudo:false is how the root collector runs — commands must be bare.
        $svc = new SecurityStatusService(true, $this->bins(), 6, $runner, 'sudo', useSudo: false, snapshotFile: '');
        $report = $svc->hostReport();

        self::assertNotSame([], $cmds);
        foreach ($cmds as $cmd) {
            self::assertNotContains('sudo', $cmd, 'collector (root) must not prefix sudo');
            self::assertNotSame('-n', $cmd[1] ?? '', 'no sudo -n when running as root');
        }
        self::assertSame('ok', $report['cards'][0]['state']); // firewall parsed from UFW
    }

    public function testFileModeReadsSnapshotWithoutRunningCommands(): void
    {
        $file = $this->tempSnapshot([
            'generated_at' => time(),         // fresh
            'cards' => [
                ['key' => 'firewall', 'label' => 'Firewall (ufw)', 'state' => 'ok', 'detail' => 'Active.', 'facts' => []],
            ],
            'jails' => [['name' => 'sshd', 'banned' => 1, 'total_banned' => 3, 'failed' => 0]],
            'bannedIps' => [['jail' => 'sshd', 'ip' => '203.0.113.7']],
        ]);
        $ran = false;
        $runner = function () use (&$ran) {
            $ran = true;
            return ['code' => 0, 'out' => ''];
        };
        $svc = new SecurityStatusService(true, $this->bins(), 6, $runner, 'sudo', useSudo: true, snapshotFile: $file);
        $snap = $svc->snapshot();

        self::assertFalse($ran, 'file mode must not execute any command');
        self::assertSame('file', $snap['source']);
        self::assertSame([['jail' => 'sshd', 'ip' => '203.0.113.7']], $snap['bannedIps']);
        // app card + fresh collector card + the one card from the file.
        self::assertSame('app_headers', $snap['cards'][0]['key']);
        $collector = $this->card($snap, 'collector');
        self::assertSame('ok', $collector['state']);
        self::assertSame('ok', $this->card($snap, 'firewall')['state']); // card came from the file
        @unlink($file);
    }

    public function testFileModeFlagsStaleSnapshot(): void
    {
        $file = $this->tempSnapshot([
            'generated_at' => time() - 5000,  // way past the default 600s max age
            'cards' => [], 'jails' => [], 'bannedIps' => [],
        ]);
        $svc = new SecurityStatusService(true, $this->bins(), 6, fn() => ['code' => 0, 'out' => ''], 'sudo', useSudo: true, snapshotFile: $file);
        $snap = $svc->snapshot();
        self::assertSame('warn', $this->card($snap, 'collector')['state']);
        @unlink($file);
    }

    public function testFileModeMissingFileShowsUnknownCollectorCard(): void
    {
        $svc = new SecurityStatusService(true, $this->bins(), 6, fn() => ['code' => 0, 'out' => ''], 'sudo', useSudo: true, snapshotFile: '/no/such/idm-snapshot.json');
        $snap = $svc->snapshot();
        self::assertSame('file', $snap['source']);
        self::assertSame('unknown', $this->card($snap, 'collector')['state']);
        self::assertSame([], $snap['bannedIps']);
    }

    public function testParseUfw(): void
    {
        $p = SecurityStatusService::parseUfw(self::UFW);
        self::assertTrue($p['active']);
        self::assertSame('deny', $p['default_incoming']);
        self::assertCount(3, $p['rules']);
        self::assertStringContainsString('22/tcp', $p['rules'][0]);
    }

    public function testParseFail2banJailsAndJail(): void
    {
        self::assertSame(['sshd', 'recidive'], SecurityStatusService::parseFail2banJails(self::JAILS));

        $j = SecurityStatusService::parseFail2banJail(self::JAIL_SSHD);
        self::assertSame(2, $j['banned']);
        self::assertSame(17, $j['total_banned']);
        self::assertSame(1, $j['failed']);
        self::assertSame(['203.0.113.7', '198.51.100.9'], $j['ips']);
    }

    public function testParseSshd(): void
    {
        $p = SecurityStatusService::parseSshd(self::SSHD);
        self::assertSame('22', $p['port']);
        self::assertSame('no', $p['permitrootlogin']);
        self::assertSame('no', $p['passwordauthentication']);
    }

    // ---- helpers --------------------------------------------------------------

    /** Write a snapshot JSON to a temp file and return its path. */
    private function tempSnapshot(array $data): string
    {
        $file = tempnam(sys_get_temp_dir(), 'idm-sec-');
        file_put_contents($file, json_encode($data));
        return $file;
    }

    /** @return array<string,string> */
    private function bins(): array
    {
        return [
            'ufw'       => '/usr/sbin/ufw',
            'fail2ban'  => '/usr/bin/fail2ban-client',
            'sshd'      => '/usr/sbin/sshd',
            'systemctl' => '/usr/bin/systemctl',
        ];
    }

    private function enabledService(string $ufw = self::UFW, string $sshd = self::SSHD): SecurityStatusService
    {
        $runner = function (array $cmd) use ($ufw, $sshd) {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'ufw')) {
                return ['code' => 0, 'out' => $ufw];
            }
            if (str_contains($joined, 'fail2ban-client status sshd')) {
                return ['code' => 0, 'out' => self::JAIL_SSHD];
            }
            if (str_contains($joined, 'fail2ban-client status recidive')) {
                return ['code' => 0, 'out' => "`- Banned IP list:\t\n|- Currently banned: 0\n"];
            }
            if (str_contains($joined, 'fail2ban-client status')) {
                return ['code' => 0, 'out' => self::JAILS];
            }
            if (str_contains($joined, 'sshd -T')) {
                return ['code' => 0, 'out' => $sshd];
            }
            if (str_contains($joined, 'is-active')) {
                return ['code' => 0, 'out' => "active\n"];
            }
            return ['code' => 1, 'out' => ''];
        };
        return new SecurityStatusService(true, $this->bins(), 6, $runner, 'sudo', useSudo: true, snapshotFile: '');
    }

    /** @param array{cards:list<array>} $snap */
    private function card(array $snap, string $key): array
    {
        foreach ($snap['cards'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        self::fail("card {$key} not found");
    }

    private function fact(array $card, string $label): ?string
    {
        foreach ($card['facts'] as [$k, $v]) {
            if ($k === $label) {
                return $v;
            }
        }
        return null;
    }
}
