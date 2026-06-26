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
     * Pure: remote files matching $pattern that haven't been fetched yet.
     *
     * @param string[] $remoteFiles
     * @param string[] $alreadyFetched
     * @return string[]
     */
    public static function plan(array $remoteFiles, array $alreadyFetched, string $pattern = '*.csv'): array
    {
        $seen = array_flip($alreadyFetched);
        $out = [];
        foreach ($remoteFiles as $name) {
            if (!fnmatch($pattern, $name, FNM_CASEFOLD)) {
                continue;
            }
            if (isset($seen[$name])) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * List a remote dir, download new matching files to $localDir.
     *
     * @param string[] $alreadyFetched
     * @return array<int,array{name:string,local:string,size:?int,downloaded:bool}>
     */
    public function fetchSource(string $remoteDir, string $pattern, string $localDir, array $alreadyFetched, bool $dryRun = false): array
    {
        $remote = $this->client->listFiles($remoteDir);
        $new = self::plan($remote, $alreadyFetched, $pattern);

        if ($new !== [] && !$dryRun && !is_dir($localDir) && !@mkdir($localDir, 0750, true) && !is_dir($localDir)) {
            throw new RuntimeException("Cannot create local feed dir: {$localDir}");
        }

        $results = [];
        foreach ($new as $name) {
            $remotePath = rtrim($remoteDir, '/') . '/' . $name;
            $local = rtrim($localDir, '/') . '/' . $name;
            if ($dryRun) {
                $results[] = ['name' => $name, 'local' => $local, 'size' => null, 'downloaded' => false];
                continue;
            }
            $size = $this->client->download($remotePath, $local);
            $results[] = ['name' => $name, 'local' => $local, 'size' => $size, 'downloaded' => true];
        }
        return $results;
    }
}
