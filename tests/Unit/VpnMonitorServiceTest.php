<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\VpnMonitorService;
use PHPUnit\Framework\TestCase;

/**
 * The VPN status page client: it must relay the monitor's JSON when reachable and
 * degrade to a clear ok=false envelope (never throw) when the monitor is
 * unconfigured, unreachable, or returns junk. The HTTP fetch is injected so no
 * live monitor is needed.
 */
final class VpnMonitorServiceTest extends TestCase
{
    public function testToneMapsToBadgeModifiers(): void
    {
        self::assertSame('active', VpnMonitorService::tone('ok'));
        self::assertSame('pending', VpnMonitorService::tone('warn'));
        self::assertSame('terminated', VpnMonitorService::tone('down'));
        self::assertSame('disabled', VpnMonitorService::tone('unknown'));
        self::assertSame('disabled', VpnMonitorService::tone('bogus'));
    }

    public function testNotConfiguredReturnsErrorEnvelope(): void
    {
        $svc = new VpnMonitorService('', 4, fn() => null);
        self::assertFalse($svc->configured());
        $res = $svc->snapshot();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('VPN_MONITOR_URL', $res['error']);
        self::assertNull($res['data']);
    }

    public function testUnreachableMonitor(): void
    {
        $svc = new VpnMonitorService('http://mon:8787', 4, fn() => null);
        $res = $svc->snapshot();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('unreachable', $res['error']);
    }

    public function testInvalidJson(): void
    {
        $svc = new VpnMonitorService('http://mon:8787', 4, fn() => '<html>nope</html>');
        $res = $svc->snapshot();
        self::assertFalse($res['ok']);
        self::assertStringContainsString('invalid JSON', $res['error']);
    }

    public function testSnapshotOkPassesThroughDecodedData(): void
    {
        $captured = null;
        $fetch = function (string $url) use (&$captured): string {
            $captured = $url;
            return json_encode(['overall' => 'ok', 'signals' => ['tunnel' => ['status' => 'ok']]]);
        };
        $svc = new VpnMonitorService('http://mon:8787/', 4, $fetch);

        $res = $svc->snapshot();
        self::assertTrue($res['ok']);
        self::assertSame('ok', $res['data']['overall']);
        // Trailing slash on the base URL is normalized; path is appended cleanly.
        self::assertSame('http://mon:8787/api/status', $captured);
    }

    public function testHistoryUsesHistoryEndpoint(): void
    {
        $captured = null;
        $svc = new VpnMonitorService('http://mon:8787', 4, function (string $url) use (&$captured): string {
            $captured = $url;
            return json_encode(['enabled' => true, 'summary' => ['uptime_pct' => 99.9]]);
        });
        $res = $svc->history();
        self::assertTrue($res['ok']);
        self::assertSame('http://mon:8787/api/history', $captured);
        self::assertTrue($res['data']['enabled']);
    }
}
