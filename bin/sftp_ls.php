<?php

declare(strict_types=1);

/**
 * SFTP diagnostic: connect with the configured SFTP_* settings and list a
 * directory, so you can discover the exact (case-sensitive) remote paths —
 * useful for Serv-U and other servers with virtual paths.
 *
 *   php bin/sftp_ls.php                 # lists home + '/'
 *   php bin/sftp_ls.php --dir=/Nextgen
 */

use App\Config;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

$host = (string) Config::get('SFTP_HOST', '');
$port = (int) Config::get('SFTP_PORT', '22');
$user = (string) Config::get('SFTP_USER', '');
if ($host === '' || $user === '') {
    fwrite(STDERR, "Set SFTP_HOST / SFTP_USER (and auth) in .env first.\n");
    exit(1);
}

$keyFile = Config::get('SFTP_PRIVATE_KEY_FILE');
$priv = ($keyFile !== null && is_file($keyFile)) ? (string) file_get_contents($keyFile) : null;
$pass = Config::get('SFTP_PASS');

try {
    $sftp = new SFTP($host, $port);

    // Optional host-key verification (same as the client).
    $expected = Config::get('SFTP_FINGERPRINT');
    if ($expected !== null && trim($expected) !== '') {
        $hk = $sftp->getServerPublicHostKey();
        $parts = $hk === false ? [] : (preg_split('/\s+/', trim($hk)) ?: []);
        $blob = base64_decode($parts[1] ?? '', true);
        $actual = $blob === false ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        echo "Host key: {$actual}\n";
    }

    $cred = ($priv !== null && trim($priv) !== '') ? PublicKeyLoader::load($priv, Config::get('SFTP_PASSPHRASE') ?: false) : $pass;
    if (!$sftp->login($user, $cred)) {
        throw new RuntimeException('Authentication failed.');
    }
    echo "Connected to {$host}:{$port} as {$user}.\n";
    echo 'Home (realpath "."): ' . var_export($sftp->realpath('.'), true) . "\n";

    $dirs = isset($opts['dir']) && $opts['dir'] !== '1' ? [$opts['dir']] : ['.', '/'];
    foreach ($dirs as $dir) {
        echo "\n=== nlist('{$dir}') ===\n";
        $list = $sftp->rawlist($dir);
        if ($list === false) {
            echo '  ERROR: ' . (trim((string) $sftp->getLastSFTPError()) ?: 'cannot list (check path/case/permissions)') . "\n";
            continue;
        }
        foreach ($list as $name => $info) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $isDir = is_object($info) && isset($info->type) && $info->type === 2; // NET_SFTP_TYPE_DIRECTORY
            printf("  %s %s\n", $isDir ? '[dir] ' : '[file]', $name);
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'SFTP diagnostic failed: ' . $e->getMessage() . "\n");
    exit(1);
}
