<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sync\Freshness;
use PHPUnit\Framework\TestCase;

final class FreshnessTest extends TestCase
{
    private const NOW = 1_700_000_000; // fixed "now" for determinism

    public function testNeverWhenNull(): void
    {
        $r = Freshness::classify(null, 26, self::NOW);
        self::assertSame(Freshness::NEVER, $r['state']);
        self::assertSame('never', $r['label']);
    }

    public function testFreshWithinThreshold(): void
    {
        $at = date('Y-m-d H:i:s', self::NOW - 3600); // 1h ago
        $r = Freshness::classify($at, 26, self::NOW);
        self::assertSame(Freshness::FRESH, $r['state']);
    }

    public function testStaleBeyondThreshold(): void
    {
        $at = date('Y-m-d H:i:s', self::NOW - 48 * 3600); // 2 days ago
        $r = Freshness::classify($at, 26, self::NOW);
        self::assertSame(Freshness::STALE, $r['state']);
        self::assertSame('2d ago', $r['label']);
    }

    public function testBoundaryIsFreshAtExactlyThreshold(): void
    {
        $at = date('Y-m-d H:i:s', self::NOW - 26 * 3600);
        self::assertSame(Freshness::FRESH, Freshness::classify($at, 26, self::NOW)['state']);
    }

    public function testAgoLabels(): void
    {
        self::assertSame('just now', Freshness::ago(10));
        self::assertSame('5m ago', Freshness::ago(300));
        self::assertSame('3h ago', Freshness::ago(3 * 3600));
        self::assertSame('2d ago', Freshness::ago(50 * 3600));
    }
}
