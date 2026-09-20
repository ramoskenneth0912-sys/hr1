# HR1 — Endpoint Migration Map (PHP → Node)

Tracks which existing backend endpoints are migrated to the parallel Node + Express
API (`node-api/`) and **which still live in the PHP API**. The old PHP backend stays
live and untouched; the Node backend is a read-side complement that grows via
incremental, verified migrations. Nothing here is deleted or disabled.

Legend:
- **Status**
  - `migrated` — Node implementation exists, verified against PHP, no divergence.
  - `migrated (health-only)` — Node endpoint is the read-only `/health` service check.
  - `php` — still served by the existing PHP API (not migrated yet).
  - `laravel` — served by the existing Laravel API (not migrated yet).
  - `future` — planned, not yet built in either backend.
- **Auth**: see `docs/phase2-node-auth-notes.md` for the auth decisions.
  - `public` — no authentication required.
  - `user:[role]` — authenticated user (Bearer/session) of the given role.
  - `key:write` — API key with the listed write scope.
- `[ ]` = migration blocked — see notes column.

## A. Jobs & recruitment

| # | Method | Path | PHP handler | Node route/target | Status | Notes |
|---|--------|------|-------------|-------------------|--------|-------|
| 1 | GET | `/jobs` | `JobsController::index` | `GET /api/v1/jobs` | migrated | Node implementation ready; public PHP/Node parity verified. Employee-role 403 guard and admin status/search branch still deferred until Node authentication is migrated. |
| 2 | GET | `/jobs/{id}` | `JobsController::show` | `GET /api/v1/jobs/{id}` | migrated | Node implementation ready; public PHP/Node parity verified. Authentication-dependent employee guard remains deferred. |
| 3 | POST | `/jobs` | `JobsController::store` | `POST /api/v1/jobs` | php | Write. Not in scope for Phase 2. |
| 4 | PUT | `/jobs/{id}` | `JobsController::update` | `PUT /api/v1/jobs/{id}` | php | Write. |
| 5 | PATCH | `/jobs/{id}` | `JobsController::update` | `PATCH /api/v1/jobs/{id}` | php | Write. |
| 6 | DELETE | `/jobs/{id}` | `JobsController::destroy` | `DELETE /api/v1/jobs/{id}` | php | Write. |
| 7 | GET | `/applications` | `ApplicationsController::index` | `GET /api/v1/applications` | php | Requires auth; not in Phase 2. |
| 8 | GET | `/applications/{id}` | `ApplicationsController::show` | `GET /api/v1/applications/{id}` | php | |
| 9 | POST | `/applications` | `ApplicationsController::store` | `POST /api/v1/applications` | php | Public + multipart + rate-limited; not in Phase 2. |
| 10 | PUT | `/applications/{id}` | `ApplicationsController::update` | `PUT /api/v1/applications/{id}` | php | |
| 11 | DELETE | `/applications/{id}` | `ApplicationsController::destroy` | `DELETE /api/v1/applications/{id}` | php | |

## B. Auth

| # | Method | Path | PHP handler | Node route/target | Status | Notes |
|---|--------|------|-------------|-------------------|--------|-------|
| 12 | POST | `/auth/login` | `AuthController::login` | — | php | Auth explicitly deferred to a later phase. |
| 13 | POST | `/auth/register` | `AuthController::register` | — | php | Deferred. |
| 14 | GET | `/auth/me` | `AuthController::me` | — | php | Deferred. |
| 15 | POST | `/auth/logout` | `AuthController::logout` | — | php | Deferred. |

## C. Users, departments, admin stats

