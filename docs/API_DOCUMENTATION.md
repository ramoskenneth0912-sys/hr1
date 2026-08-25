# TRI-M GLOBAL HR1 — REST API v1 Documentation

Base URL: `http://localhost/HR1/api/v1`
Format: JSON only (`Content-Type: application/json` for bodies; file uploads use `multipart/form-data`).
Auth: `Authorization: Bearer <token>` (7-day tokens) or an existing website session cookie.

---

## 1. Conventions

### Response envelope
Every response (except `204 No Content`) is:

```json
{
  "success": true,
  "message": "...",
  "data":   { },
  "meta":   { "page": 1, "limit": 10, "total": 5, "total_pages": 1 }
}
```

Errors add `"errors": { "field": "message" }`.

| Status | Meaning |
|-------:|---------|
| 200 OK | Success |
| 201 Created | Resource created |
| 204 No Content | Delete success (empty body) |
| 400 Bad Request | Malformed request / no updatable fields |
| 401 Unauthorized | Missing/invalid token or bad credentials |
| 403 Forbidden | Authenticated but not allowed |
| 404 Not Found | Unknown route/resource (hidden resources also return 404) |
| 405 Method Not Allowed | Wrong HTTP verb on existing endpoint |
| 409 Conflict | Duplicate email/application, FK constraint, closed job |
| 422 Unprocessable Entity | Validation failed |
| 429 Too Many Requests | Rate limit exceeded |
| 500 Server Error | Unexpected error (details in `storage/api/error.log`) |

### Roles
- **hr / manager** = administrators (full control).
- **applicant** = self-service applications.
- **employee** = self-service profile.

### Rate limits (per IP, fixed 15-minute windows)
- All endpoints: 300 requests
- `POST /auth/login`, `POST /auth/register`: 10 attempts
- `POST /applications`: 20 submissions

### Status value mapping
The API accepts friendly aliases and stores canonical enum values:

| Accepted input | Stored value | Display label |
|---|---|---|
| pending, new | new | Pending |
| reviewing, screening | screening | Reviewing |
| shortlisted | shortlisted | Shortlisted |
| interview | interview | Interview |
| accepted, offered | offered | Accepted |
| hired | hired | Hired |
| rejected | rejected | Rejected |

Employment type: `full-time/full_time/fulltime → regular`, `part-time/part_time/parttime → part_time`,
`contract → contractual`, `intern → internship`; native values pass through.

### Pagination
List endpoints accept `page` (default 1) and `limit` (default 10, max 100).

---

## 2. Authentication

### POST /auth/register *(public)*
```json
{ "username": "juan", "email": "juan@example.com", "password": "Passw0rd1",
  "first_name": "Juan", "last_name": "Dela Cruz", "phone": "+63 917 000 0000" }
```
- Password: 8–72 chars, at least one letter + one number.
- Creates a row in the existing `users` table with role `applicant`.
- If `first_name`/`last_name` given, also seeds an `applicants` profile.
- **201** → `{ data: { user: {...}, token, token_type: "Bearer", expires_in_days: 7 } }`
- **409** duplicate username/email · **422** validation

### POST /auth/login *(public)*
```json
{ "credential": "juan OR juan@example.com", "password": "Passw0rd1" }
```
Also accepts `username`/`email` keys instead of `credential`. Optional `device_name` labels the token.
- **200** → user + Bearer token. **401** wrong credentials.

### GET /auth/me
Current user: `{ id, username, email, role, employee_id, created_at }`. Never includes password_hash.

### POST /auth/logout
Revokes the presented Bearer token → `{ data: { revoked: true } }`.

---

## 3. Jobs

### GET /jobs *(public — open positions only)*
Query: `page, limit, search, location, category (department name), department_id, employment_type`
Admins may add `admin_view=1` (+ optional `status=draft|open|closed|filled`) to see everything.

```json
{
  "id": 5, "job_code": "JOB00005", "title": "HR Assistant",
  "department_id": 4, "department": "Human Resources",
  "description": "...", "requirements": "...", "qualifications": "...",
  "required_skills": "...", "education_requirement": "...", "experience_requirement": "...",
  "location": "Makati City, Metro Manila", "employment_type": "regular",
  "vacancies": 1, "status": "open", "posted_date": "2026-08-21", "closing_date": "2026-09-20"
}
```

### GET /jobs/{id}
Public if `open`; other statuses visible to admins only (public gets 404).

