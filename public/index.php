<?php

declare(strict_types=1);

/**
 * Front controller / web entry point. nginx rewrites all non-file requests here
 * (try_files ... /index.php). Routes are declared below.
 *
 * NOTE: SAML SSO + RBAC arrive in Milestone 7. Until then these pages are
 * read-only and intended for the dev network only — do not expose publicly.
 */

use App\Controller\PageController;
use App\Controller\PersonController;
use App\Http\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

// Secure-ish session defaults (full hardening + SAML in M7).
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'),
]);

$router = new Router();
$person = new PersonController();
$page = new PageController();

$router->get('/', static fn() => $page->home());
$router->get('/people', static fn() => $person->index());
$router->get('/people/{id}', static fn(array $p) => $person->show($p));

// Nav targets not yet implemented (later milestones) — coherent placeholders.
$router->get('/dashboard', static fn() => $page->placeholder('Dashboard', 'home', 'Dashboard', 'Home / health dashboard', 6));
$router->get('/review', static fn() => $page->placeholder('Review queue', 'review', 'Review queue', 'Review queue', 4));
$router->get('/add', static fn() => $page->placeholder('Add person', 'add', 'People  /  Add person', 'Manual add', 2));
$router->get('/reference', static fn() => $page->placeholder('Reference data', 'ref', 'Configuration  /  Reference data', 'Reference-data admin', 6));
$router->get('/import', static fn() => $page->placeholder('Import / feeds', 'import', 'Configuration  /  Import & feeds', 'Import / feed status', 6));

$router->setNotFound(static fn() => $page->notFound());

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    echo $router->dispatch($method, $path);
} catch (\Throwable $e) {
    http_response_code(500);
    $debug = \App\Config::bool('APP_DEBUG', false);
    error_log('[idm] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo $page->error($debug ? $e : null);
}
