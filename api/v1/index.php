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
 *   GET    employee/goals                  → GoalsController::index   (employee, own goals)
 *   PATCH  employee/goals/{id}             → GoalsController::update  (employee, own goal)
 *
 *   GET    employee/competencies                  → CompetenciesController::index   (employee, own competencies)
 *   GET    employee/competencies/{id}             → CompetenciesController::show    (employee, own competency)
 *
 *   GET    employee/performance            → PerformanceController::index  (employee, own reviews)
 *   GET    employee/performance/{id}       → PerformanceController::show
 *   PATCH  employee/performance/{id}/self-assessment → PerformanceController::saveSelfAssessmentDraft
 *   POST   employee/performance/{id}/self-assessment → PerformanceController::submitSelfAssessment
 *   POST   employee/performance/{id}/acknowledge     → PerformanceController::acknowledge
 *
 *   GET    admin/performance/periods                  → PerformanceController::periods      (hr/manager)
 *   POST   admin/performance/periods                  → PerformanceController::createPeriod (hr only)
 *   GET    admin/performance/options?employee_id=     → PerformanceController::options      (hr/manager)
 *   GET    admin/performance/reviews                  → PerformanceController::adminIndex   (hr/manager, scoped)
 *   POST   admin/performance/reviews                  → PerformanceController::store        (hr/manager, scoped)
 *   GET    admin/performance/reviews/{id}             → PerformanceController::adminShow
 *   PATCH/PUT admin/performance/reviews/{id}          → PerformanceController::adminUpdate
 *   PUT    admin/performance/reviews/{id}/goal-results → PerformanceController::scoreGoals
 *   PUT    admin/performance/reviews/{id}/manager-feedback → PerformanceController::managerFeedback
 *
 *   GET    admin/competencies                     → CompetenciesController::catalogIndex   (hr/manager)
 *   POST   admin/competencies                     → CompetenciesController::catalogStore   (hr only)
 *   GET    admin/competencies/{id}                → CompetenciesController::catalogShow
 *   PUT/PATCH admin/competencies/{id}             → CompetenciesController::catalogUpdate  (hr only)
 *   DELETE admin/competencies/{id}                → CompetenciesController::catalogDestroy (hr only, archive)
 *   GET    admin/competencies/employees           → CompetenciesController::assignmentsIndex   (hr/manager, scoped)
 *   POST   admin/competencies/employees           → CompetenciesController::assignmentsStore   (hr only)
 *   GET    admin/competencies/employees/{id}      → CompetenciesController::assignmentsShow
 *   PUT/PATCH admin/competencies/employees/{id}   → CompetenciesController::assignmentsUpdate (hr full; manager evaluation)
 *   DELETE admin/competencies/employees/{id}      → CompetenciesController::assignmentsDestroy (hr only)
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
require_once __DIR__ . '/controllers/GoalsController.php';
require_once __DIR__ . '/controllers/PerformanceController.php';
require_once __DIR__ . '/controllers/CompetenciesController.php';
require_once __DIR__ . '/controllers/DevelopmentController.php';
require_once __DIR__ . '/controllers/RecognitionController.php';
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
$id3 = isset($segments[3]) && ctype_digit($segments[3]) ? (int) $segments[3] : null;
$id4 = isset($segments[4]) && ctype_digit($segments[4]) ? (int) $segments[4] : null;
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

    // ---------------- /employee ----------------------------------------------------------
    // [user] endpoints — employee self-service; API keys are rejected. Ownership
    // is derived from the authenticated user's employee record, never from input.
    if ($res === 'employee' && ($segments[1] ?? '') === 'goals') {
        switch (true) {
            case $method === 'GET' && count($segments) === 2:
                GoalsController::index();
            case in_array($method, ['PUT', 'PATCH'], true) && $id2 !== null && count($segments) === 3:
                GoalsController::update($id2, $method === 'PATCH');
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /employee/performance ---------------------------------------
    // [user] endpoints — employee self-service; API keys are rejected. Ownership
    // comes from the authenticated user's employee record, never from input.
    if ($res === 'employee' && ($segments[1] ?? '') === 'performance') {
        switch (true) {
            case $method === 'GET' && count($segments) === 2:
                PerformanceController::index();
            case $method === 'GET' && $id2 !== null && count($segments) === 3:
                PerformanceController::show($id2);
            case $method === 'PATCH' && $id2 !== null && ($segments[3] ?? '') === 'self-assessment' && count($segments) === 4:
                PerformanceController::saveSelfAssessmentDraft($id2);
            case $method === 'POST' && $id2 !== null && ($segments[3] ?? '') === 'self-assessment' && count($segments) === 4:
                PerformanceController::submitSelfAssessment($id2);
            case $method === 'POST' && $id2 !== null && ($segments[3] ?? '') === 'acknowledge' && count($segments) === 4:
                PerformanceController::acknowledge($id2);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /employee/competencies ---------------------------------
    // [user] endpoints — employee self-service; API keys are rejected. Ownership
    // comes from the authenticated user's employee record, never from input.
    if ($res === 'employee' && ($segments[1] ?? '') === 'competencies') {
        switch (true) {
            case $method === 'GET' && count($segments) === 2:
                CompetenciesController::index();
            case $method === 'GET' && $id2 !== null && count($segments) === 3:
                CompetenciesController::show($id2);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }

    }

    // ---------------- /employee/development --------------------------------
    // Employee-owned development plans and roadmap activities. Ownership is
    // resolved from the authenticated user's employee record.
    if ($res === 'employee' && ($segments[1] ?? '') === 'development') {
        switch (true) {
            case $method === 'GET' && count($segments) === 2:
                DevelopmentController::index();
            case $method === 'POST' && count($segments) === 2:
                DevelopmentController::store();
            case in_array($method, ['PUT', 'PATCH'], true) && $id2 !== null && count($segments) === 3:
                DevelopmentController::update($id2);
            case $method === 'POST' && $id2 !== null && ($segments[3] ?? '') === 'activities' && count($segments) === 4:
                DevelopmentController::storeActivity($id2);
            case in_array($method, ['PUT', 'PATCH'], true)
                && $id2 !== null
                && ($segments[3] ?? '') === 'activities'
                && $id4 !== null
                && count($segments) === 5:
                DevelopmentController::updateActivity($id2, $id4);
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /employee/recognition ------------------------------
    // Published HR-owned records; employees have read-only access to their
    // own records through the controller's authenticated employee scope.
    if ($res === 'employee' && ($segments[1] ?? '') === 'recognition') {
        switch (true) {
            case $method === 'GET' && count($segments) === 2:
                RecognitionController::index();
            default:
                Response::error('Method not allowed for this endpoint.', [], 405);
        }
    }

    // ---------------- /admin -------------------------------------------------------------
    // [user] endpoints — admin operations require Bearer/session auth.
    if ($res === 'admin') {
        switch (true) {
            case $method === 'POST' && ($segments[1] ?? '') === 'recognition' && count($segments) === 2:
                RecognitionController::store();
            case in_array($method, ['PUT', 'PATCH'], true)
                && ($segments[1] ?? '') === 'recognition'
                && $id2 !== null && count($segments) === 3:
                RecognitionController::update($id2);
            case $method === 'GET' && ($segments[1] ?? '') === 'stats' && count($segments) === 2:
                AdminController::stats();
            case in_array($method, ['PUT', 'PATCH', 'GET'], true)
                && ($segments[1] ?? '') === 'applications'
                && $id2 !== null && ($segments[3] ?? null) === 'status' && count($segments) === 4:
                if ($method === 'GET') {
                    AdminController::applicationStatus($id2);
                }
                AdminController::setApplicationStatus($id2);
            // --- admin/performance (Phase 2) ---
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'periods' && count($segments) === 3 && $method === 'GET':
                PerformanceController::periods();
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'periods' && count($segments) === 3 && $method === 'POST':
                PerformanceController::createPeriod();
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'options' && count($segments) === 3 && $method === 'GET':
                PerformanceController::options();
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'reviews' && count($segments) === 3 && $method === 'GET':
                PerformanceController::adminIndex();
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'reviews' && count($segments) === 3 && $method === 'POST':
                PerformanceController::store();
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'reviews' && $id3 !== null && count($segments) === 4 && $method === 'GET':
                PerformanceController::adminShow($id3);
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'reviews' && $id3 !== null && count($segments) === 4 && in_array($method, ['PUT', 'PATCH'], true):
                PerformanceController::adminUpdate($id3);
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'reviews' && $id3 !== null && ($segments[4] ?? '') === 'goal-results' && count($segments) === 5 && $method === 'PUT':
                PerformanceController::scoreGoals($id3);
            case ($segments[1] ?? '') === 'performance' && ($segments[2] ?? '') === 'reviews' && $id3 !== null && ($segments[4] ?? '') === 'manager-feedback' && count($segments) === 5 && $method === 'PUT':
                PerformanceController::managerFeedback($id3);
            // --- admin/competencies (Phase 3A) ---
            // Competency catalog (hr/manager read; hr-only write)
            case ($segments[1] ?? '') === 'competencies' && count($segments) === 2 && $method === 'GET':
                CompetenciesController::catalogIndex();
            case ($segments[1] ?? '') === 'competencies' && count($segments) === 2 && $method === 'POST':
                CompetenciesController::catalogStore();
            case ($segments[1] ?? '') === 'competencies' && $id2 !== null && count($segments) === 3 && $method === 'GET':
                CompetenciesController::catalogShow($id2);
            case ($segments[1] ?? '') === 'competencies' && $id2 !== null && count($segments) === 3 && in_array($method, ['PUT', 'PATCH'], true):
                CompetenciesController::catalogUpdate($id2, $method === 'PATCH');
            case ($segments[1] ?? '') === 'competencies' && $id2 !== null && count($segments) === 3 && $method === 'DELETE':
                CompetenciesController::catalogDestroy($id2);
            // Employee competency assignments (hr/manager, manager scope enforced)
            case ($segments[1] ?? '') === 'competencies' && ($segments[2] ?? '') === 'employees' && count($segments) === 3 && $method === 'GET':
                CompetenciesController::assignmentsIndex();
            case ($segments[1] ?? '') === 'competencies' && ($segments[2] ?? '') === 'employees' && count($segments) === 3 && $method === 'POST':
                CompetenciesController::assignmentsStore();
            case ($segments[1] ?? '') === 'competencies' && ($segments[2] ?? '') === 'employees' && $id3 !== null && count($segments) === 4 && $method === 'GET':
                CompetenciesController::assignmentsShow($id3);
            case ($segments[1] ?? '') === 'competencies' && ($segments[2] ?? '') === 'employees' && $id3 !== null && count($segments) === 4 && in_array($method, ['PUT', 'PATCH'], true):
                CompetenciesController::assignmentsUpdate($id3, $method === 'PATCH');
            case ($segments[1] ?? '') === 'competencies' && ($segments[2] ?? '') === 'employees' && $id3 !== null && count($segments) === 4 && $method === 'DELETE':
                CompetenciesController::assignmentsDestroy($id3);
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