| # | Method | Path | PHP handler | Node route/target | Status | Notes |
|---|--------|------|-------------|-------------------|--------|-------|
| 16 | GET | `/users` | `UsersController::index` | — | php | user:[hr/manager]. |
| 17 | GET | `/users/{id}` | `UsersController::show` | — | php | |
| 18 | PUT/PATCH | `/users/{id}` | `UsersController::update` | — | php | |
| 19 | DELETE | `/users/{id}` | `UsersController::destroy` | — | php | |
| 20 | GET | `/departments` | `DepartmentsController::index` | `GET /api/v1/departments` | migrated | Node implementation ready; PHP/Node data parity verified. HR/manager authentication guard remains deferred until Node authentication is migrated. |
| 21 | GET | `/admin/stats` | `AdminController::stats` | `GET /api/v1/admin/stats` | migrated | Node implementation ready; PHP/Node data parity verified. HR/manager authentication guard remains deferred until Node authentication is migrated. |
| 22 | GET/PUT/PATCH | `/admin/applications/{id}/status` | `AdminController::...` | — | php | |
| 23 | GET | `/api-keys` (+scopes) | `ApiKeysController` | — | php | key lifecycle; deferred. |

## D. Admin — goals, recognition (Phase 3)

| # | Method | Path | PHP handler | Node route/target | Status | Notes |
|---|--------|------|-------------|-------------------|--------|-------|
| 24 | GET | `/admin/goals` | `GoalsController::adminIndex` | `GET /api/v1/admin/goals` | migrated | Node implementation ready; PHP/Node parity verified (adminShape shape parity + live endpoint vs identical-SQL envelope). HR/manager guard, manager `e.manager_id` scope filter, and employee-in-scope 403 remain deferred until Node authentication is migrated. |
| 25 | GET | `/admin/goals/{id}` | `GoalsController::adminShow` | `GET /api/v1/admin/goals/{id}` | migrated | Node implementation ready; PHP router (digit-only id, else admin-block 404) and envelope parity verified against identical-SQL resolution. HR/manager guard and manager `e.manager_id` scope remain deferred until Node authentication is migrated. |
| 26 | POST | `/admin/goals` | `GoalsController::adminStore` | — | php | Write. |
| 27 | PUT/PATCH | `/admin/goals/{id}` | `GoalsController::adminUpdate` | — | php | |
| 28 | GET | `/admin/recognition` | `RecognitionController::adminIndex` | `GET /api/v1/admin/recognition` | migrated | Node implementation ready; parity verified (real PHP `adminShape()` via reflection + live endpoint vs identical-SQL envelope, incl. status/recipient filters, pagination clamping, and 422 validation). HR/manager guard and manager `re.manager_id` scope filter remain deferred until Node authentication is migrated. |
| 29 | GET | `/admin/recognition/{id}` | `RecognitionController::adminShow` | `GET /api/v1/admin/recognition/{id}` | migrated | Node implementation ready; PHP router (digit-only id, else admin-block 404) and envelope parity verified against identical-SQL resolution. HR/manager guard and manager `assertEmployeeScope()` 403 remain deferred until Node authentication is migrated. |
| 30 | POST | `/admin/recognition` | `RecognitionController::store` | — | php | Write. |
| 31 | PUT/PATCH | `/admin/recognition/{id}` | `RecognitionController::update` | — | php | |
| 32 | DELETE | `/admin/recognition/{id}` | `RecognitionController::destroy` | — | php | |

## E. Employee self-service (ESS)

