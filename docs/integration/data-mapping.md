# HR1 — Data Mapping Reference

Maps HR1 API fields to real HR1 database tables/columns, verified against the
staging schema (`hr1_staging`, MariaDB 10.4). All column names below are real
and were produced from `information_schema.columns`.

> **Key naming rule:** in HR1 there is **no `applications` table**.
> "Applications" in the API = rows in the **`applicants`** table that carry a
> `job_posting_id` (i.e. a job application). Registration-profile rows without a
> job are also `applicants` rows (they are hidden from `/applications` by a
> `job_posting_id IS NOT NULL` filter).

---

## 1. Entity glossary

| HR1 concept | Table | Notes |
|---|---|---|
| User account | `users` | login identity; roles `applicant`/`employee`/`manager`/`hr`; `employee_id` FK to `employees` |
| Job application | `applicants` | `job_posting_id` non-null ⇒ is an application; `notes` column = cover letter |
| Job posting | `job_postings` | `job_code` (`JOB00001`), status `draft/open/closed/filled` |
| Department | `departments` | `code`, `name` |
| AI screening result | `ai_screening` | match score stored server-side after `POST /applications` |
| Exam (provisioned by HR3) | `exams` | `source_provider='HR3'`, unique `external_ref` |
| Exam assignment (link) | `exam_assignments` | `access_token` (`exm_...`), links applicant→exam→job |
| Exam attempt | `exam_attempts` | one per recorded result; `attempt_number`, `status` |
| Exam result | `exam_results` | `earned_points`, `total_points`, `percentage`, `passed`, unique `external_result_id` |
| API key | `api_keys` | `key_hash` (SHA-256), `key_prefix`, `scopes` (JSON text), lifecycle fields |
| API key audit | `api_key_audit` | every auth attempt + management event |
| Bearer token | `api_tokens` | `token_hash` (SHA-256), `expires_at`, `revoked_at` |

---

## 2. Field-level mapping — core objects

### 2.1 `users` (API: `user` object in `/auth/*`, `/users*`)

| API field | Column | Table | Notes |
|---|---|---|---|
| `id` | `id` | `users` | int |
| `username` | `username` | `users` | unique |
| `email` | `email` | `users` | unique |
| `role` | `role` | `users` | enum `applicant/employee/manager/hr` |
| `employee_id` | `employee_id` | `users` | FK `employees.id`, nullable |
| `is_active` | `is_active` | `users` | soft-delete flag |
| `created_at` | `created_at` | `users` | |

`password_hash` exists in `users` but is **never** exposed by the API.

### 2.2 Job application (API: `/applications`, `/admin/applications/*/status`)

| API field | Column | Table | Notes |
|---|---|---|---|
| `id` | `id` | `applicants` | |
| `applicant_no` | `applicant_no` | `applicants` | unique, `APP00001` format |
| `user_id` | `user_id` | `applicants` | nullable; set only if logged-in applicant submitted |
| `job_id` | `job_posting_id` | `applicants` | FK `job_postings.id`; null ⇒ registration profile (not listed) |
| `job_title` | `job_postings.title` | `job_postings` | via join |
| `department` | `departments.name` | `departments` | via join |
| `first_name` / `last_name` | `first_name` / `last_name` | `applicants` | ≤80 |
| `email` | `email` | `applicants` | owner-lockable (cannot change after submit) |
| `phone` | `phone` | `applicants` | |
| `address` | `address` | `applicants` | |
| `education` | `education` | `applicants` | varchar(150) |
| `skills` | `skills` | `applicants` | text |
| `work_experience` | `work_experience` | `applicants` | text |
| `cover_letter` | `notes` | `applicants` | **API name ≠ column name** |
| `status` | `status` | `applicants` | enum; aliases see hr1-api.md §5 |
| `status_label` | — | — | derived via display map |
| `applied_date` | `applied_date` | `applicants` | date |
| `created_at` / `updated_at` | `created_at` / `updated_at` | `applicants` | |
| `position_applied`, `department_id`, `resume_path` | same names | `applicants` | internal; `resume_path` exposed to admins only (filename portion as `resume_file`) |

### 2.3 Job posting (API: `/jobs*`)

| API field | Column | Table | Notes |
|---|---|---|---|
| `id` | `id` | `job_postings` | |
| `job_code` | `job_code` | `job_postings` | unique `JOB00001` |
| `title` | `title` | `job_postings` | ≤150 |
| `department_id` | `department_id` | `job_postings` | FK `departments.id` |
| `department` | `departments.name` | `departments` | via join |
| `description` / `requirements` | same | `job_postings` | |
| `qualifications`, `required_skills`, `education_requirement`, `experience_requirement` | same | `job_postings` | |
| `location` | `work_location` | `job_postings` | **API name ≠ column name** |
| `employment_type` | `job_employment_type` | `job_postings` | **API name ≠ column name** |
| `vacancies` | `vacancies` | `job_postings` | |
| `status` | `status` | `job_postings` | enum |
| `posted_date` / `closing_date` | same | `job_postings` | date |

