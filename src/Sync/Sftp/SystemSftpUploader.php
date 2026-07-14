<?php

declare(strict_types=1);

namespace App\Sync\Sftp;

use App\Config;
use RuntimeException;

/**
 * Uploads files by shelling out to the system OpenSSH `sftp` client in batch
 * mode — no PHP extensions or Composer deps, key-based auth ONLY (BatchMode
 * refuses password prompts; nothing secret ever appears on the command line).
 *
 * Uploads are atomic from the consumer's point of view: the file goes up under
 * a dot-prefixed temporary name and is renamed to its fixed name only once the
 * transfer completed, so PowerSchool's AutoComm can never pick up a
 * half-written file. OpenSSH's `rename` uses the posix-rename extension where
 * the server offers it (atomic overwrite); when the server refuses to rename
 * onto an existing file we fall back to rm + rename and report that the swap
 * was not atomic.
 *
 * Connection settings come from the same SFTP_* config the feed pull uses
 * (SFTP_HOST / SFTP_PORT / SFTP_USER / SFTP_PRIVATE_KEY_FILE). Host keys are
 * verified against the system known_hosts, or PS_STAFF_SFTP_KNOWN_HOSTS if
 * set — StrictHostKeyChecking is never disabled.
 */
final class SystemSftpUploader
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $keyFile,
        private readonly ?string $knownHostsFile = null,
        private readonly string $sftpBinary = 'sftp',
    ) {
    }

    /** Build an uploader from the SFTP_* environment config; throws if incomplete. */
    public static function fromConfig(): self
    {
        $host = trim((string) Config::get('SFTP_HOST', ''));
        $user = trim((string) Config::get('SFTP_USER', ''));
        $keyFile = trim((string) Config::get('SFTP_PRIVATE_KEY_FILE', ''));
        if ($host === '' || $user === '') {
            throw new RuntimeException('SFTP_HOST / SFTP_USER are not configured.');
        }
        if ($keyFile === '' || !is_file($keyFile) || !is_readable($keyFile)) {
            throw new RuntimeException('SFTP_PRIVATE_KEY_FILE is not set or not readable — the staff export uploads with key auth only.');
        }
        $known = trim((string) Config::get('PS_STAFF_SFTP_KNOWN_HOSTS', ''));
        return new self($host, (int) Config::get('SFTP_PORT', '22'), $user, $keyFile, $known !== '' ? $known : null);
    }

    /**
     * Upload $localPath to $remoteDir/$remoteName via a temporary name + rename.
     * Returns true when the swap was atomic (single rename), false when the
     * rm+rename fallback had to be used. Throws on any failure.
     */
    public function uploadAtomic(string $localPath, string $remoteDir, string $remoteName): bool
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("Local file missing: {$localPath}");
        }
        $dir = rtrim($remoteDir, '/');
        $tmp = "{$dir}/.{$remoteName}.tmp";
        $final = "{$dir}/{$remoteName}";

        // Preferred path: put + rename in one session (posix-rename overwrites).
        $r = $this->batch([
            'put ' . self::q($localPath) . ' ' . self::q($tmp),
            'rename ' . self::q($tmp) . ' ' . self::q($final),
        ]);
        if ($r['exit'] === 0) {
            return true;
        }

        // Fallback: some servers refuse rename onto an existing file. Re-put,
        // remove the old file, then rename — a small non-atomic window, which
        // the caller reports. '-rm' ignores a missing target.
        $r2 = $this->batch([
            'put ' . self::q($localPath) . ' ' . self::q($tmp),
            '-rm ' . self::q($final),
            'rename ' . self::q($tmp) . ' ' . self::q($final),
        ]);
        if ($r2['exit'] === 0) {
            return false;
        }
        throw new RuntimeException(sprintf(
            'sftp upload of %s failed (exit %d): %s',
            $remoteName, $r2['exit'], trim($r2['stderr'] . ' ' . $r['stderr'])
        ));
    }

    /**
     * Size of a remote file in bytes (post-upload verification), or null when
     * it does not exist / the listing could not be parsed.
     */
    public function remoteSize(string $remotePath): ?int
    {
        $r = $this->batch(['ls -l ' . self::q($remotePath)]);
        if ($r['exit'] !== 0) {
            return null;
        }
        // Expected long-listing line: -rw-r--r-- 1 user group 12345 Jul 14 01:00 /dir/name
        $base = basename($remotePath);
        foreach (preg_split('/\r\n|\n/', $r['stdout']) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 9 && basename(end($parts)) === $base && ctype_digit($parts[4])) {
                return (int) $parts[4];
            }
        }
        return null;
    }

    /**
     * Run one sftp batch (commands fed on stdin via `-b -`; sftp aborts on the
     * first failed command and exits non-zero).
     *
     * @param list<string> $commands
     * @return array{exit:int, stdout:string, stderr:string}
     */
    private function batch(array $commands): array
    {
        $argv = [
            $this->sftpBinary,
            '-b', '-',
            '-P', (string) $this->port,
            '-i', $this->keyFile,
            '-o', 'BatchMode=yes',
            '-o', 'PasswordAuthentication=no',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'ConnectTimeout=30',
        ];
        if ($this->knownHostsFile !== null) {
            $argv[] = '-o';
            $argv[] = 'UserKnownHostsFile=' . $this->knownHostsFile;
        }
        $argv[] = $this->user . '@' . $this->host;

        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot start the sftp client — is OpenSSH installed?');
        }
        fwrite($pipes[0], implode("\n", $commands) . "\n");
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Quote a path for an sftp batch line; refuses characters that would break out of it. */
    private static function q(string $path): string
    {
        if (preg_match('/["\r\n]/', $path)) {
            throw new RuntimeException("Unsafe character in sftp path: {$path}");
        }
        return '"' . $path . '"';
    }
}
