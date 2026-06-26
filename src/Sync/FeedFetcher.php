<?php

declare(strict_types=1);

namespace App\Sync;

use App\Sync\Sftp\SftpClient;
use RuntimeException;

/**
 * Downloads feed CSVs from SFTP into local feed directories. The "which files
 * are new" decision is a pure, testable function (plan); fetchSource() performs
 * the downloads via the injected SftpClient.
 */
final class FeedFetcher
{
    public function __construct(private readonly SftpClient $client)
    {
    }

    /**
     * Pure: which remote files to (re-)download. A file is selected when it is
     * new OR its remote mtime is newer than the last-fetched mtime — the feeds are
     * overwritten in place (same name) on each update, so name alone isn't enough.
     *
     * When the server doesn't report an mtime we fall back to name-only dedupe
     * (fetch once) to avoid re-downloading the same file on every run.
     *
     * @param array<int,array{name:string,size?:?int,mtime?:?int}> $remoteFiles
     * @param array<string,?int> $fetchedMtimes  name => last fetched mtime (or null)
     * @return array<int,array{name:string,size:?int,mtime:?int}>
     */
    public static function plan(array $remoteFiles, array $fetchedMtimes, string $pattern = '*.csv'): array
    {
        $out = [];
        foreach ($remoteFiles as $f) {
            $name = $f['name'];
            if (!fnmatch($pattern, $name, FNM_CASEFOLD)) {
                continue;
            }
            $mtime = $f['mtime'] ?? null;
            $seen = array_key_exists($name, $fetchedMtimes);
            $lastMtime = $seen ? $fetchedMtimes[$name] : null;

            if (!$seen) {
                $out[] = ['name' => $name, 'size' => $f['size'] ?? null, 'mtime' => $mtime];
                continue;
            }
            // Seen before: re-fetch only if we can prove the remote is newer.
            if ($mtime !== null && ($lastMtime === null || $mtime > $lastMtime)) {
                $out[] = ['name' => $name, 'size' => $f['size'] ?? null, 'mtime' => $mtime];
            }
        }
        return $out;
    }

    /**
     * List a remote dir, download new/updated matching files to $localDir.
     *
     * @param array<string,?int> $fetchedMtimes  name => last fetched mtime (or null)
     * @return array<int,array{name:string,local:string,size:?int,mtime:?int,downloaded:bool}>
     */
    public function fetchSource(string $remoteDir, string $pattern, string $localDir, array $fetchedMtimes, bool $dryRun = false): array
    {
        $remote = $this->client->listFilesWithMeta($remoteDir);
        $new = self::plan($remote, $fetchedMtimes, $pattern);

        if ($new !== [] && !$dryRun && !is_dir($localDir) && !@mkdir($localDir, 0750, true) && !is_dir($localDir)) {
            throw new RuntimeException("Cannot create local feed dir: {$localDir}");
        }

        $results = [];
        foreach ($new as $f) {
            $name = $f['name'];
            $remotePath = rtrim($remoteDir, '/') . '/' . $name;
            $local = rtrim($localDir, '/') . '/' . $name;
            if ($dryRun) {
                $results[] = ['name' => $name, 'local' => $local, 'size' => $f['size'], 'mtime' => $f['mtime'], 'downloaded' => false];
                continue;
            }
            $size = $this->client->download($remotePath, $local);
            $results[] = ['name' => $name, 'local' => $local, 'size' => $size, 'mtime' => $f['mtime'], 'downloaded' => true];
        }
        return $results;
    }
}