### 2.4 Exam objects (HR3 boundary)

| API field | Column | Table |
|---|---|---|
| `title` | `title` | `exams` |
| `reference` (`external_ref`) | `external_ref` | `exams` |
| `passing_score` | `passing_score` (decimal 5,2) | `exams` |
| `time_limit_minutes` | `time_limit_minutes` | `exams` |
| `max_attempts` | `max_attempts` | `exams` |
| `instructions` / `description` | same | `exams` |
| `exam_url` | `exam_url` | `exams` |
| `source` | `source_provider` | `exams` (hard-coded `HR3` on provision) |
| `job_posting_id` / `job_id` | `job_posting_id` | `exams` |
| `access_token` (`exm_...`) | `access_token` | `exam_assignments` |
| `attempt_ref` | `external_ref` | `exam_attempts` |
| `earned_points` / `total_points` | same (decimal 10,2) | `exam_results` |
| `percentage` | `percentage` (decimal 5,2) | `exam_results` |
| `passed` | `passed` (tinyint) | `exam_results` |
| `external_result_id` | `external_result_id` | `exam_results` (unique) |

### 2.5 API keys

| API field | Column | Table |
|---|---|---|
| `id` | `id` | `api_keys` |
| `name` | `name` | `api_keys` |
| `key` (once only) | — | never stored; only `key_hash` (char(64) SHA-256) |
| `key_prefix` | `key_prefix` | `api_keys` |
| `scopes` | `scopes` | `api_keys` (JSON text) |
| `expires_at` | `expires_at` | `api_keys` |
| `is_active`, `last_used_at`, `revoked_at` | same | `api_keys` |

---

## 3. Cross-system mapping (PROPOSED — pending external specifications)

The tables below are **placeholders expressing intent**. No contract value is
invented. Every cell marked `Pending external API specification` /
`TBD — subsystem owner` must be confirmed by the owning system before use.

### 3.1 HR3 — External Examination Provider (closest to ready)

HR1 already implements the **HR1-side** of this boundary (see `integration-flow.md` §4).

| HR1 object | HR3 responsibility | Status |
|---|---|---|
| `exams` provisioning (`POST /exams`) | HR3 sends title/ref/passing_score/url | ✅ Ready (HR1 side) — awaits HR3 spec for field naming |
| `exam_assignments.access_token` | HR3 must present HR1-issued `exm_...` on result ingest | ✅ Ready (HR3 must never use DB ids) |
| `exam_results` ingest (`POST /exam-results`) | HR3 sends `earned_points`/`total_points`/`external_result_id` | ✅ Ready (HR1 side) — HR3 spec pending |
| exam launch URL redirect | HR3 must host `exam_url` (HTTPS) — HR1 redirects applicant there | HR1 ✓ · HR3 `Pending external API specification` |
| candidate identity for assignment | Currently assignment is created HR-internally | `TBD — subsystem owner` |

### 3.2 HR2 — Employee Self-Service / Employee system (`Pending external API specification`)

| Intended purpose | HR1 anchor | Status |
|---|---|---|
| Employee record sync (create/update `employees`) | `employees` table (exists; no API resource) | Pending external spec |
| ESS authentication parity | `users.role = 'employee'`, `users.is_active` | Pending external spec |
| Payroll / HRIS downstream consumption | `employees.*` (incl. `019_employee_salary_information.sql`) | Pending external spec |
| Scopes `employees:read` / `employees:write` | defined in `ApiKeyAuth::VALID_SCOPES`, **no route yet** | Pending — do not rely on |

### 3.3 HR4 — (`TBD — subsystem owner`)

| Intended purpose | HR1 anchor | Status |
|---|---|---|
| Unspecified by external owner | — | Pending external API specification |

No field/table mapping can be produced for HR4 until the owning subsystem
publishes its contract.

---

## 4. Semantic notes for integrators

1. `applicants.status` canonical enum: `new`, `screening`, `shortlisted`, `interview`,
   `offered`, `hired`, `rejected`. HR1 accepts friendly aliases on input; output is canonical.
2. `exams.external_ref` and `exam_results.external_result_id` are **unique** — they are the
   natural idempotency keys (confirmed via `SHOW INDEX`).
3. `exam_assignments.access_token` format is `exm_` + 48 lowercase hex. Treat it as an opaque
   credential, not a lookup key.
4. The API hides ownership: querying another applicant's id returns `404`, not `403`.
5. Dates are passed as `YYYY-MM-DD` (dates) and `Y-m-d H:i:s` (timestamps); all app-local time.