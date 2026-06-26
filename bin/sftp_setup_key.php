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

    // --- 3. install public key (SFTP write, then SSH exec fallback) ---
    $installed = install_via_sftp($sftp, $pub) || install_via_exec($host, $port, $user, $password, $pub);

    // --- 4. verify key-only login (only if we think it's installed) ---
    $keyVerified = false;
    if ($installed) {
        $verify = new SFTP($host, $port);
        $keyVerified = (bool) $verify->login($user, PublicKeyLoader::load($priv));
        echo $keyVerified ? "Key-based login verified.\n" : "Installed, but key login did not verify.\n";
    }

    // --- 5. update .env ---
    $envPath = $root . '/.env';
    $writeEnv = static function (array $kv) use ($envPath): bool {
        if (!is_file($envPath)) {
            return false;
        }
        foreach ($kv as $k => $v) {
            set_env($envPath, $k, $v);
        }
        return true;
    };

    if ($keyVerified) {
        $ok = $writeEnv([
            'SFTP_HOST' => $host, 'SFTP_PORT' => (string) $port, 'SFTP_USER' => $user,
            'SFTP_PRIVATE_KEY_FILE' => $keyFile, 'SFTP_FINGERPRINT' => $fingerprint, 'SFTP_PASS' => '',
        ]);
        echo $ok
            ? "Updated .env — key auth enabled, SFTP_PASS cleared.\n"
            : "No .env found — set SFTP_PRIVATE_KEY_FILE={$keyFile} and SFTP_FINGERPRINT={$fingerprint}.\n";
        echo "\nDone. Test with:  php bin/fetch_feeds.php --dry-run\n";
        exit(0);
    }

    // Could not install the key automatically (SFTP-only/chrooted server, or key
    // management is elsewhere). Configure PASSWORD auth so fetching works now,
    // record the fingerprint, and print the key for manual install.
    $envOk = $writeEnv([
        'SFTP_HOST' => $host, 'SFTP_PORT' => (string) $port, 'SFTP_USER' => $user,
        'SFTP_FINGERPRINT' => $fingerprint, 'SFTP_PASS' => $password, 'SFTP_PRIVATE_KEY_FILE' => '',
    ]);
    if (!$envOk) {
        fwrite(STDOUT, "\nNo .env found at {$envPath} — run `cp .env.example .env` first, or set the SFTP_* values yourself.\n");
    }
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Could not install the key automatically — the server's home isn't writable over SFTP\n");
    fwrite(STDOUT, "and SSH exec isn't available (typical for SFTP-only / chrooted accounts).\n\n");
    fwrite(STDOUT, ".env was set to PASSWORD auth (host/port/user/password + verified host fingerprint),\n");
    fwrite(STDOUT, "so 'php bin/fetch_feeds.php' works now. To switch to key auth, ask the SFTP admin to\n");
    fwrite(STDOUT, "add this public key to the account's authorized_keys, then re-run this script:\n\n");
    fwrite(STDOUT, $pub . "\n\n");
    fwrite(STDOUT, "(public key also saved at {$keyFile}.pub)\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'SFTP key setup failed: ' . $e->getMessage() . "\n");
    if (isset($pub)) {
        fwrite(STDERR, "\nPublic key (install manually if needed):\n{$pub}\n");
    }
    exit(1);
}

/** Append the public key via SFTP write of ~/.ssh/authorized_keys. Returns success. */
function install_via_sftp(SFTP $sftp, string $pub): bool
{
    try {
        if (!$sftp->is_dir('.ssh') && !$sftp->mkdir('.ssh')) {
            return false;
        }
        @$sftp->chmod(0700, '.ssh');
        $existing = $sftp->file_exists('.ssh/authorized_keys') ? (string) $sftp->get('.ssh/authorized_keys') : '';
        if (str_contains($existing, $pub)) {
            echo "Public key already present in authorized_keys (SFTP).\n";
            return true;
        }
        $merged = ($existing === '' ? '' : rtrim($existing, "\n") . "\n") . $pub . "\n";
        if ($sftp->put('.ssh/authorized_keys', $merged) === false) {
            return false;
        }
        @$sftp->chmod(0600, '.ssh/authorized_keys');
        echo "Installed public key via SFTP (~/.ssh/authorized_keys).\n";
        return true;
    } catch (\Throwable) {
        return false;
    }
}

/** Append the public key by running a shell command over SSH. Returns success. */
function install_via_exec(string $host, int $port, string $user, string $password, string $pub): bool
{
    if (!class_exists(\phpseclib3\Net\SSH2::class)) {
        return false;
    }
    try {
        $ssh = new \phpseclib3\Net\SSH2($host, $port);
        if (!$ssh->login($user, $password)) {
            return false;
        }
        $k = str_replace("'", "'\\''", $pub); // single-quote-safe
        $cmd = "mkdir -p ~/.ssh && chmod 700 ~/.ssh && "
            . "touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && "
            . "(grep -qxF '{$k}' ~/.ssh/authorized_keys || printf '%s\\n' '{$k}' >> ~/.ssh/authorized_keys) && echo IDM_OK";
        $out = (string) $ssh->exec($cmd);
        if (str_contains($out, 'IDM_OK')) {
            echo "Installed public key via SSH exec.\n";
            return true;
        }
        return false;
    } catch (\Throwable) {
        return false;
    }
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
