<?php

declare(strict_types=1);

/**
 * Set up / verify the API-backend authorization for direct Google provisioning.
 *   php bin/google_auth_check.php
 *
 * The API backend (GOOGLE_BACKEND=api) talks to the Directory API directly with
 * a Google service account using domain-wide delegation — no gam install and no
 * subprocess per lookup. Unlike gam there's nothing to "log in" to interactively;
 * the one-time setup is (1) a service account + JSON key in Google Cloud, and
 * (2) authorizing that account's client id for the Directory scope in the Admin
 * console. This script checks each step of that chain against the live API and,
 * when something's missing, prints exactly what to configure.
 *
 * Exit 0 when the backend can authenticate and make a delegated call; 1 otherwise.
 */

use App\Service\GoogleWorkspaceService;

require __DIR__ . '/../src/bootstrap.php';

$d = (new GoogleWorkspaceService())->diagnose();

$dash = static fn(string $v): string => $v !== '' ? $v : '—';

echo "Google Workspace — API backend authorization check\n";
echo str_repeat('─', 52) . "\n";
echo '  Backend        : ' . $dash($d['backend']) . "\n";
echo '  SA key source  : ' . $dash($d['keySource']) . "\n";
echo '  SA client email: ' . $dash($d['clientEmail']) . "\n";
echo '  SA client id   : ' . $dash($d['clientId']) . "\n";
echo '  Admin subject  : ' . $dash($d['adminSubject']) . "\n";
echo '  Domain         : ' . $dash($d['domain']) . "\n";
echo '  Scopes         : ' . $dash($d['scopes']) . "\n";
echo str_repeat('─', 52) . "\n";

foreach ($d['steps'] as $step) {
    echo '  [' . ($step['ok'] ? 'OK  ' : 'FAIL') . "] {$step['name']}\n";
    echo "         {$step['detail']}\n";
}
echo str_repeat('─', 52) . "\n";

if ($d['ok']) {
    echo "✓ The API backend is authorized. Set GOOGLE_BACKEND=api and run the sync.\n";
    exit(0);
}

// Not ok — print the one-time setup an admin needs to complete. Fill in the real
// client id / scopes where we already know them so they can be pasted verbatim.
$clientId = $d['clientId'] !== '' ? $d['clientId'] : '<service-account client id — the numeric "Unique ID" from the SA in Google Cloud>';
$scopes   = $d['scopes'] !== '' ? $d['scopes'] : 'https://www.googleapis.com/auth/admin.directory.user';

echo "✗ Not authorized yet. One-time setup:\n\n";
echo "1. Google Cloud (console.cloud.google.com):\n";
echo "   - Create (or pick) a project and enable the \"Admin SDK API\".\n";
echo "   - Create a service account; create a JSON key for it and download it.\n";
echo "   - Point GOOGLE_SA_KEY_FILE at that JSON (or inline it as GOOGLE_SA_JSON).\n\n";
echo "2. Admin console (admin.google.com) → Security → Access and data control →\n";
echo "   API controls → Domain-wide delegation → Add new. Authorize:\n";
echo "     Client ID : {$clientId}\n";
echo "     Scopes    : {$scopes}\n\n";
echo "3. Set GOOGLE_ADMIN_SUBJECT to a real super-admin the SA impersonates, and\n";
echo "   GOOGLE_DOMAIN to your Workspace primary domain, then re-run this check.\n\n";
echo "The failing step above says which of these is still outstanding.\n";
exit(1);
