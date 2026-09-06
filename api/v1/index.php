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
 *   POST   exams                          → ExamProvisioningController::provision (HR3 → HR1 exam ingest; exams:write)
 *   GET    exams                          → ExamProvisioningController::list     (exams:read)
 *   GET    exams/{id}                     → ExamProvisioningController::show     (exams:read)
 *
 *   POST   exam-results                   → ExamResultsController::ingest   (HR3 → HR1 result ingest; exams:results:write)
 *   POST   api-keys                   → ApiKeysController::store     (hr/manager)
 *   GET    api-keys                   → ApiKeysController::index     (hr/manager)
 *   GET    api-keys/{id}              → ApiKeysController::show      (hr/manager)
 *   PATCH  api-keys/{id}              → ApiKeysController::update    (hr/manager)
 *   POST   api-keys/{id}/revoke       → ApiKeysController::revoke    (hr/manager)
 *   POST   api-keys/{id}/activate     → ApiKeysController::activate  (hr/manager)
 *   DELETE api-keys/{id}              → ApiKeysController::destroy   (hr/manager)
 *   GET    api-keys/scopes            → ApiKeysController::scopes    (hr/manager)
 *
 * AUTHENTICATION METHODS:
 *   1. X-API-Key header    → system-to-system (scope-based authorization)
 *   2. Authorization: Bearer → user-based (role-based authorization)
 *   3. PHP session cookie    → website frontend (role-based authorization)
 *
 * ENDPOINT AUTH MODE:
 *   [public]          = no auth required (rate limited)
 *   [user]            = Bearer token or session only (API keys not accepted)
 *   [user or api-key] = Bearer, session, or API key with appropriate scope
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
require_once __DIR__ . '/controllers/ApiKeysController.php';
require_once __DIR__ . '/controllers/ExamResultsController.php';
require_once __DIR__ . '/controllers/ExamProvisioningController.php';

// Resolve the current caller before dispatching (API key, Bearer token, or site session).
Auth::authenticate();

// Maintenance gate (JSON 503). Runs after auth so HR admins — who keep full
// access by design — are recognized and pass through.
maintenance_api_block();

$segments = route_path() === '' ? [] : explode('/', route_path());
$res = $segments[0] ?? null;
$id1 = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;
$id2 = isset($segments[2]) && ctype_digit($segments[2]) ? (int) $segments[2] : null;
$seg2 = $segments[2] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ---------------- /auth -------------------------------------------------
    // [user] endpoints — API keys cannot register/login/manage sessions.
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
    // [public read, user-or-api-key write]
    // GET is public (open jobs only). Write requires hr/manager role OR
    // API key with jobs:read/jobs:write scope.
    if ($res === 'jobs') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                JobsController::index((bool) (api_query('admin_view') ?? false) && Auth::isAdmin());
            case $method === 'GET' && $id1 !== null:
                JobsController::show($id1);
            case $method === 'POST' && $id1 === null:
                Auth::requireScope('jobs:write');
                JobsController::store();
            case in_array($method, ['PUT', 'PATCH'], true) && $id1 !== null:
                Auth::requireScope('jobs:write');
                JobsController::update($id1, $method === 'PATCH');
            case $method === 'DELETE' && $id1 !== null:
                Auth::requireScope('jobs:write');
                JobsController::destroy($id1);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /applications ---------------------------------------------
    // [user-or-api-key read, user-or-api-key write]
    // API key callers are treated as admin-level (see ApplicationsController).
    if ($res === 'applications') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                Auth::requireScope('applicants:read');
                ApplicationsController::index();
            case $method === 'GET' && $id1 !== null:
                Auth::requireScope('applicants:read');
                ApplicationsController::show($id1);
            case $method === 'POST' && $id1 === null:
                ApplicationsController::store(); // public (rate-limited)
            case in_array($method, ['PUT', 'PATCH'], true) && $id1 !== null:
                Auth::requireScope('applicants:write');
                ApplicationsController::update($id1, $method === 'PATCH');
            case $method === 'DELETE' && $id1 !== null:
                Auth::requireScope('applicants:write');
                ApplicationsController::destroy($id1);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /users --------------------------------------------------------
    // [user] endpoints — user data management requires Bearer/session auth.
    // API keys cannot access user records.
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
    // [user] endpoints — departments listing requires hr/manager.
    if ($res === 'departments') {
        switch (true) {
            case $method === 'GET' && $id1 === null:
                DepartmentsController::index();
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /admin -------------------------------------------------------------
    // [user] endpoints — admin operations require Bearer/session auth.
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

    // ---------------- /exams -------------------------------------------------
    // [user-or-api-key] HR3 → HR1 examination provisioning boundary.
    //   POST /exams        -> HR3 provides a new exam to HR1 (scope exams:write)
    //   GET  /exams        -> list exams (scope exams:read)
    //   GET  /exams/{id}   -> show one exam (scope exams:read)
    if ($res === 'exams') {
        switch (true) {
            case $method === 'POST' && $id1 === null:
                ExamProvisioningController::provision(false);
            case $method === 'GET' && $id1 === null:
                ExamProvisioningController::list();
            case $method === 'GET' && $id1 !== null && count($segments) === 2:
                ExamProvisioningController::show($id1);
            default:
                Response::notFound('Endpoint not found.');
        }
    }

    // ---------------- /exam-results --------------------------------------------
    // [user-or-api-key] External examination result ingestion.
    // API key scope exams:results:write for POST (the future HR3 system-to-system
    // path); exams:read for GET. Bearer/session hr|manager callers bypass scopes.
    if ($res === 'exam-results') {
        switch (true) {
            case $method === 'POST' && $id1 === null:
                ExamResultsController::ingest();
            case $method === 'GET' && $id1 === null:
                ExamResultsController::show();
            default:
                Response::notFound('Endpoint not found.');
        }
    }

    // ---------------- /api-keys ---------------------------------------------------------
    // [user] endpoints — API key management requires hr/manager Bearer/session auth.
    // API keys cannot manage themselves.
    if ($res === 'api-keys') {
        switch (true) {
            case $method === 'GET' && ($segments[1] ?? '') === 'scopes' && count($segments) === 2:
                ApiKeysController::scopes();
            case $method === 'POST' && $id1 === null:
                ApiKeysController::store();
            case $method === 'GET' && $id1 === null:
                ApiKeysController::index();
            case $method === 'GET' && $id1 !== null && count($segments) === 2:
                ApiKeysController::show($id1);
            case in_array($method, ['PUT', 'PATCH'], true) && $id1 !== null && count($segments) === 2:
                ApiKeysController::update($id1);
            case $method === 'POST' && $id1 !== null && ($segments[2] ?? '') === 'revoke' && count($segments) === 3:
                ApiKeysController::revoke($id1);
            case $method === 'POST' && $id1 !== null && ($segments[2] ?? '') === 'activate' && count($segments) === 3:
                ApiKeysController::activate($id1);
            case $method === 'DELETE' && $id1 !== null && count($segments) === 2:
                ApiKeysController::destroy($id1);
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
