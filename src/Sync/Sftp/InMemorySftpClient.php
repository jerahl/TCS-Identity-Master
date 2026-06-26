<?php

declare(strict_types=1);

namespace App\Sync\Sftp;

use RuntimeException;

/**
 * In-memory SFTP fake for tests: seed it with directories → {filename: contents},
 * and download() writes the contents to the local path.
 */
final class InMemorySftpClient implements SftpClient
{
    /** @param array<string,array<string,string>> $tree dir => [name => contents] */
    public function __construct(private array $tree = [])
    {
    }

    public function connect(): void
    {
        // no-op
    }

    public function listFiles(string $dir): array
    {
        $dir = rtrim($dir, '/');
        return array_keys($this->tree[$dir] ?? []);
    }

    public function download(string $remotePath, string $localPath): int
    {
        $dir = rtrim(dirname($remotePath), '/');
        $name = basename($remotePath);
        if (!isset($this->tree[$dir][$name])) {
            throw new RuntimeException("No such remote file: {$remotePath}");
        }
        $bytes = file_put_contents($localPath, $this->tree[$dir][$name]);
        if ($bytes === false) {
            throw new RuntimeException("Cannot write local file: {$localPath}");
        }
        return $bytes;
    }
}
