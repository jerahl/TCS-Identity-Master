<?php

declare(strict_types=1);

/**
 * Manage per-user API keys from the CLI (MCP / programmatic access).
 *
 *   php bin/api_key.php create --email=user@tuscaloosacityschools.com --label="Claude Desktop"
 *   php bin/api_key.php list   --email=user@tuscaloosacityschools.com
 *   php bin/api_key.php list                       # all keys, all users
 *   php bin/api_key.php revoke --id=42
 *
 * A key inherits the owner's LIVE role — grant/downgrade the role with
 * bin/set_role.php. The full key is printed once on create and never stored.
 */

use App\Db;
use App\Service\ApiKeyService;

require __DIR__ . '/../src/bootstrap.php';

$argvRest = array_slice($_SERVER['argv'] ?? [], 1);
$cmd = $argvRest[0] ?? '';
$opts = [];
foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

$usage = <<<TXT
Usage:
  php bin/api_key.php create --email=<email> [--label="..."]
  php bin/api_key.php list [--email=<email>]
  php bin/api_key.php revoke --id=<key_id>

TXT;

try {
    $svc = new ApiKeyService();
    $db = Db::connect(Db::ROLE_APP);

    switch ($cmd) {
        case 'create':
            $email = strtolower(trim((string) ($opts['email'] ?? '')));
            if ($email === '') {
                fwrite(STDERR, $usage);
                exit(1);
            }
            $stmt = $db->prepare('SELECT user_id, role FROM app_user WHERE email = :e');
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch();
            if ($user === false) {
                fwrite(STDERR, "No app_user with email {$email}. Create/grant one first (bin/set_role.php).\n");
                exit(1);
            }
            $label = (string) ($opts['label'] ?? 'CLI key');
            $created = $svc->create((int) $user['user_id'], $label, 'cli');
            echo "Key created for {$email} (role {$user['role']}).\n";
            echo "Copy this now — it will not be shown again:\n\n";
            echo '  ' . $created['plaintext'] . "\n\n";
            break;

        case 'list':
            $email = strtolower(trim((string) ($opts['email'] ?? '')));
            if ($email !== '') {
                $stmt = $db->prepare('SELECT user_id FROM app_user WHERE email = :e');
                $stmt->execute([':e' => $email]);
                $uid = $stmt->fetchColumn();
                if ($uid === false) {
                    fwrite(STDERR, "No app_user with email {$email}.\n");
                    exit(1);
                }
                $rows = $svc->listForUser((int) $uid);
                foreach ($rows as $r) {
                    $status = $r['revoked_at'] === null ? 'active' : 'revoked';
                    printf("#%-5s %-10s %-24s prefix=%s… last_used=%s\n",
                        $r['id'], $status, $r['label'], $r['token_prefix'], $r['last_used_at'] ?? 'never');
                }
                if ($rows === []) {
                    echo "No keys for {$email}.\n";
                }
            } else {
                $rows = $svc->listAll();
                foreach ($rows as $r) {
                    $status = $r['revoked_at'] === null ? 'active' : 'revoked';
                    printf("#%-5s %-10s %-32s %-22s prefix=%s… last_used=%s\n",
                        $r['id'], $status, $r['owner_email'], $r['label'], $r['token_prefix'], $r['last_used_at'] ?? 'never');
                }
                if ($rows === []) {
                    echo "No API keys exist yet.\n";
                }
            }
            break;

        case 'revoke':
            $id = (int) ($opts['id'] ?? 0);
            if ($id <= 0) {
                fwrite(STDERR, $usage);
                exit(1);
            }
            $ok = $svc->revoke($id, null, 'cli');
            echo $ok ? "Key #{$id} revoked.\n" : "Key #{$id} not found or already revoked.\n";
            exit($ok ? 0 : 1);

        default:
            fwrite(STDERR, $usage);
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
