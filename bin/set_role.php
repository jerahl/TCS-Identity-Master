<?php

declare(strict_types=1);

/**
 * Grant an app role to a user (bootstrap the first admin, etc.).
 *   php bin/set_role.php --email=admin@tuscaloosacityschools.com --role=admin
 * Roles: admin | editor | readonly. Creates the user if absent; audited.
 */

use App\Service\AuthService;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}

$email = $opts['email'] ?? '';
$role = $opts['role'] ?? '';
if ($email === '' || !AuthService::isValidRole($role)) {
    fwrite(STDERR, "Usage: php bin/set_role.php --email=<email> --role=<admin|editor|readonly>\n");
    exit(1);
}

try {
    $user = (new AuthService())->grantRole($email, $role);
    echo "Set {$user['email']} -> {$user['role']} (user_id {$user['user_id']}).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
