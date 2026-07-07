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
    /**
     * @param array<string,array<string,string>> $tree   dir => [name => contents]
     * @param array<string,array<string,int>>    $mtimes dir => [name => unix mtime]
     */
    public function __construct(private array $tree = [], private array $mtimes = [])
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

    public function listFilesWithMeta(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $out = [];
        foreach ($this->tree[$dir] ?? [] as $name => $contents) {
            $out[] = [
                'name'  => $name,
                'size'  => strlen($contents),
                'mtime' => $this->mtimes[$dir][$name] ?? null,
            ];
        }
        return $out;
    }

    public function upload(string $localPath, string $remotePath): int
    {
        $contents = @file_get_contents($localPath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read local file: {$localPath}");
        }
        $dir = rtrim(dirname($remotePath), '/');
        $this->tree[$dir][basename($remotePath)] = $contents;
        return strlen($contents);
    }

    /** Contents of an uploaded file, for test assertions (null if absent). */
    public function uploaded(string $remotePath): ?string
    {
        $dir = rtrim(dirname($remotePath), '/');
        return $this->tree[$dir][basename($remotePath)] ?? null;
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
