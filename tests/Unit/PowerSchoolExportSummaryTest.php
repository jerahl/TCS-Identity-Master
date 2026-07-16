<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PowerSchoolExportSummary;
use PHPUnit\Framework\TestCase;

/**
 * PowerSchoolExportSummary turns a service_run row (job='ps_export') into the
 * Outputs-page view model: shipped counts (new/changed/rows), the exceptions
 * total driving the "requires attention" tile, and the upload flag. Pure —
 * just parses counts_json (written by bin/export_powerschool.php).
 */
final class PowerSchoolExportSummaryTest extends TestCase
{
    public function testNullWhenNeverRun(): void
    {
        self::assertNull(PowerSchoolExportSummary::fromRun(null));
    }

    public function testBuildsSummaryFromCounts(): void
    {
        $counts = ['new' => 3, 'changed' => 5, 'rows' => 11, 'schools' => 4, 'exceptions' => 2, 'uploaded' => 1];
        $row = [
            'status' => 'complete', 'origin' => 'cron', 'actor' => 'system:export_powerschool',
            'started_at' => '2026-07-15 05:00:00', 'finished_at' => '2026-07-15 05:01:00',
            'message' => 'new 3 · changed 5 · rows 11 · exceptions 2 · uploaded',
            'counts_json' => json_encode($counts),
        ];

        $s = PowerSchoolExportSummary::fromRun($row);
        self::assertNotNull($s);
        self::assertSame('complete', $s['status']);
        self::assertSame('2026-07-15 05:01:00', $s['when']);
        self::assertSame(11, $s['rows']);
        self::assertSame(2, $s['exceptions']);
        self::assertSame(2, $s['attention']);
        // exported = new 3 + changed 5.
        self::assertSame(8, $s['exported']);
        self::assertTrue($s['uploaded']);

        $labels = array_column($s['cells'], 'label', 'key');
        self::assertSame('New users', $labels['new']);
        self::assertSame('Exceptions (held back)', $labels['exceptions']);
    }

    public function testZeroCountsAreOmittedAndUploadDefaultsOff(): void
    {
        $counts = ['new' => 0, 'changed' => 2, 'rows' => 2, 'schools' => 1, 'exceptions' => 0];
        $s = PowerSchoolExportSummary::fromRun(['status' => 'failed', 'counts_json' => json_encode($counts)]);
        self::assertNotNull($s);
        self::assertFalse($s['uploaded']);
        self::assertSame(0, $s['attention']);
        $keys = array_column($s['cells'], 'key');
        self::assertNotContains('new', $keys);
        self::assertNotContains('exceptions', $keys);
        self::assertContains('changed', $keys);
    }

    public function testMalformedCountsJsonYieldsZeros(): void
    {
        $s = PowerSchoolExportSummary::fromRun(['status' => 'complete', 'counts_json' => '{nope']);
        self::assertNotNull($s);
        self::assertSame(0, $s['rows']);
        self::assertSame([], $s['cells']);
    }
}
