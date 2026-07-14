<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GoogleRunService;
use PHPUnit\Framework\TestCase;

/**
 * GoogleRunService — the "Run Google sync now" trigger. Like AdaxesRunService it
 * must stay off unless enabled, build a shell-free `systemctl start --no-block`
 * argv, refuse a bogus unit name, and degrade to an ok=false envelope (never throw)
 * on failure. The command runner is injected so nothing actually shells out.
 */
final class GoogleRunServiceTest extends TestCase
{
    public function testDisabledByDefaultRefusesToRun(): void
    {
        $ran = false;
        $svc = new GoogleRunService(false, 'idm-google-sync.service', 15, function () use (&$ran) {
            $ran = true;
            return ['code' => 0, 'out' => ''];
        });

        self::assertFalse($svc->enabled());
        $res = $svc->start();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('GOOGLE_RUN_ENABLED', (string) $res['error']);
        self::assertFalse($ran, 'runner must not be invoked when disabled');
    }

    public function testStartCommandIsShellFreeNoBlockArgv(): void
    {
        $svc = new GoogleRunService(true, 'idm-google-sync.service', 15, fn() => ['code' => 0, 'out' => '']);
        self::assertSame(
            ['sudo', '-n', 'systemctl', 'start', '--no-block', 'idm-google-sync.service'],
            $svc->startCommand()
        );
    }

    public function testStartSucceedsWhenRunnerExitsZero(): void
    {
        $svc = new GoogleRunService(true, 'idm-google-sync.service', 15, fn() => ['code' => 0, 'out' => '']);
        $res = $svc->start();
        self::assertTrue($res['ok']);
        self::assertNull($res['error']);
    }

    public function testStartFailureSurfacesHintAboutSudoers(): void
    {
        $svc = new GoogleRunService(true, 'idm-google-sync.service', 15, fn() => ['code' => 1, 'out' => '']);
        $res = $svc->start();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('sudoers', (string) $res['error']);
    }

    public function testRefusesInvalidUnitName(): void
    {
        $ran = false;
        $svc = new GoogleRunService(true, 'bad unit; rm -rf /', 15, function () use (&$ran) {
            $ran = true;
            return ['code' => 0, 'out' => ''];
        });
        $res = $svc->start();
        self::assertFalse($res['ok']);
        self::assertFalse($ran, 'must not run with an invalid unit name');
    }

    public function testNeverThrowsWhenRunnerThrows(): void
    {
        $svc = new GoogleRunService(true, 'idm-google-sync.service', 15, function () {
            throw new \RuntimeException('proc_open failed');
        });
        $res = $svc->start();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('Could not execute', (string) $res['error']);
    }
}
