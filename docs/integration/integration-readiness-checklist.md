# HR1 — Integration Readiness Checklist

Status legend: **Ready** = verified working during this phase ·
**Pending** = documented intent, blocked on an external specification (do not implement) ·
**N/A** = not applicable.

---

## A. HR1 API surface

| # | Item | Status | Evidence / Notes |
|---|---|---|---|
| A1 | Single JSON API entry point under `/api/v1` | Ready | `api/v1/index.php`; 404 JSON on unknown routes |
| A2 | Strict JSON only (never HTML) from `/api/v1` | Ready | bootstrap sets JSON headers + exception handler |
| A3 | Response envelope (`success/message/data/meta`, `errors` object) | Ready | `lib/Response.php` |
| A4 | Documented HTTP status code set (200/201/204/400/401/403/404/405/409/422/429/500/503) | Ready | `hr1-api.md` §2 |
| A5 | Standard error logging to `storage/api/error.log` (no stack leakage) | Ready | bootstrap exception handler |
| A6 | Pagination on list endpoints (`page`, `limit` ≤100) | Ready | `meta` block |
| A7 | Health/version surface for integrators | Pending | no `/health` or `/version` endpoint today — candidate future enhancement |
| A8 | OpenAPI definition parity with live code | Partial | `docs/openapi.yaml` covers Auth/Jobs/Applications/Users/Admin only; exams/exam-results/api-keys pending |

## B. Authentication & authorization

| # | Item | Status | Evidence / Notes |
|---|---|---|---|
| B1 | API keys (`hr1_...`) with SHA-256 hash-only storage | Ready | `lib/ApiKeyAuth.php`, `api_keys` table |
| B2 | API key scopes enforced at route level | Ready | `Auth::requireScope()` in `index.php` |
| B3 | Bearer tokens (7-day TTL, hashed, revocable) | Ready | `api_tokens`, `POST /auth/logout` |
| B4 | Session-cookie auth for same-origin web | Ready | `Auth::authenticate()` |
| B5 | No fallback between auth methods (invalid key/token = 401) | Ready | `lib/Auth.php` |
| B6 | Role-based policy (hr/manager admin; owner-scoped applicant) | Ready | verified matrix staging + localhost |
| B7 | API keys cannot impersonate users / manage themselves | Ready | enforced |
| B8 | Employee role locked out of `/jobs*` + `/applications*` API | Ready | `JobsController`/`ApplicationsController` |
| B9 | Key management lifecycle (create/list/show/update/revoke/activate/delete/scopes) | Ready | `ApiKeysController` |
| B10 | Admin audit trail for key events (`api_key_audit`) | Ready | `ApiKeyAuth::logAudit`/`logSecurityEvent` |

## C. HR3 exam boundary

| # | Item | Status | Evidence / Notes |
|---|---|---|---|
| C1 | `POST /exams` provisioning endpoint (HR3→HR1) | Ready | `ExamProvisioningController::provision` |
| C2 | Idempotency by unique `external_ref` (409 on replay) | Ready | index `uq_exams_external_ref`, verified in schema |
| C3 | `POST /exam-results` ingest endpoint | Ready | `ExamResultsController::ingest` |
| C4 | Token-based resolution (`exm_...`), never raw DB ids | Ready | `findAssignmentByToken()` |
| C5 | Server-side pass/fail derivation (external values untrusted) | Ready | `examPassThreshold()` |
| C6 | Duplicate/replay protection (`duplicate_result`, `duplicate_external`) | Ready | unique indexes + guards |
| C7 | Association integrity checks (applicant↔exam↔job) | Ready | `recordExamResult()` `job_mismatch` |
| C8 | `interview_eligible` computed, staged (no auto-hire) | Ready | `applicantFinalInterviewEligible()` |
| C9 | HR3 general contract (field names, auth, transport) | Pending | `Pending external API specification` |
| C10 | Exam assignment/launch handshake to HR3 | Pending | HR-internal today; spec required |

## D. Hardening & operations

| # | Item | Status | Evidence / Notes |
|---|---|---|---|
| D1 | Rate limiting per IP (global 300, login/register 10, apply 20, key-fail 10) | Ready | verified `429` behavior in Phase 2 |
| D2 | CORS allowlist (disabled in production unless configured) | Ready | `HR1_API_ALLOWED_ORIGINS` |
| D3 | Security headers (`nosniff`, frame deny, no-referrer) | Ready | bootstrap |
| D4 | `X-Content-Type-Options`, `X-Frame-Options` present | Ready | |
| D5 | Resume upload validation (ext + MIME + size) + web-denied storage | Ready | `uploads/api_resumes` |
| D6 | Malicious input validation (PDO prepared statements everywhere) | Ready | controllers use prepared statements |
| D7 | Maintenance-mode JSON 503 (HR bypass) | Ready | `maintenance_api_block()` |
| D8 | TLS enforced on `exam_url`; integration channel must be HTTPS | Ready | HR1-side enforced |
| D9 | Correlation-ID header (echo) | Pending | documented in hr1-api.md §6; not yet emitted |
| D10 | Generic `Idempotency-Key` header | Pending | natural keys sufficient today (see flow doc) |
| D11 | `Retry-After` header on 429/503 | Pending | text message today |
| D12 | Config-driven rate-limit tuning | Pending | hard-coded today (hr1-api.md §7) |

## E. Documentation & testability

| # | Item | Status | Evidence / Notes |
|---|---|---|---|
| E1 | API contract doc (this set) | Ready | `docs/integration/hr1-api.md` |
| E2 | Data mapping doc with real column names | Ready | `docs/integration/data-mapping.md` |
| E3 | Integration flow doc (apply/auth/HR3/HR2/HR4) | Ready | `docs/integration/integration-flow.md` |
| E4 | Mock contract tester (local, clearly MOCK ONLY) | Ready | `database/mock_contract_tester.php` (label `MOCK ONLY — NOT HR2/HR3/HR4`); run per §run instructions |
| E5 | Declare HR2/HR3/HR4 unknowns as `Pending external API specification` | Ready | throughout docs |
| E6 | Staging + localhost regression green | Ready | rerun in this phase (see Phase-3 run log) |
| E7 | Pre-existing unrelated test failures documented, not masked | Ready | category-1 ×8 / category-4 ×2 at HEAD (unchanged) |

---

## Result

All **Ready** items above mean HR1 can be integrated against today with
confidence. Anything marked **Pending** requires an external owner's decision;
no code should be written against a Pending contract.

**Phase 3 conclusion:** `READY FOR EXTERNAL API SPECIFICATIONS`