| # | Method | Path | PHP handler | Node route/target | Status | Notes |
|---|--------|------|-------------|-------------------|--------|-------|
| 33 | GET | `/employee/goals` | `GoalsController::index` | — | php | user:[employee], own. |
| 34 | PATCH | `/employee/goals/{id}` | `GoalsController::update` | — | php | |
| 35 | GET | `/employee/performance` | `PerformanceController::index` | — | php | user:[employee], own. |
| 36 | GET | `/employee/performance/{id}` | `PerformanceController::show` | — | php | |
| 37 | POST | `/employee/performance/{id}/self-assessment` | `PerformanceController::submitSelfAssessment` | — | php | |
| 38 | PATCH | `/employee/performance/{id}/self-assessment` | `PerformanceController::saveSelfAssessmentDraft` | — | php | |
| 39 | GET | `/employee/competencies` | `CompetenciesController::index` | — | php | user:[employee], own. |
| 40 | GET | `/employee/competencies/{id}` | `CompetenciesController::show` | — | php | |
| 41 | GET | `/employee/development` | `DevelopmentController::index` | — | php | user:[employee], own. |
| 42 | POST | `/employee/development` | `DevelopmentController::store` | — | php | |
| 43 | PUT/PATCH | `/employee/development/{id}` | `DevelopmentController::update` | — | php | |
| 44 | POST | `/employee/development/{id}/activities` | `DevelopmentController::storeActivity` | — | php | |
| 45 | PUT/PATCH | `/employee/development/{id}/activities/{aid}` | `DevelopmentController::updateActivity` | — | php | |
| 46 | GET | `/employee/recognition` | `RecognitionController::index` | — | php | user:[employee], own. |
| 47 | GET | `/employee/trainings` | — | `GET /api/v1/employee/trainings` | future | Introduced in React SPA Phase 1.5; not yet a backend endpoint. |

## F. Admin — performance, competencies (Phase 2 api/v1 + Laravel)

| # | Method | Path | Source | Node route/target | Status | Notes |
|---|--------|------|--------|-------------------|--------|-------|
| 48 | GET | `/admin/performance/options` | `PerformanceController::options` | `GET /api/v1/admin/performance/options` | migrated | Node implementation ready; PHP `(int)` truncating-cast parity (non-numeric -> 0 -> 422 "required", leading-numeric-prefix strings truncate) and `Response::item()` envelope verified against identical-SQL `rating_options`/`employee_goals` queries. HR/manager guard and manager `checkEmployeeScope()` 403 remain deferred until Node authentication is migrated. |
| 49 | POST | `/admin/performance/periods` | php | — | php | |
| 50 | GET | `/admin/performance/reviews` | `PerformanceController::adminIndex` | `GET /api/v1/admin/performance/reviews` | migrated | Node implementation ready; `period_id`/`status`/`department_id`/`employee_id` filter parity (PHP `intOrError()`/enum semantics) and `Response::list()` envelope verified against identical-SQL join with `employees`/`review_periods`/`users`. HR/manager guard and manager `(e.manager_id OR reviewer_user_id)` scope filter remain deferred until Node authentication is migrated. |
| 51 | GET | `/admin/competencies` | php | — | php | |
| 52 | PUT | `/admin/competencies/{id}` | php | — | php | |
| 53 | GET | `/admin/jobs` | laravel | — | laravel | Laravel read-only mirrors. |
| 54 | GET | `/admin/schema-check` | laravel | — | laravel | |

## G. System / health

| # | Method | Path | Source | Node route/target | Status | Notes |
|---|--------|------|--------|-------------------|--------|-------|
| 55 | GET | `/health` (PHP: `/api/v1/health` in `node-api`) | node | `GET /api/v1/health` | migrated (health-only) | Verifies HTTP + MySQL (SELECT 1) with a read-only DB user. See `docs/endpoint-migration-map.md`. |
| 56 | GET | `/health` | laravel | `GET /api/v1/health` | laravel | Laravel `/health` stays. |

## H. Planned (no backend yet — must not be created here)

| # | Path | Status | Notes |
|---|------|--------|-------|
| 57 | `GET /api/v1/jobs` etc. | php | Node version requires the auth layer (deferred) before migration — do not duplicate until auth exists. |

---

## Current state summary

- **`GET /api/v1/health`** — the only migrated endpoint. Returns the standard HR1
  envelope with a read-only MySQL connectivity check (`SELECT 1`) using a dedicated
  SELECT-only MySQL user. HTTP 200 when the DB responds; HTTP 503 when it does not.
  Mirrors the PHP backend's `/api/v1/health`-style up/down contract while keeping
  the DB user read-only (SELECT grants only).
- **All other endpoints** continue to be served by the existing PHP API
  (`api/v1`) and existing Laravel API (`laravel-api`) — both untouched.

This map is maintained per-endpoint as migration proceeds. The `status` column is the
source of truth for "who serves what right now."
