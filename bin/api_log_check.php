<?php

declare(strict_types=1);

/**
 * Diagnose the OneSync API debug log: is it enabled, where does it write, and can
 * the process actually write there? Writes a test line so you can confirm.
 *
 *   php bin/api_log_check.php
 *
 * NOTE: run this as the SAME user the web server runs as, or the writability
 * result won't match what the API sees. e.g.:
 *   sudo -u www-data php bin/api_log_check.php
 */

use App\Config;
use App\Support\ApiLog;

require __DIR__ . '/../src/bootstrap.php';

$user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid()) : get_current_user();
echo "Running as user:        {$user}\n";
echo 'ONESYNC_API_DEBUG:      ' . (Config::bool('ONESYNC_API_DEBUG', false) ? 'true (enabled)' : 'false (DISABLED — set ONESYNC_API_DEBUG=true)') . "\n";
echo 'ONESYNC_API_KEY:        ' . (trim((string) Config::get('ONESYNC_API_KEY', '')) !== '' ? 'set' : 'NOT set (API returns 503)') . "\n";

$path = ApiLog::path();
$dir = dirname($path);
echo "Log path:               {$path}\n";
echo "Dir exists:             " . (is_dir($dir) ? 'yes' : 'NO') . "\n";
if (!is_dir($dir)) {
    echo "  -> create it:         sudo mkdir -p {$dir} && sudo chown {$user} {$dir}\n";
}
echo "Dir writable:           " . (is_dir($dir) && is_writable($dir) ? 'yes' : 'NO') . "\n";
if (is_file($path)) {
    echo "File writable:          " . (is_writable($path) ? 'yes' : 'NO') . "\n";
}

// Walk the parent chain: creating a file needs execute (traverse) on EVERY
// ancestor, even if the leaf dir is owned by this user. A parent missing x is
// the usual "dir is mine but still permission denied" cause.
echo "Path traversal (each ancestor needs 'x' to descend):\n";
$parts = explode('/', rtrim($dir, '/'));
$acc = '';
foreach ($parts as $seg) {
    $acc = $acc === '' ? ($seg === '' ? '/' : $seg) : rtrim($acc, '/') . '/' . $seg;
    if ($acc === '' || $seg === '') {
        $acc = '/';
    }
    if (!is_dir($acc)) {
        echo "  {$acc}  (missing)\n";
        continue;
    }
    $perms = substr(sprintf('%o', fileperms($acc)), -4);
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($acc))['name'] ?? (string) fileowner($acc)) : (string) fileowner($acc);
    $canEnter = is_executable($acc);
    echo "  " . str_pad($acc, 26) . " {$perms} {$owner}" . ($canEnter ? '' : "   <-- NOT traversable by {$user}; fix: sudo chmod o+x {$acc}") . "\n";
}

if (!Config::bool('ONESYNC_API_DEBUG', false)) {
    echo "\nLogging is OFF, so no test line was written. Enable it and re-run.\n";
    exit(0);
}

ApiLog::write(['endpoint' => 'self-test', 'status' => 0, 'note' => 'api_log_check.php test line']);

if (is_file($path) && is_readable($path)) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    echo "\nWrote a test line. Last entry:\n  " . (end($lines) ?: '(empty)') . "\n";
    echo "OK — tail it:           tail -f {$path}\n";
} else {
    echo "\nCould not write {$path} — check the PHP error log instead; ApiLog falls back to error_log().\n";
    echo "Most likely the web user can't write {$dir}. Fix:\n";
    echo "  sudo mkdir -p {$dir} && sudo chown www-data {$dir} && sudo chmod 750 {$dir}\n";
}
