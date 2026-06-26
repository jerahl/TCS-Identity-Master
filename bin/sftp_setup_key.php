<?php

declare(strict_types=1);

/**
 * One-time SFTP key bootstrap: use your SFTP login + password ONCE to set up
 * key-based auth for the feed fetcher.
 *
 *   php bin/sftp_setup_key.php [--host=h] [--port=22] [--user=u] [--key=/path/id_ed25519]
 *
 * It will:
 *   1. generate an Ed25519 keypair (at SFTP_PRIVATE_KEY_FILE / --key) if absent,
 *   2. connect with your password (prompted, hidden) and verify/record the host
 *      key fingerprint,
 *   3. append the public key to the server's ~/.ssh/authorized_keys,
 *   4. verify key-only login works,
 *   5. update .env (SFTP_HOST/PORT/USER, SFTP_PRIVATE_KEY_FILE, SFTP_FINGERPRINT)
 *      and CLEAR SFTP_PASS so the fetcher uses the key.
 *
 * Host/user default to .env values; flags override. The password is never stored.
 */

use App\Config;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

require __DIR__ . '/../src/bootstrap.php';

$root = dirname(__DIR__);

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

$host = $opts['host'] ?? Config::get('SFTP_HOST', '');
$port = (int) ($opts['port'] ?? Config::get('SFTP_PORT', '22'));
$user = $opts['user'] ?? Config::get('SFTP_USER', '');
$keyFile = $opts['key'] ?? Config::get('SFTP_PRIVATE_KEY_FILE', $root . '/var/sftp/id_ed25519');

if ($host === '' || $user === '') {
    fwrite(STDERR, "Need an SFTP host and user. Set SFTP_HOST/SFTP_USER in .env or pass --host= --user=.\n");
    exit(1);
}

// --- password (env or hidden prompt) ---
$password = getenv('SFTP_SETUP_PASSWORD');
if ($password === false || $password === '') {
    fwrite(STDOUT, "SFTP password for {$user}@{$host}: ");
    $password = read_hidden();
    fwrite(STDOUT, "\n");
}
if ($password === '') {
    fwrite(STDERR, "No password provided.\n");
    exit(1);
}

try {
    // --- 1. generate keypair if absent ---
    if (!is_file($keyFile)) {
        @mkdir(dirname($keyFile), 0700, true);
        $key = EC::createKey('Ed25519');
        $priv = (string) $key->toString('OpenSSH');
        $pub = (string) $key->getPublicKey()->toString('OpenSSH', ['comment' => 'tcs-identity-master']);
        file_put_contents($keyFile, $priv . "\n");
        chmod($keyFile, 0600);
        file_put_contents($keyFile . '.pub', $pub . "\n");
        echo "Generated Ed25519 keypair at {$keyFile}\n";
    } else {
        $priv = (string) file_get_contents($keyFile);
        $loaded = PublicKeyLoader::load($priv);
        $pub = (string) $loaded->getPublicKey()->toString('OpenSSH', ['comment' => 'tcs-identity-master']);
        echo "Using existing private key at {$keyFile}\n";
    }
    $pub = trim($pub);

    // --- 2. connect with password + record host fingerprint ---
    $sftp = new SFTP($host, $port);
    $hostKey = $sftp->getServerPublicHostKey();
    if ($hostKey === false) {
        throw new RuntimeException('Could not read the SFTP host key.');
    }
    $fingerprint = sha256_fingerprint($hostKey);
    echo "Host key fingerprint: {$fingerprint}\n";

    if (!$sftp->login($user, $password)) {
        throw new RuntimeException('Password authentication failed.');
    }
    echo "Password login OK.\n";

    // --- 3. install public key into ~/.ssh/authorized_keys ---
    if (!$sftp->is_dir('.ssh')) {
        $sftp->mkdir('.ssh');
    }
    $sftp->chmod(0700, '.ssh');
    $existing = $sftp->file_exists('.ssh/authorized_keys') ? (string) $sftp->get('.ssh/authorized_keys') : '';
    if (!str_contains($existing, $pub)) {
        $merged = ($existing === '' ? '' : rtrim($existing, "\n") . "\n") . $pub . "\n";
        if ($sftp->put('.ssh/authorized_keys', $merged) === false) {
            throw new RuntimeException('Could not write ~/.ssh/authorized_keys (is the home dir writable?).');
        }
        $sftp->chmod(0600, '.ssh/authorized_keys');
        echo "Installed public key in ~/.ssh/authorized_keys.\n";
    } else {
        echo "Public key already present in authorized_keys.\n";
    }

    // --- 4. verify key-only login ---
    $verify = new SFTP($host, $port);
    if (!$verify->login($user, PublicKeyLoader::load($priv))) {
        throw new RuntimeException('Key login verification FAILED — left .env on password auth.');
    }
    echo "Key-based login verified.\n";

    // --- 5. update .env ---
    $envPath = $root . '/.env';
    if (is_file($envPath)) {
        set_env($envPath, 'SFTP_HOST', $host);
        set_env($envPath, 'SFTP_PORT', (string) $port);
        set_env($envPath, 'SFTP_USER', $user);
        set_env($envPath, 'SFTP_PRIVATE_KEY_FILE', $keyFile);
        set_env($envPath, 'SFTP_FINGERPRINT', $fingerprint);
        set_env($envPath, 'SFTP_PASS', '');
        echo "Updated .env (key auth; SFTP_PASS cleared).\n";
    } else {
        echo "No .env found — add SFTP_PRIVATE_KEY_FILE={$keyFile} and SFTP_FINGERPRINT={$fingerprint} yourself.\n";
    }

    echo "\nDone. Test with:  php bin/fetch_feeds.php --dry-run\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'SFTP key setup failed: ' . $e->getMessage() . "\n");
    if (isset($pub)) {
        fwrite(STDERR, "\nIf automated install failed, add this public key to the server's authorized_keys manually:\n{$pub}\n");
    }
    exit(1);
}

// ---------------------------------------------------------------------------

function read_hidden(): string
{
    if (DIRECTORY_SEPARATOR === '/' && @shell_exec('command -v stty') !== null) {
        @shell_exec('stty -echo');
        $line = fgets(STDIN);
        @shell_exec('stty echo');
        return trim((string) $line);
    }
    return trim((string) fgets(STDIN)); // fallback: visible
}

function sha256_fingerprint(string $hostKey): string
{
    $parts = preg_split('/\s+/', trim($hostKey)) ?: [];
    $blob = base64_decode($parts[1] ?? $parts[0] ?? '', true);
    return $blob === false ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}

function set_env(string $path, string $key, string $value): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $found = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^' . preg_quote($key, '/') . '=/', $line)) {
            $lines[$i] = $key . '=' . $value;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }
    file_put_contents($path, implode("\n", $lines) . "\n");
}
