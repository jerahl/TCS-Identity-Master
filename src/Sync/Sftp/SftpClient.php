<?php

declare(strict_types=1);

namespace App\Sync\Sftp;

/**
 * Minimal SFTP port the feed fetcher needs. An interface so the fetcher can be
 * unit-tested with an in-memory fake; production uses PhpseclibSftpClient.
 */
interface SftpClient
{
    /** Connect + authenticate (and verify the host key if configured). */
    public function connect(): void;

    /** @return string[] file names in $dir (no '.'/'..', no directories) */
    public function listFiles(string $dir): array;

    /**
     * Like listFiles() but with metadata, so the fetcher can re-download files
     * that changed in place (same name, newer mtime).
     *
     * @return array<int,array{name:string,size:?int,mtime:?int}>
     */
    public function listFilesWithMeta(string $dir): array;

    /** Download $remotePath to $localPath; returns bytes written. */
    public function download(string $remotePath, string $localPath): int;
}
