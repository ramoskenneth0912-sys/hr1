<?php
/**
 * HR1 REST API — single entry point for /api/v1/*.
 *
 * Supported routes (method path → handler):
 *   POST   auth/register            → AuthController::register
 *   POST   auth/login               → AuthController::login
 *   GET    auth/me                  → AuthController::me
 *   POST   auth/logout              → AuthController::logout
 *
 *   GET    jobs                     → JobsController::index        (public: open only)
 *   GET    jobs/{id}                → JobsController::show         (public if open)
 *   POST   jobs                     → JobsController::store        (hr/manager)
 *   PUT    jobs/{id}                → JobsController::update       (hr/manager)
 *   PATCH  jobs/{id}                → JobsController::update       (hr/manager)
 *   DELETE jobs/{id}                → JobsController::destroy      (hr/manager)
 *
 *   GET    applications             → ApplicationsController::index  (owner or hr/manager)
 *   GET    applications/{id}        → ApplicationsController::show
 *   POST   applications             → ApplicationsController::store  (public, multipart resume)
 *   PUT    applications/{id}        → ApplicationsController::update
 *   PATCH  applications/{id}        → ApplicationsController::update
 *   DELETE applications/{id}       → ApplicationsController::destroy (hr/manager)
 *
 *   GET    users                    → UsersController::index     (hr/manager)
 *   GET    users/{id}               → UsersController::show      (self or hr/manager)
 *   PUT    users/{id}               → UsersController::update    (self or hr/manager; guarded fields admin-only)
 *   PATCH  users/{id}               → UsersController::update
 *   DELETE users/{id}               → UsersController::destroy   (hr/manager, soft-deactivate)
 *
 *   GET    admin/applications/{id}/status  → AdminController::applicationStatus
 *   PUT    admin/applications/{id}/status  → AdminController::setApplicationStatus
 *   PATCH  admin/applications/{id}/status  → AdminController::setApplicationStatus
 *   GET    admin/stats                     → AdminController::stats
 *
 *   GET    departments                     → DepartmentsController::index (hr/manager)
 *
 * Anything else → 404 JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/JobsController.php';
require_once __DIR__ . '/controllers/ApplicationsController.php';
require_once __DIR__ . '/controllers/UsersController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/DepartmentsController.php';

// Resolve the current user before dispatching (Bearer token or site session).
Auth::authenticate();

$segments = route_path() === '' ? [] : explode('/', route_path());
$res = $segments[0] ?? null;
$id1 = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;
$id2 = isset($segments[2]) && ctype_digit($segments[2]) ? (int) $segments[2] : null;
$seg2 = $segments[2] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ---------------- /auth -------------------------------------------------
    if ($res === 'auth') {
        switch (true) {
            case $method === 'POST' && ($segments[1] ?? '') === 'register':
                AuthController::register();
            case $method === 'POST' && ($segments[1] ?? '') === 'login':
                AuthController::login();
            case $method === 'GET' && ($segments[1] ?? '') === 'me':
                AuthController::me();
            case $method === 'POST' && ($segments[1] ?? '') === 'logout':
                AuthController::logout();
            default:
                Response::notFound('Endpoint not found.');
        }
    }

    // ---------------- /jobs ---------------------------------------------------
    if ($res === 'jobs') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                JobsController::index((bool) (api_query('admin_view') ?? false) && Auth::isAdmin());
            case $method === 'GET' && $id1 !== null:
                JobsController::show($id1);
            case $method === 'POST' && $id1 === null:
                JobsController::store();
            case in_array($method, ['PUT', 'PATCH'], true) && $id1 !== null:
                JobsController::update($id1, $method === 'PATCH');
            case $method === 'DELETE' && $id1 !== null:
                JobsController::destroy($id1);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /applications ---------------------------------------------
    if ($res === 'applications') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                ApplicationsController::index();
            case $method === 'GET' && $id1 !== null:
                ApplicationsController::show($id1);
            case $method === 'POST' && $id1 === null:
                ApplicationsController::store();
            case in_array($method, ['PUT', 'PATCH'], true) && $id1 !== null:
                ApplicationsController::update($id1, $method === 'PATCH');
            case $method === 'DELETE' && $id1 !== null:
                ApplicationsController::destroy($id1);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /users --------------------------------------------------------
    if ($res === 'users') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                UsersController::index();
            case $method === 'GET' && $id1 !== null:
                UsersController::show($id1);
            case in_array($method, ['PUT', 'PATCH'], true) && $id1 !== null:
                UsersController::update($id1, $method === 'PATCH');
            case $method === 'DELETE' && $id1 !== null:
                UsersController::destroy($id1);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /departments --------------------------------------------------------
    if ($res === 'departments') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                DepartmentsController::index();
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /admin -------------------------------------------------------------
    if ($res === 'admin') {
        switch (true) {
            case $method === 'GET' && ($segments[1] ?? '') === 'stats' && count($segments) === 2:
                AdminController::stats();
            case in_array($method, ['PUT', 'PATCH', 'GET'], true)
                && ($segments[1] ?? '') === 'applications'
                && $id2 !== null && ($segments[3] ?? null) === 'status' && count($segments) === 4:
                if ($method === 'GET') {
                    AdminController::applicationStatus($id2);
                }
                AdminController::setApplicationStatus($id2);
            default:
                Response::notFound('Endpoint not found.');
        }
    }

    Response::notFound('Endpoint not found.');
} catch (PDOException $e) {
    // Surface FK conflicts as 409 rather than a generic 500.
    if ($e->getCode() === '23000') {
        Response::conflict('The request conflicts with existing data (foreign key constraint).');
    }
    throw $e;
}
