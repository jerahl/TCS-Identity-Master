<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GoogleSyncSummary;
use PHPUnit\Framework\TestCase;

/**
 * GoogleSyncSummary turns a service_run row (job='google') into the Outputs-page
 * view model: an ordered list of non-zero counts, an "attention" total (errors +
 * license-blocked), and the count of changes applied. Pure — just parses
 * counts_json (the flat map written by bin/sync_google.php).
 */
final class GoogleSyncSummaryTest extends TestCase
{
    public function testNullWhenNeverRun(): void
    {
        self::assertNull(GoogleSyncSummary::fromRun(null));
    }

    public function testBuildsSummaryFromCounts(): void
    {
        $counts = [
            'eligible' => 120, 'created' => 4, 'pushed' => 6, 'suspended' => 3, 'moved' => 2,
            'licensed' => 5, 'unlicensed' => 1, 'license_blocked' => 2,
            'in_sync' => 90, 'no_email' => 3, 'no_account' => 4, 'manual_override' => 1, 'errors' => 2,
        ];
        $row = [
            'status' => 'failed', 'origin' => 'cron', 'actor' => 'system:google_sync',
            'started_at' => '2026-07-14 05:30:00', 'finished_at' => '2026-07-14 05:44:00',
            'message' => 'created 4 · pushed 6 · suspended 3 · errors 2',
            'counts_json' => json_encode($counts),
        ];

        $s = GoogleSyncSummary::fromRun($row);
        self::assertNotNull($s);
        self::assertSame('failed', $s['status']);
        self::assertSame(120, $s['eligible']);
        self::assertSame(2, $s['errors']);
        // attention = errors (2) + license_blocked (2) = 4.
        self::assertSame(4, $s['attention']);
        // actions = created 4 + pushed 6 + suspended 3 + moved 2 + licensed 5 + unlicensed 1 = 21.
        self::assertSame(21, $s['actions']);
        self::assertSame('2026-07-14 05:44:00', $s['when']);

        $labels = array_column($s['cells'], 'label', 'key');
        self::assertSame('Created', $labels['created']);
        self::assertSame('Name pushed', $labels['pushed']);
        self::assertSame('License blocked (no seat)', $labels['license_blocked']);
        // Order: created appears before errors.
        $keys = array_column($s['cells'], 'key');
        self::assertLessThan(array_search('errors', $keys, true), array_search('created', $keys, true));
    }

    public function testZeroCountsAreOmitted(): void
    {
        $counts = ['eligible' => 10, 'created' => 0, 'pushed' => 2, 'in_sync' => 8, 'errors' => 0];
        $s = GoogleSyncSummary::fromRun(['status' => 'complete', 'counts_json' => json_encode($counts)]);
        self::assertNotNull($s);
        $keys = array_column($s['cells'], 'key');
        self::assertContains('pushed', $keys);
        self::assertNotContains('created', $keys); // zero → not shown
        self::assertNotContains('errors', $keys);
        self::assertSame(2, $s['actions']);
        self::assertSame(0, $s['attention']);
    }

    public function testToleratesMissingOrBadCountsJson(): void
    {
        $s = GoogleSyncSummary::fromRun(['status' => 'complete', 'counts_json' => 'not json']);
        self::assertNotNull($s);
        self::assertSame([], $s['cells']);
        self::assertSame(0, $s['attention']);
        self::assertSame(0, $s['actions']);
        self::assertSame(0, $s['eligible']);
    }
}
