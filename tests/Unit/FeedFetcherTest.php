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

    /** @return array<int,array{name:string,size:?int,mtime:?int}> */
    private function meta(array $namesToMtime): array
    {
        $out = [];
        foreach ($namesToMtime as $name => $mtime) {
            $out[] = ['name' => $name, 'size' => null, 'mtime' => $mtime];
        }
        return $out;
    }

    /** @return string[] just the selected names, for convenient asserting */
    private function names(array $plan): array
    {
        return array_map(static fn($f) => $f['name'], $plan);
    }

    public function testPlanFiltersPatternAndAlreadyFetched(): void
    {
        $remote = $this->meta([
            'staff_20260625.csv' => 1000,
            'staff_20260626.csv' => 1000,
            'README.txt' => 1000,
            'old.CSV' => 1000,
        ]);
        $new = $this->names(FeedFetcher::plan($remote, ['staff_20260625.csv' => 1000], '*.csv'));

        self::assertContains('staff_20260626.csv', $new);
        self::assertContains('old.CSV', $new, 'pattern match is case-insensitive');
        self::assertNotContains('staff_20260625.csv', $new, 'already fetched, unchanged, skipped');
        self::assertNotContains('README.txt', $new, 'non-csv skipped');
    }

    public function testPlanRefetchesWhenRemoteIsNewer(): void
    {
        // Same name overwritten in place with a newer mtime -> re-fetch.
        $remote = $this->meta(['users.csv' => 2000]);
        self::assertSame(['users.csv'], $this->names(FeedFetcher::plan($remote, ['users.csv' => 1000])));
    }

    public function testPlanSkipsWhenRemoteNotNewer(): void
    {
        // Unchanged (same mtime) or older -> skip.
        $remote = $this->meta(['users.csv' => 1000]);
        self::assertSame([], FeedFetcher::plan($remote, ['users.csv' => 1000]));
        $older = $this->meta(['users.csv' => 500]);
        self::assertSame([], FeedFetcher::plan($older, ['users.csv' => 1000]));
    }

    public function testPlanUnknownMtimeFallsBackToNameOnce(): void
    {
        // Server reports no mtime: fetch the first time, then never again.
        $remote = $this->meta(['users.csv' => null]);
        self::assertSame(['users.csv'], $this->names(FeedFetcher::plan($remote, [])));
        self::assertSame([], FeedFetcher::plan($remote, ['users.csv' => null]));
    }

    public function testFetchSourceDownloadsNewAndUpdatedFiles(): void
    {
        $client = new InMemorySftpClient(
            ['/outbound/nextgen' => [
                'a.csv' => "EmployeeID\n1",
                'b.csv' => "EmployeeID\n2",
                'notes.txt' => 'ignore',
            ]],
            ['/outbound/nextgen' => ['a.csv' => 2000, 'b.csv' => 1000]],
        );
        $fetcher = new FeedFetcher($client);

        // a.csv already fetched at mtime 1000 but remote is now 2000 -> re-download.
        // b.csv already fetched at its current mtime -> skip.
        $res = $fetcher->fetchSource('/outbound/nextgen', '*.csv', $this->tmp, ['a.csv' => 1000, 'b.csv' => 1000], false);

        self::assertCount(1, $res, 'only the updated a.csv');
        self::assertSame('a.csv', $res[0]['name']);
        self::assertSame(2000, $res[0]['mtime']);
        self::assertTrue($res[0]['downloaded']);
        self::assertFileExists($this->tmp . '/a.csv');
        self::assertFileDoesNotExist($this->tmp . '/b.csv');
    }

    public function testDryRunDownloadsNothing(): void
    {
        $client = new InMemorySftpClient(['/d' => ['x.csv' => 'EmployeeID']], ['/d' => ['x.csv' => 1000]]);
        $res = (new FeedFetcher($client))->fetchSource('/d', '*.csv', $this->tmp, [], true);

        self::assertCount(1, $res);
        self::assertFalse($res[0]['downloaded']);
        self::assertFileDoesNotExist($this->tmp . '/x.csv');
    }
}
