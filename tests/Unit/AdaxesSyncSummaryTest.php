<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdaxesSyncSummary;
use PHPUnit\Framework\TestCase;

/**
 * AdaxesSyncSummary turns a service_run row (job='adaxes') into the Services-page
 * view model: per-phase counts, an "attention" total (errors + review), and the
 * count of changes applied. Pure — just parses counts_json.
 */
final class AdaxesSyncSummaryTest extends TestCase
{
    public function testNullWhenNeverRun(): void
    {
        self::assertNull(AdaxesSyncSummary::fromRun(null));
    }

    public function testBuildsPerPhaseSummaryFromCounts(): void
    {
        $counts = [
            'errors'        => 2,
            'write_enabled' => true,
            'phases'        => [
                'disable' => ['applied' => 3, 'noop' => 10, 'skipped' => 1, 'errors' => 0],
                'edit'    => ['applied' => 5, 'noop' => 40, 'errors' => 1],
                'create'  => ['applied' => 4, 'correlated' => 2, 'review' => 6, 'errors' => 1, 'capped' => 0],
                'groups'  => ['added' => 7, 'removed' => 2, 'noop' => 30, 'errors' => 0],
            ],
        ];
        $row = [
            'status' => 'failed', 'origin' => 'cron', 'actor' => 'system:adaxes_sync',
            'started_at' => '2026-07-14 04:30:00', 'finished_at' => '2026-07-14 04:41:00',
            'message' => 'phases disable,edit,create,groups · errors 2',
            'counts_json' => json_encode($counts),
        ];

        $s = AdaxesSyncSummary::fromRun($row);
        self::assertNotNull($s);
        self::assertSame('failed', $s['status']);
        self::assertTrue($s['writeEnabled']);
        self::assertSame(2, $s['errors']);
        // attention = top-level errors (2) + review across phases (create 6) = 8.
        self::assertSame(8, $s['attention']);
        // actions = applied/created/correlated/added/removed = 3+5+4+2+7+2 = 23.
        self::assertSame(23, $s['actions']);
        self::assertCount(4, $s['phases']);

        // 'applied' is relabelled per phase.
        $create = null;
        foreach ($s['phases'] as $p) {
            if ($p['key'] === 'create') {
                $create = $p;
            }
        }
        self::assertNotNull($create);
        $labels = array_column($create['cells'], 'label', 'key');
        self::assertSame('Created', $labels['applied']);       // create: applied → Created
        self::assertSame('Correlated', $labels['correlated']);
        self::assertSame('Needs review', $labels['review']);
        self::assertSame(1, $create['errors']);
        // A zero count (capped) is not shown.
        self::assertArrayNotHasKey('capped', $labels);
    }

    public function testToleratesMissingOrBadCountsJson(): void
    {
        $s = AdaxesSyncSummary::fromRun(['status' => 'complete', 'counts_json' => 'not json']);
        self::assertNotNull($s);
        self::assertSame([], $s['phases']);
        self::assertSame(0, $s['attention']);
    }
}
