<?php

declare(strict_types=1);

namespace App\Sync\Sftp;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * SFTP client backed by phpseclib (pure PHP — no ext-ssh2 needed).
 *
 * Auth by private key (preferred) or password. If a host-key fingerprint is
 * configured it is verified before authenticating (prevents MITM); set
 * SFTP_FINGERPRINT to the server's `SHA256:...` fingerprint.
 */
final class PhpseclibSftpClient implements SftpClient
{
    private ?SFTP $sftp = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 22,
        private readonly string $user = '',
        private readonly ?string $password = null,
        private readonly ?string $privateKey = null,    // key contents (PEM/OpenSSH)
        private readonly ?string $passphrase = null,
        private readonly ?string $fingerprint = null,   // expected SHA256:... host key fp
    ) {
        if (!class_exists(SFTP::class)) {
            throw new RuntimeException('phpseclib is not installed (composer require phpseclib/phpseclib).');
        }
    }

    public function connect(): void
    {
        if ($this->host === '' || $this->user === '') {
            throw new RuntimeException('SFTP host and user are required (set SFTP_HOST / SFTP_USER).');
        }
        $sftp = new SFTP($this->host, $this->port);

        if ($this->fingerprint !== null && trim($this->fingerprint) !== '') {
            $hostKey = $sftp->getServerPublicHostKey();
            if ($hostKey === false) {
                throw new RuntimeException('Could not read SFTP host key for verification.');
            }
            $actual = self::sha256Fingerprint($hostKey);
            $expected = self::normalizeFingerprint($this->fingerprint);
            if (!hash_equals($expected, $actual)) {
                throw new RuntimeException("SFTP host key fingerprint mismatch (expected {$expected}, got {$actual}).");
            }
        }

        $credential = $this->password;
        if ($this->privateKey !== null && trim($this->privateKey) !== '') {
            $credential = PublicKeyLoader::load($this->privateKey, $this->passphrase ?? false);
        }
        if ($credential === null || $credential === '') {
            throw new RuntimeException('No SFTP credential: set SFTP_PRIVATE_KEY_FILE or SFTP_PASS.');
        }
        if (!$sftp->login($this->user, $credential)) {
            throw new RuntimeException('SFTP authentication failed for user ' . $this->user . '.');
        }
        $this->sftp = $sftp;
    }

    public function listFiles(string $dir): array
    {
        $sftp = $this->client();
        $names = $sftp->nlist($dir);
        if ($names === false) {
            throw new RuntimeException("Cannot list SFTP directory: {$dir}");
        }
        $out = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($sftp->is_dir(rtrim($dir, '/') . '/' . $name)) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    public function download(string $remotePath, string $localPath): int
    {
        $sftp = $this->client();
        if ($sftp->get($remotePath, $localPath) === false) {
            throw new RuntimeException("SFTP download failed: {$remotePath}");
        }
        clearstatcache(true, $localPath);
        return is_file($localPath) ? (int) filesize($localPath) : 0;
    }

    private function client(): SFTP
    {
        if ($this->sftp === null) {
            throw new RuntimeException('SFTP not connected — call connect() first.');
        }
        return $this->sftp;
    }

    /** "ssh-ed25519 AAAA..." -> "SHA256:base64(no padding)". */
    private static function sha256Fingerprint(string $hostKey): string
    {
        $parts = preg_split('/\s+/', trim($hostKey)) ?: [];
        $blob = base64_decode($parts[1] ?? $parts[0] ?? '', true);
        if ($blob === false) {
            return '';
        }
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private static function normalizeFingerprint(string $fp): string
    {
        $fp = trim($fp);
        return str_starts_with($fp, 'SHA256:') ? $fp : 'SHA256:' . ltrim($fp, ':');
    }
}
