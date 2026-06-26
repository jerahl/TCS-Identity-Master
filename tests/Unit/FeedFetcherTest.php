<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sync\FeedFetcher;
use App\Sync\Sftp\InMemorySftpClient;
use PHPUnit\Framework\TestCase;

final class FeedFetcherTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/idm_fetch_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testPlanFiltersPatternAndAlreadyFetched(): void
    {
        $remote = ['staff_20260625.csv', 'staff_20260626.csv', 'README.txt', 'old.CSV'];
        $new = FeedFetcher::plan($remote, ['staff_20260625.csv'], '*.csv');

        self::assertContains('staff_20260626.csv', $new);
        self::assertContains('old.CSV', $new, 'pattern match is case-insensitive');
        self::assertNotContains('staff_20260625.csv', $new, 'already fetched skipped');
        self::assertNotContains('README.txt', $new, 'non-csv skipped');
    }

    public function testFetchSourceDownloadsOnlyNewFiles(): void
    {
        $client = new InMemorySftpClient([
            '/outbound/nextgen' => [
                'a.csv' => "EmployeeID\n1",
                'b.csv' => "EmployeeID\n2",
                'notes.txt' => 'ignore',
            ],
        ]);
        $fetcher = new FeedFetcher($client);

        $res = $fetcher->fetchSource('/outbound/nextgen', '*.csv', $this->tmp, ['a.csv'], false);

        self::assertCount(1, $res, 'only b.csv is new');
        self::assertSame('b.csv', $res[0]['name']);
        self::assertTrue($res[0]['downloaded']);
        self::assertFileExists($this->tmp . '/b.csv');
        self::assertFileDoesNotExist($this->tmp . '/a.csv');
    }

    public function testDryRunDownloadsNothing(): void
    {
        $client = new InMemorySftpClient(['/d' => ['x.csv' => 'EmployeeID']]);
        $res = (new FeedFetcher($client))->fetchSource('/d', '*.csv', $this->tmp, [], true);

        self::assertCount(1, $res);
        self::assertFalse($res[0]['downloaded']);
        self::assertFileDoesNotExist($this->tmp . '/x.csv');
    }
}
