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
use App\Controller\ReviewController;
use App\Http\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

// Secure-ish session defaults (full hardening + SAML in M7).
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'),
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$page = new PageController();

try {
    // Controllers are built here (not at top level) so a DB failure in a service
    // constructor surfaces as the error page rather than an uncaught fatal.
    $person = new PersonController();
    $review = new ReviewController();
    $dashboard = new \App\Controller\DashboardController();
    $reference = new \App\Controller\ReferenceController();
    $import = new \App\Controller\ImportController();

    $router = new Router();
    $router->get('/', static fn() => $dashboard->index());
    $router->get('/dashboard', static fn() => $dashboard->index());
    $router->get('/people', static fn() => $person->index());
    $router->get('/people/{id}', static fn(array $p) => $person->show($p));

    $router->get('/review', static fn() => $review->index());
    $router->post('/review/confirm', static fn() => $review->confirm());
    $router->post('/review/reject', static fn() => $review->reject());

    $router->get('/reference', static fn() => $reference->index());
    $router->get('/import', static fn() => $import->index());

    // Manual add lands in a later milestone — coherent placeholder for now.
    $router->get('/add', static fn() => $page->placeholder('Add person', 'add', 'People  /  Add person', 'Manual add', 7));

    $router->setNotFound(static fn() => $page->notFound());

    echo $router->dispatch($method, $path);
} catch (\Throwable $e) {
    http_response_code(500);
    $debug = \App\Config::bool('APP_DEBUG', false);
    error_log('[idm] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo $page->error($debug ? $e : null);
}
