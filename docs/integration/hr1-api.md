# HR1 REST API v1 — Integration Contract

**Base path:** `/api/v1`
**Format:** JSON (`Content-Type: application/json`). Multipart (`multipart/form-data`) only for resume upload on `POST /applications`.
**Source of truth:** `api/v1/index.php` + `api/v1/controllers/*` (this document mirrors that code).
**Pre-existing companion:** `docs/API_DOCUMENTATION.md` (Bearer/session view) and `docs/openapi.yaml`.

---

## 1. Transport & security headers

Every response carries:

- `Content-Type: application/json; charset=utf-8`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`

Errors and responses are JSON; HTML is never produced by `/api/v1`.

### CORS (browser cross-origin only)

- Same-origin requests and non-browser (system-to-system) callers are unaffected by CORS.
- Allowed origins come from `HR1_API_ALLOWED_ORIGINS` (comma-separated env).
- In production, **if that env is empty, cross-origin CORS is disabled entirely**.
- In non-production, `localhost` Vite origins are additionally admitted.
- Allowed origin is echoed only when the request `Origin` matches the allowlist
  (plus `Vary: Origin`), with credentials. Preflight `OPTIONS` → `204`.
- Integration guidance: HR2/HR3/HR4 run server-side and must NOT rely on CORS;
  for browser-hosted integrations, HR must set `HR1_API_ALLOWED_ORIGINS` explicitly.

### Maintenance gate

After authentication, a JSON `503` is returned while maintenance mode is
active (`FULL` = all endpoints blocked; `LIMITED` = `jobs`/`applications`
only). HR-role callers bypass the gate.

---

## 2. Response envelope

All responses except `204 No Content`:

```json
{
  "success": true,
  "message": "Human-readable summary",
  "data": { },
  "meta": { "page": 1, "limit": 10, "total": 5, "total_pages": 1 }
}
```

Errors:

```json
{
  "success": false,
  "message": "Human-readable error",
  "errors": { "field": "message" }
}
```

`errors` is an object keyed by field; an empty object is serialized as `{}`.
When no field errors exist, `errors` is `{}`.

### Status codes

| Code | Meaning |
|---|---|
| 200 | Success |
| 201 | Created |
| 204 | Deleted / no body |
| 400 | Malformed request, no updatable fields |
| 401 | Missing/invalid token or bad credentials (`WWW-Authenticate: Bearer realm="HR1 API"`) |
| 403 | Authenticated but not allowed |
| 404 | Unknown route, hidden resource, or not found (hidden resources hide as 404) |
| 405 | Wrong method on existing endpoint |
| 409 | Duplicate email/application, FK conflict, closed job, already-revoked key, duplicate external exam reference/result |
| 422 | Validation failure (field errors in `errors`) |
| 429 | Rate limit exceeded |
| 500 | Unexpected error (details in `storage/api/error.log`, never in the response) |
| 503 | Maintenance mode / temporarily unavailable |

### Pagination

List endpoints: `?page=1&limit=10` (limit max 100). Response `meta` mirrors `page`, `limit`, `total`, `total_pages`.

---

## 3. Authentication & authorization

Three independent methods. Resolution priority, **no cross-fallback**:

1. `X-API-Key: hr1_...` → system identity + scopes.
2. `Authorization: Bearer <token>` → user identity + role.
3. PHP session cookie → website frontend user identity + role.

An invalid API key, or an invalid/expired Bearer, produces `401` and **never**
falls back to another method.

### 3.1 API keys (system-to-system)

- Format: `hr1_` + base64url(48 bytes) → 64 characters. Only SHA-256 hashes are stored.
- Presented via `X-API-Key` header.
- Authorization is **scope-based**; map below. Bearer/session roles do not apply.
- Failed API-key auth counts toward a per-IP failure bucket (10 per 15 min → `429`).
- Full audit rows land in `api_keys`/`api_key_audit` (`last_used_at` updated on success).

Valid scopes:

| Scope | Meaning | Enforced today |
|---|---|---|
| `applicants:read` | Read applicant/application data | `GET /applications*` |
| `applicants:write` | Create/update/delete applications | `PUT/PATCH/DELETE /applications/{id}`, `DELETE /applications` |
| `jobs:read` | Read job postings | Public `GET /jobs` (scope not required; `admin_view` user-only) |
| `jobs:write` | Create/update/delete job postings | `POST/PUT/PATCH/DELETE /jobs*` |
| `users:read` | List/view user accounts | **None** — `/users` rejects API keys (see §7) |
| `users:write` | Create/update/delete users | **None** |
| `employees:read` | Read employee data | **None** (no `/employees` route) |
| `employees:write` | Create/update/delete employee data | **None** |
| `departments:read` | List departments | **None** — `/departments` rejects API keys (see §7) |
| `exams:read` | Read exams/exam results | `GET /exams*`, `GET /exam-results` |
| `exams:write` | Provision exams (HR3 boundary) | `POST /exams` |
| `exams:results:write` | Ingest exam results (HR3 boundary) | `POST /exam-results` |
| `admin:read` | View dashboard statistics | **None** — `/admin` rejects API keys |
| `admin:write` | Change application statuses | **None** |

> **Integration notice:** API keys are the only allowed method for HR3-style
> system ingestion. Keys cannot register/login users, and cannot manage
> themselves (`/api-keys` is user-auth only).

### 3.2 Bearer tokens (user-based)

- Issued by `POST /auth/login` and `POST /auth/register`.
- TTL 7 days (`expires_in_days`), stored as SHA-256 hash in `api_tokens`, revocable via `POST /auth/logout`.
- Roles: `hr`, `manager` (= administrators), `applicant`, `employee`.

### 3.3 Role/scope decision table per route family

| Endpoint family | `[public]` | Bearer/session allowed | API key allowed |
|---|---|---|---|
| `/auth/register`, `/auth/login` | yes (rate-limited) | n/a (they issue tokens) | **never** |
| `/auth/me`, `/auth/logout` | no | any authenticated user | **never** |
| `/jobs` GET | yes (open jobs only) | hr/manager all; applicant yes; **employee 403** | works (public read; `admin_view` is user-admin only) |
| `/jobs` write | no | hr/manager only | key with `jobs:write` |
| `/applications` GET | no | owner or hr/manager; **employee 403** | key with `applicants:read` (admin-level view) |
| `/applications` POST | yes (rate-limited) | n/a (optionally links logged-in applicant) | n/a (public) |
| `/applications` PUT/PATCH | no | owner (limited fields) or hr/manager | key with `applicants:write` (admin-level) |
| `/applications` DELETE | no | hr/manager | key with `applicants:write` |
| `/admin/*` | no | hr/manager only | **rejected (user auth required)** |
| `/users` | no | self or hr/manager | **rejected (user auth only; scopes unused)** |
| `/departments` | no | hr/manager only | **rejected (user auth only)** |
| `/exams`, `/exam-results` | no | hr/manager | key with `exams:*` (HR3 path) |
| `/api-keys` | no | hr/manager only | **never** |

---

## 4. Rate limits

All per-IP, fixed 15-minute windows (file-based counter under `/storage/api/rate_limits`).

| Bucket | Limit |
|---|---|
| Global (all endpoints) | 300 / 900 s |
| `register` | 10 / 900 s |
| `login` | 10 / 900 s |
| `apply` (`POST /applications`) | 20 / 900 s |
| API-key attempts (`apikey_fail:{ip}`) | 10 / 900 s — see note below |

> **API-key bucket nuance (verified behavior):** the counter named `apikey_fail`
> actually increments on **every** request that carries a well-formed API key —
> successful authentications included — not only failures. An IP that makes
> more than 10 API-key calls in any 15-minute window gets `429` even with a
> valid key. Realistic HR3 ingest volumes (provision + a few result reads) fit
> comfortably, but bursty shared egress IPs can trip it. Widening this to
> count real failures only is a documented `Pending` enhancement; the counter
> file is under `/storage/api/rate_limits/`.

Exceeding → `429 Too Many Requests`. Counter files persist current window state.

---

## 5. Endpoint catalog

Notation: **auth mode** = `public` · `user` (Bearer/session) · `api-key` (X-API-Key with scope) · `user|key`.

Reusable value aliases:

- **Status**: accept `pending|new` → `new`; `reviewing|screening` → `screening`; `shortlisted`; `interview`; `accepted|offered` → `offered`; `hired`; `rejected`. Output uses stored status + `status_label`.
- **Employment type**: `full-time|full_time|fulltime` → `regular`; `part-time|part_time|parttime` → `part_time`; `contract|contractual` → `contractual`; `intern|internship` → `internship`.
- **Dates**: `YYYY-MM-DD`.

### 5.1 Auth

**`POST /auth/register`** — `public`, 10/900 s.

```json
{ "username": "juan", "email": "juan@example.com", "password": "Passw0rd1",
  "first_name": "Juan", "last_name": "Dela Cruz", "phone": "+63 917 000 0000" }
```

- `username` `[A-Za-z0-9_.]{3,50}`; `email` valid; `password` 8–72 chars with ≥1 letter and ≥1 digit.
- Optional `first_name`/`last_name` (≤80) also seed an `applicants` profile row.
- Creates `users` row with role `applicant`. `201`:
```json
{ "data": { "user": { "id": 33, "username": "juan", "email": "juan@example.com", "role": "applicant" },
  "token": "<64 hex>", "token_type": "Bearer", "expires_in_days": 7 } }
```
- `409` duplicate username/email · `422` validation.

**`POST /auth/login`** — `public`, 10/900 s.

```json
{ "credential": "juan OR juan@example.com", "password": "Passw0rd1", "device_name": "optional" }
```

- `credential` accepts `username`, or the aliases `username`/`email` keys.
- Active users only (`is_active = 1`).
- `200` → same shape as register (token nested inside `data`).
- `401` invalid credentials · `422` missing fields.

**`GET /auth/me`** — `user`. Returns `{ id, username, email, role, employee_id, created_at }`. Never includes `password_hash`.

**`POST /auth/logout`** — `user`. Revokes the presented Bearer token → `{ data: { revoked: true } }`.

### 5.2 Jobs

**`GET /jobs`** — `public` (open only) / `user|key`.

- Public (no auth): only `status=open` rows.
- hr/manager users: optional `admin_view=1` + `status=draft|open|closed|filled` to see everything
  (`admin_view` is user-role-only; API-key callers cannot enable it).
- **employees → 403** (cannot browse jobs via API).
- Filters: `search`, `location`, `category` (department name), `department_id`, `employment_type`, plus `page`, `limit`.

**`GET /jobs/{id}`** — `public` if open; hr/manager may read other statuses; the public gets `404` for non-open jobs; **employees → 403**.

**`POST /jobs`** — `user|key` (`jobs:write`); body fields:

`title` (required, ≤150), `department_id` (must exist), `description`, `requirements`, `qualifications`, `required_skills`, `education_requirement`, `experience_requirement`, `work_location`, `employment_type` (alias→canonical), `vacancies` (int ≥0), `status` (`draft|open|closed|filled`), `posted_date`, `closing_date`.

- `job_code` auto-generated (`JOB00001` style). Opening sets `posted_date` to today if absent. `201` full record.

**`PUT/PATCH /jobs/{id}`** — `user|key` (`jobs:write`). PUT requires `title`; PATCH updates only sent fields. Same validation.

**`DELETE /jobs/{id}`** — `user|key` (`jobs:write`). Hard delete → `204`. FK conflicts → `409`.

### 5.3 Applications

**`POST /applications`** — `public`, 20/900 s; `multipart/form-data` OR JSON (a file is mandatory, so multipart in practice).

| Field | Req | Rules |
|---|---|---|
| `job_id` | ✔ | must exist and be `open` (else `409`/`422`) |
| `first_name`, `last_name`, `email` | ✔ | ≤80; email valid |
| `phone` | – | `[0-9+\-\s().]{7,20}` |
| `education` | – | ≤150 |
| `skills`, `work_experience`, `cover_letter` | – | ≤2000 / 3000 / 4000 |
| `resume` (file) | ✔ | PDF/DOC/DOCX, 1 B–5 MB, MIME-sniffed |

- Duplicate guard: same email + same job unless prior application is `rejected` → `409`.
- Upload stored as `uploads/api_resumes/{APP...}_api_{rand}.{ext}` (web-denied).
- Optional profile link: logged-in applicant gets `user_id` set.
- On success, HR1 runs AI screening (`autoScreenApplicant`) — result stored; submission is never dependent on it.
- `201` application resource. Resume path exposed to admins only (filename only).

**`GET /applications`** — `user|key` (`applicants:read`).

- Owner (applicant): own rows (match `user_id` OR email). hr/manager or API-key: all rows.
- **employees → 403**. Non-owner, non-admin, non-applicant → `403`.
- Filter (admin): `email`, `status` (aliases), `job_id`, `search` (name/email/applicant_no); + `page`, `limit`.
- Only rows with a job attached (`job_posting_id IS NOT NULL`) are listed.

**`GET /applications/{id}`** — owner or hr/manager or API-key; anyone else → `404` (no existence leak).

**`PUT/PATCH /applications/{id}`** — owner or `user|key` (`applicants:write`).

- Owner may edit: `first_name`, `last_name`, `phone`, `address`, `education`, `skills`, `work_experience`, `cover_letter`.
- Owner **cannot** change `email` (403) nor `status` (403; status changes via `/admin`).
- PUT requires `first_name`/`last_name`.

**`DELETE /applications/{id}`** — hr/manager or key (`applicants:write`). Hard delete → `204`.

### 5.4 Admin

**`GET /admin/stats`** — `user` (hr/manager). Returns job + application counters:
```json
{ "jobs": { "total": 6, "open": 5, "closed": 0 },
  "applications": { "total": 12, "by_status": { "new": { "label": "Pending", "count": 9 } } } }
```

**`GET /admin/applications/{id}/status`** — `user` (hr/manager). Status snapshot (`status`, `status_label`, `updated_at`).

**`PUT|PATCH /admin/applications/{id}/status`** — `user` (hr/manager).

```json
{ "status": "shortlisted", "notes": "Strong PHP background" }
```

- Any status alias; notifies the applicant via in-app notification on change.
- `notes` (≤4000) updates the applicant `notes` (cover letter) column when provided.

### 5.5 Users

**`GET /users`** — hr/manager only (Bearer/session). Filters `role=hr|manager|employee|applicant`, `search`. API-key `users:read` route exists but `/users` currently requires `requireAdmin()` (user auth), see §7 note.

**`GET /users/{id}`** — self or hr/manager (`403` otherwise). `password_hash` never returned.

**`PUT/PATCH /users/{id}`** — self or hr/manager.

- Self: `email` (unique-checked); password change `{ current_password, password }` with current-password confirmation for non-admins (8–72 chars, letter+digit).
- `role`, `username`, `is_active` are admin-only (non-admins → 403; admins should use admin flows).

**`DELETE /users/{id}`** — hr/manager. Soft-deactivate (`is_active=0`) + revoke all tokens. Cannot deactivate self. → `204`.

### 5.6 Departments

**`GET /departments`** — hr/manager only (Bearer/session); API-key callers are rejected (403). Returns `{ id, code, name }` list.

### 5.7 Exams — HR3 provisioning boundary

**`POST /exams`** — `user|key` (`exams:write`; intended system path = API key). Body:

```json
{ "title": "PHP Developer Assessment", "reference": "REF-2026-0001",
  "job_posting_ref": "JOB00005", "passing_score": 75,
  "time_limit_minutes": 60, "max_attempts": 1,
  "instructions": "...", "description": "...", "exam_url": "https://hr3.example.com/exams/abc" }
```

- `title` required ≤200; `reference` (`external_ref`) required ≤120, **UNIQUE — duplicate → 409** (idempotency/replay protection, unique index `uq_exams_external_ref`).
- `job_posting_ref` optional; resolves by `job_code`, `title`, or numeric id; unknown → 422.
- `exam_url` optional, HTTPS-only (`https` scheme, valid URL, ≤500).
- Passing score 0<x≤100; time limit 1–720; max attempts 1–10.
- `source_provider` hard-coded `HR3`; `status=active`. `201`:
```json
{ "id": 3, "title": "...", "reference": "REF-2026-0001", "source": "HR3",
  "job_posting_id": 5, "status": "active", "created": true }
```

**`GET /exams`** — `user|key` (`exams:read`). List with `job_title`, `job_code`.

**`GET /exams/{id}`** — `user|key` (`exams:read`). Full exam row; `404` unknown.

### 5.8 Exam results — HR3 result boundary

**`POST /exam-results`** — `user|key` (`exams:results:write`; intended system path = API key).

```json
{ "access_token": "exm_<48 hex>", "earned_points": 45.5, "total_points": 60,
  "external_result_id": "HR3-RESULT-778", "attempt_ref": "optional", "scored_at": "2026-09-09 10:00:00" }
```

- `access_token` is the opaque HR1-issued assignment reference (`exm_...`); raw DB ids are never accepted.
- Pass/fail & percentage are **derived server-side** from `earned/total` vs the exam's `passing_score` — external `passed`/`percentage` are never trusted.
- Rejections: `assignment_finalised` (409), `duplicate_result` (409), `duplicate_external` (409 — `external_result_id` unique), `max_attempts` (422), `job_mismatch` (422), invalid score bounds (422), `exam_not_configured` (422).
- Success `201`:
```json
{ "assignment_ref": "exm_...", "attempt_ref": "...", "result_id": 4, "applicant_no": "APP00012",
  "job": { "job_code": "JOB00005", "title": "..." },
  "exam": { "title": "...", "source_provider": "HR3" },
  "percentage": 75.83, "passed": true, "interview_eligible": true }
```

**`GET /exam-results?access_token=exm_...`** — `user|key` (`exams:read`). Returns recorded result + `interview_eligible`; `recorded:false` when none yet.

### 5.9 API key management

All endpoints `user` (hr/manager) only — API keys cannot manage themselves.

| Endpoint | Purpose |
|---|---|
| `POST /api-keys` | Create key → plaintext `key` shown **once**. Body `{ name, scopes[], expires_in_days? }` |
| `GET /api-keys` | List (no `key_hash`) |
| `GET /api-keys/{id}` | Single key |
| `PATCH /api-keys/{id}` | Update `name`, `scopes`, `expires_in_days`, `is_active` |
| `POST /api-keys/{id}/revoke` | Soft revocation |
| `POST /api-keys/{id}/activate` | Re-activate |
| `DELETE /api-keys/{id}` | Permanent delete |
| `GET /api-keys/scopes` | Valid scopes + descriptions |

---

## 6. Errors, correlation, idempotency, retries

### Error contract (for integrators)
- Always check HTTP status first; parse `errors` for field-level detail.
- `409` means "retry after correcting state" (dedupe), not a transient fault.
- `429` means "slow down" — honor the bucket (15 min); integrators should add jitter+backoff.
- `500`/`503` mean "service side" — a safe retry window applies (see below).

### Correlation IDs
HR1 does not currently emit/sink a request correlation ID header (`X-Correlation-ID`)
at the API boundary. Documented decision for this phase:
- **Documented, not implemented.** A correlation-ID header that is echoed on request/response
  is a *future enhancement* (`Pending`). HR3-style ingestion today is made safe by the
  idempotency keys that DO exist: `external_ref` (exams) and `external_result_id` (results).

### Idempotency
HR1 provides **natural idempotency via unique keys** on the ingestion boundaries:

| Operation | Idempotency mechanism |
|---|---|
| `POST /exams` | unique `exams.external_ref` → second call returns `409` (reject, not overwrite) |
| `POST /exam-results` | unique `exam_results.external_result_id` → `409`; also one result per assignment |
| `POST /applications` | one active application per email+job → `409` |

Client-driven `Idempotency-Key` headers are **not** implemented (`Pending`), because each
write already has a natural key. A generic idempotency layer is a candidate enhancement
once external specs exist.

### Timeouts & retries (recommended for integrators)
- Connect timeout: 10 s. Read/response timeout: 30 s.
- Retry only on `5xx`/transport errors; never on `4xx`.
- Backoff: 1 s → 2 s → 4 s (max 3 retries) with jitter; honor `Retry-After` when provided.
- On `409` (idempotency hit), treat as success: fetch the existing resource and continue.

---

## 7. Audit observations (recorded during this phase)

1. **API-key-usable resources today are limited** to `/jobs` (write), `/applications`,
   `/exams` and `/exam-results`. `/admin/*`, `/users`, `/departments`, `/api-keys` and
   `/auth/me|logout` enforce user auth (`requireAuth()`/`requireAdmin()` with API-key
   rejection), so the scopes `users:read`, `users:write`, `departments:read`,
   `admin:read`, `admin:write`, `employees:read`, `employees:write` — and the
   `jobs:read` read scope on public `GET /jobs` — have **no effective API-key route**
   today. Only `applicants:*`, `jobs:write`, and `exams:*` are enforced on API keys.
   Scope definitions are not wasted (ready for future routes), but integrators must
   not assume a scope grants access until its resource route allows API keys.
2. **No `/employees` API resource exists** (ESS is web-only); `employees:*` scopes are reserved.
3. **`ApiKeyAuth::requiredScopeFor()`** exists but is currently unused (routes call
   `requireScope()` explicitly). No behavioral impact.
4. **Rate-limiter file counter** is per-IP; NATed corporate egress can share a bucket.
   Limits are hard-coded in `api/v1/bootstrap.php` (global 300/900 s) and in the
   controllers (login/register 10, apply 20, API-key attempts 10). Config-driven
   limits are a `Pending` enhancement.
5. **No generic HTTP `Retry-After` header** is emitted on `429` (message text only). `Pending` if integrators require it.
6. **Discovered & fixed in this phase:** `ApiKeyAuth::generateKey()` produced a
   `key_prefix` value of 16 chars (`substr($key,0,12).'****'`) against a
   `VARCHAR(12)` column — every `POST /api-keys` failed with `SQLSTATE 1406`
   (`Data too long`). Fixed by shortening the display prefix to
   `substr($key,0,8).'****'` (12 chars). API-key creation is now verified working.