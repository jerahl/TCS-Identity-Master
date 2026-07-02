<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\VpnControlService;
use PHPUnit\Framework\TestCase;

/**
 * The one VPN write path: restart the systemd unit. It must stay off unless
 * explicitly enabled, build a shell-free argv, refuse a bogus unit name, and
 * degrade to an ok=false envelope (never throw) when systemctl fails. The command
 * runner is injected so nothing actually shells out under test.
 */
final class VpnControlServiceTest extends TestCase
{
    public function testDisabledByDefaultRefusesToRun(): void
    {
        $ran = false;
        $svc = new VpnControlService(false, 'openconnect-pseast.service', 30, function () use (&$ran) {
            $ran = true;
            return ['code' => 0, 'out' => ''];
        });

        self::assertFalse($svc->enabled());
        $res = $svc->restart();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('VPN_CONTROL_ENABLED', $res['error']);
        self::assertFalse($ran, 'runner must not be invoked when disabled');
    }

    public function testRestartCommandIsShellFreeArgv(): void
    {
        $svc = new VpnControlService(true, 'openconnect-pseast.service', 30, fn() => ['code' => 0, 'out' => '']);
        self::assertSame(
            ['sudo', '-n', 'systemctl', 'restart', 'openconnect-pseast.service'],
            $svc->restartCommand()
        );
    }

    public function testSuccessfulRestartReturnsOk(): void
    {
        $captured = null;
        $svc = new VpnControlService(true, 'openconnect-pseast.service', 30, function (array $cmd) use (&$captured) {
            $captured = $cmd;
            return ['code' => 0, 'out' => ''];
        });

        $res = $svc->restart();
        self::assertTrue($res['ok']);
        self::assertNull($res['error']);
        self::assertContains('restart', $captured);
        self::assertContains('openconnect-pseast.service', $captured);
    }

    public function testNonZeroExitSurfacesAsFailureWithOutput(): void
    {
        $svc = new VpnControlService(true, 'openconnect-pseast.service', 30, fn() => [
            'code' => 1,
            'out'  => 'sudo: a password is required',
        ]);

        $res = $svc->restart();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('password is required', $res['error']);
    }

    public function testRunnerThrowingDegradesGracefully(): void
    {
        $svc = new VpnControlService(true, 'openconnect-pseast.service', 30, function () {
            throw new \RuntimeException('proc_open failed');
        });

        $res = $svc->restart();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('Could not execute', $res['error']);
    }

    public function testInvalidUnitIsRejectedBeforeRunning(): void
    {
        $ran = false;
        $svc = new VpnControlService(true, 'evil; rm -rf /', 30, function () use (&$ran) {
            $ran = true;
            return ['code' => 0, 'out' => ''];
        });

        $res = $svc->restart();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('invalid service unit', $res['error']);
        self::assertFalse($ran);
    }

    public function testIsValidUnit(): void
    {
        self::assertTrue(VpnControlService::isValidUnit('openconnect-pseast.service'));
        self::assertTrue(VpnControlService::isValidUnit('openvpn@client.service'));
        self::assertFalse(VpnControlService::isValidUnit(''));
        self::assertFalse(VpnControlService::isValidUnit('foo bar'));
        self::assertFalse(VpnControlService::isValidUnit('foo;bar'));
        self::assertFalse(VpnControlService::isValidUnit('foo && reboot'));
    }
}