### POST /jobs *(hr/manager)*
Required: `title`. Optional: `department_id, description, requirements, qualifications,
required_skills, education_requirement, experience_requirement, work_location,
employment_type (default regular), vacancies (default 1), status (default draft),
posted_date, closing_date (YYYY-MM-DD)`.
`job_code` is auto-generated (`JOB00001` format). Opening with no posted_date sets it today.
**201** with the full record.

### PUT/PATCH /jobs/{id} *(hr/manager)*
PUT requires `title`; PATCH updates only sent fields. Validated fields:
status ∈ draft/open/closed/filled · vacancies ≥ 0 · valid department_id · YYYY-MM-DD dates.

### DELETE /jobs/{id} *(hr/manager)*
Hard delete. **204** empty body; **409** if applications still reference it.

---

## 4. Applications

### POST /applications *(public)*
`multipart/form-data` (JSON without a file is rejected):

| Field | Required | Notes |
|---|---|---|
| job_id | ✔ | Job must exist and be open (**409** otherwise) |
| first_name, last_name, email | ✔ | email unique per job unless previous application was rejected (**409**) |
| phone | – | digits/spaces/+ - ( ) . , 7–20 chars |
| address, education (≤150), skills, work_experience, cover_letter | – | stored in applicants columns |
| resume | ✔ | PDF/DOC/DOCX, ≤ 5 MB, content verified by finfo |

Files are stored in `uploads/api_resumes/{APPxxxxx}_api_{rand}.{ext}` (web access denied via .htaccess).
Authenticated applicants get `user_id` linked automatically.
**201** → application resource. Responses never expose server paths to owners.

### GET /applications *(owner or hr/manager)*
Owners see their own (matched by `user_id` OR email); admins see all with filters:
`email, status (aliases accepted), job_id, search (name/email/applicant_no)`.

### GET /applications/{id}
Owner or admin only — anyone else gets **404** (no existence leak).

### PUT/PATCH /applications/{id}
Owners may edit contact/profile fields while they wish (first_name, last_name, phone, address,
education, skills, work_experience, cover_letter). The application **email cannot be changed**
by owners (**403**). `status` is admin-only here (**403** for owners). PUT requires first/last name.

### DELETE /applications/{id} *(hr/manager)*
Hard delete + **204**.

---

## 5. Admin endpoints *(hr/manager)*

### PUT/PATCH /admin/applications/{id}/status
```json
{ "status": "shortlisted", "notes": "Strong PHP background" }
```
Any alias from the mapping table. Returns the updated status + label.

### GET /admin/applications/{id}/status
Current status snapshot.

### GET /admin/stats
```json
{ "jobs": { "total": 6, "open": 5, "closed": 0 },
  "applications": { "total": 12, "by_status": { "new": {"label":"Pending","count":9}, ... } } }
```

---

## 6. Users

### GET /users *(hr/manager)* — filters: `role=hr|manager|employee|applicant`, `search`

### GET /users/{id} — self or hr/manager (**403** otherwise)

### PUT/PATCH /users/{id}
- Self-service: `email` (unique-checked), plus password change:
  `{ current_password, password }` — non-admins must confirm the current password.
- `role`, `username`, `is_active` are admin-only (**403** for others; use admin flows).

### DELETE /users/{id} *(hr/manager)*
Soft-deactivate (`is_active = 0`) + revokes all their API tokens. Cannot deactivate yourself. **204**.

---

## 7. Security notes
- Tokens are 64-hex random; only SHA-256 hashes are stored (`api_tokens`), TTL 7 days, revocable.
- Expired tokens are lazily purged (~2% of authenticated requests).
- All SQL uses PDO prepared statements; output is JSON-encoded with escaping flags.
- Uploads validated by extension AND finfo MIME sniffing; stored outside web-executable reach.
- Apache strips `Authorization` by default — `.htaccess` re-injects it (`SetEnvIf` + `CGIPassAuth`).
- Errors/exceptions become JSON; stack details go to `storage/api/error.log` only.

## 8. Migration
`database/migrations/001_api_layer.sql` (already applied):
- creates `api_tokens` (user_id FK CASCADE, token_hash CHAR(64) UNIQUE, name, expires_at, revoked_at)
- widens `applicants.status` enum with `shortlisted`
- adds `applicants.education VARCHAR(150)`, `skills TEXT`, `work_experience TEXT`
