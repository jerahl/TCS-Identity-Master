<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\DashboardService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * DashboardService::directSyncHealth — the AD/Google dashboard tiles read their
 * last run from service_run (the same rows the CLI/web syncs write), and pass a
 * `configured` flag through so a tile can say "off" instead of "never run".
 */
final class DashboardDirectSyncHealthTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('CREATE TABLE service_run (
            run_id INTEGER PRIMARY KEY, job TEXT, origin TEXT, actor TEXT, status TEXT,
            counts_json TEXT, message TEXT, started_at TEXT, finished_at TEXT)');
        return $db;
    }

    public function testNeverRunReportsNeverButKeepsConfiguredFlag(): void
    {
        $h = (new DashboardService($this->db()))->directSyncHealth('adaxes', true);

        self::assertSame('never', $h['state']);
        self::assertNull($h['status']);
        self::assertTrue($h['configured']);
    }

    public function testReadsLastRunStatusAndCounts(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO service_run (job, origin, actor, status, counts_json, message, started_at, finished_at)
                   VALUES ('google', 'cron', 'system', 'complete', '{\"created\":2,\"pushed\":3,\"errors\":0}', 'ok',
                           '2026-07-10 08:00:00', '2026-07-10 08:01:00')");

        $h = (new DashboardService($db))->directSyncHealth('google', true);

        self::assertSame('complete', $h['status']);
        self::assertSame(2, (int) $h['counts']['created']);
        self::assertSame(3, (int) $h['counts']['pushed']);
        self::assertTrue($h['configured']);
    }

    public function testConfiguredFalsePassesThrough(): void
    {
        $h = (new DashboardService($this->db()))->directSyncHealth('adaxes', false);
        self::assertFalse($h['configured']);
    }
}
