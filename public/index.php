<?php

declare(strict_types=1);

/**
 * Front controller / web entry point. nginx rewrites all non-file requests here.
 *
 * Pipeline: enforce HTTPS (prod) -> security headers -> session -> auth gate +
 * per-route RBAC -> dispatch. No app route is reachable unauthenticated; write
 * routes require the matching capability (server-side, not just hidden in the UI).
 */

use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\ImportController;
use App\Controller\PageController;
use App\Controller\PersonController;
use App\Controller\ReferenceController;
use App\Controller\ReviewController;
use App\Controller\UserController;
use App\Http\Router;
use App\Http\Security;
use App\Service\AuthService;

require dirname(__DIR__) . '/src/bootstrap.php';

Security::enforceHttps();
Security::sendHeaders();

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => Security::isHttps(),
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$page = new PageController();

try {
    $auth = new AuthService();

    // Gate a handler behind authentication + a capability. Unauthenticated users
    // are redirected to /login; authenticated-but-unauthorized get 403.
    $guard = static function (string $capability, callable $handler) use ($auth, $page): callable {
        return static function (array $params = []) use ($auth, $capability, $handler, $page) {
            if (!$auth->isAuthenticated()) {
                header('Location: ' . url('/login'), true, 302);
                return '';
            }
            if (!$auth->can($capability)) {
                http_response_code(403);
                return $page->forbidden();
            }
            return $handler($params);
        };
    };

    $person = new PersonController();
    $review = new ReviewController();
    $dashboard = new DashboardController();
    $reference = new ReferenceController();
    $import = new ImportController();
    $users = new UserController();
    $audit = new \App\Controller\AuditController();
    $authCtl = new AuthController();

    $router = new Router();

    // ---- OneSync write-back API (machine-to-machine, token auth — not session) ----
    $api = new \App\Controller\ApiController();
    $router->get('/api/onesync/ping', static fn() => $api->ping());
    $router->post('/api/onesync/username', static fn() => $api->username());
    $router->post('/api/onesync/sync-status', static fn() => $api->syncStatus());

    // ---- Public (no auth) ----
    $router->get('/login', static fn() => $authCtl->loginPage());
    $router->get('/saml/login', static fn() => $authCtl->samlLogin());
    $router->post('/saml/acs', static fn() => $authCtl->acs());
    $router->get('/saml/metadata', static fn() => $authCtl->metadata());
    $router->post('/dev-login', static fn() => $authCtl->devLogin());
    $router->get('/logout', static fn() => $authCtl->logout());

    // ---- View (any authenticated role) ----
    $router->get('/', $guard('view', static fn() => $dashboard->index()));
    $router->get('/dashboard', $guard('view', static fn() => $dashboard->index()));
    $router->get('/people', $guard('view', static fn() => $person->index()));
    $router->get('/people/{id}', $guard('view', static fn(array $p) => $person->show($p)));
    $router->get('/review', $guard('view', static fn() => $review->index()));
    $router->get('/reference', $guard('view', static fn() => $reference->index()));
    $router->get('/import', $guard('view', static fn() => $import->index()));
    $router->get('/vpn', $guard('view', static fn() => (new \App\Controller\VpnController())->index()));

    // ---- Edit (editor / admin) ----
    $router->post('/review/confirm', $guard('edit', static fn() => $review->confirm()));
    $router->post('/review/reject', $guard('edit', static fn() => $review->reject()));
    $router->get('/add', $guard('edit', static fn() => $person->addForm()));
    $router->post('/add', $guard('edit', static fn() => $person->create()));
    $router->get('/people/{id}/edit', $guard('edit', static fn(array $p) => $person->editForm($p)));
    $router->post('/people/{id}/edit', $guard('edit', static fn(array $p) => $person->update($p)));
    $router->post('/people/{id}/disable', $guard('edit', static fn(array $p) => $person->disable($p)));
    $router->post('/import/upload', $guard('edit', static fn() => $import->upload()));
    $router->post('/import/fetch', $guard('edit', static fn() => $import->fetch()));
    $router->post('/vpn/restart', $guard('edit', static fn() => (new \App\Controller\VpnController())->restart()));

    // ---- Admin only ----
    $router->get('/users', $guard('admin', static fn() => $users->index()));
    $router->post('/users/role', $guard('admin', static fn() => $users->updateRole()));
    $router->post('/users/add', $guard('admin', static fn() => $users->addUser()));
    $router->get('/audit', $guard('admin', static fn() => $audit->index()));

    $router->setNotFound(static fn() => $page->notFound());

    echo $router->dispatch($method, $path);
} catch (\Throwable $e) {
    http_response_code(500);
    $debug = \App\Config::bool('APP_DEBUG', false);
    error_log('[idm] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo $page->error($debug ? $e : null);
}
