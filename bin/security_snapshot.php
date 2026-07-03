<?php

declare(strict_types=1);

/**
 * Collect the host's security posture (firewall, fail2ban, sshd, updates,
 * AppArmor, auditd) and write it as JSON for the admin /security page to read.
 *
 *   sudo php bin/security_snapshot.php            # write to SECURITY_STATUS_FILE
 *   sudo php bin/security_snapshot.php --print    # also echo the JSON to stdout
 *
 * Run as ROOT from a systemd timer (deploy/idm-security-snapshot.{service,timer}).
 * On a hardened host, php-fpm has proc_open disabled (harden-debian12.sh), so the
 * web app can't run these commands itself — this out-of-band collector does, and
 * the app just reads the file. Because it runs as root it needs NO sudo and NO
 * sudoers rule. The CLI php.ini is separate from php-fpm's, so proc_open works.
 *
 * The file is written atomically (temp + rename) and made world-readable so the
 * web user can read it; it contains only status (open ports, banned IPs), no
 * secrets.
 */

use App\Config;
use App\Service\SecurityStatusService;

require __DIR__ . '/../src/bootstrap.php';

$args = array_slice($_SERVER['argv'] ?? [], 1);
$print = in_array('--print', $args, true);

$path = trim((string) Config::get('SECURITY_STATUS_FILE', '/var/idm/security-status.json'));
if ($path === '') {
    fwrite(STDERR, "SECURITY_STATUS_FILE is empty — set it (e.g. /var/idm/security-status.json).\n");
    exit(2);
}

// Run the host probes directly (we ARE root here → no sudo). enabled=true forces
// collection regardless of SECURITY_STATUS_ENABLED (that flag gates the web UI);
// snapshotFile='' forces live command execution rather than reading a file.
$svc = new SecurityStatusService(
    enabled: true,
    useSudo: false,
    snapshotFile: ''
);

$report = $svc->hostReport();
$report['generated_at'] = time();
$report['generated_by'] = 'bin/security_snapshot.php';

$json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "Failed to encode snapshot JSON.\n");
    exit(1);
}

$dir = dirname($path);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create directory {$dir}.\n");
    exit(1);
}

// Atomic write: temp file in the same dir, then rename over the target.
$tmp = $path . '.tmp';
if (@file_put_contents($tmp, $json) === false) {
    fwrite(STDERR, "Cannot write {$tmp} — is this running as root?\n");
    exit(1);
}
@chmod($tmp, 0644);            // world-readable so php-fpm (www-data) can read it
if (!@rename($tmp, $path)) {
    @unlink($tmp);
    fwrite(STDERR, "Cannot move {$tmp} -> {$path}.\n");
    exit(1);
}

$banned = count($report['bannedIps']);
echo 'Security snapshot written to ' . $path . ' · ' . count($report['cards']) . ' cards · '
    . $banned . ' banned IP' . ($banned === 1 ? '' : 's') . "\n";
if ($print) {
    echo $json . "\n";
}
exit(0);
