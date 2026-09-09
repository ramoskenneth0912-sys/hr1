# HR1 — Integration Flows

End-to-end sequence flows across the HR1 API boundary. Each flow states who
the actors are, which real endpoints are used, the ordering constraints, and
the failure modes. HR2/HR3/HR4 specifics are marked
`Pending external API specification` where HR1 cannot yet know them.

---

## 1. Flow Legend

- **HR1** — this system (recruitment + HR), owns all applicant communication.
- **HR2** — employee-related subsystem (`Pending external API specification`).
- **HR3** — external examination provider. HR1-side contract is **implemented**
  (see §4); HR3's own spec remains pending.
- **HR4** — `TBD — subsystem owner`; no flow defined.
- **[public]** / **[user]** / **[api-key]** = endpoint auth mode per hr1-api.md.

---

## 2. Public Applicant — Apply Flow

Actors: **Candidate** (browser), **HR1**.

```
Candidate            HR1
   |   GET /jobs           |      [public] open jobs
   |---------------------->|
   |<----------------------|  200 { jobs }   (open only)
   |   POST /applications  |      [public] multipart (job_id, names, email, resume)
   |---------------------->|
   |                       |  validate job open + email uniqueness + resume ext/MIME
   |                       |  insert applicants row (job_posting_id set)
   |                       |  autoScreenApplicant() -> ai_screening (never blocks)
   |<----------------------|  201 { application } (created)
   |   POST /auth/login    |      [public] (optional account creation first)
   |---------------------->|
   |<----------------------|  200 { data.token }
   |   GET /applications   |      [user] owner scope
   |---------------------->|
   |<----------------------|  200 { own applications }
```

Failure modes: closed job `409` · duplicate email+job `409` · bad resume `422`
· missing file `422` · rate limit `429`.

Note: an applicant can also *register* (`POST /auth/register`) and then apply
with the account, which links `applicants.user_id` to `users.id`. Registration
is optional for applying.

---

## 3. Authentication Options for Integrators

```
Integrator system                        HR1
   system-to-system:
   |   POST /api-keys                     (human HR admin, [user])
   |<------------------------------------  201 { key }   store in vault (shown once)
   |   GET /jobs   X-API-Key: hr1_...    [api-key]
   |------------------------------------>
   |<------------------------------------  200
   user-based (e.g. an HR2 employee portal calling on behalf of a user):
   |   POST /auth/login                  [public]
   |------------------------------------>
   |<------------------------------------  200 { data.token }
   |   GET /auth/me   Authorization: Bearer <token>   [user]
   |------------------------------------>
   |<------------------------------------  200 { user }
```

Rules: API keys cannot impersonate users, cannot register/login, and cannot
manage other keys. Bearer tokens expire after 7 days and are revocable.
If a token is compromised, `DELETE /users/{id}` (as admin) revokes all of a
user's tokens, or the user calls `POST /auth/logout`.

---

## 4. HR3 — Exam Provisioning & Result Ingest (implemented HR1-side)

### 4.1 Provision an exam

Actors: **HR3** (service), **HR1**.

```
HR3                    HR1
   | POST /exams                 [api-key] scope exams:write
   | { title, reference, passing_score, exam_url, job_posting_ref?, ... } |
   |---------------------------->|
   |                             | validate + resolve job by ref
   |                             | unique external_ref check
   |                             | INSERT exams (source_provider='HR3', status='active')
   |<----------------------------| 201 { id, reference, source, created:true }
```

- Second call with the same `reference` → `409` (idempotency; do not overwrite).
- `exam_url` must be HTTPS and is what HR1 later redirects the applicant to;
  HR3 must keep it live.
- HR3 must **never** email the applicant directly — HR1 owns communication.

### 4.2 Assign the exam to an applicant

HR-internal today (`includes/exam.php` `assignExamToApplicant()`); HR3 does not
assign. Assignment produces `exam_assignments.access_token` (`exm_...`),
which is the handle HR3 uses for result ingest.

> `Pending external API specification`: whether HR3 will request assignments or
> be informed of them (webhook vs. read) is undecided.

### 4.3 Ingest a result

```
HR3                    HR1
   | POST /exam-results          [api-key] scope exams:results:write
   | { access_token: exm_..., earned_points, total_points,
   |   external_result_id, attempt_ref?, scored_at? }
   |---------------------------->|
   |                             | resolve assignment by token (never by DB id)
   |                             | re-validate applicant->exam->job association
   |                             | derive percentage + passed server-side
   |                             | guards: not finalised / no duplicate / unique external_result_id / max_attempts
   |                             | INSERT exam_attempts + exam_results
   |<----------------------------| 201 { passed, percentage, interview_eligible }
```

- Passes mark the applicant eligible for the Final Interview
  (`interview_eligible: true`) but do **not** auto-hire; an HR user schedules
  the interview.
- A replayed `external_result_id` or a second result on the same assignment
  → `409` with a stable code (`duplicate_result` / `duplicate_external`).
- HR1 returns the correct `409`/`422` status via the `code` mapping:
  `assignment_finalised|duplicate_result|duplicate_external` → `409`,
  everything else → `422`, `server_error` → `500`.

### 4.4 Read back a result

```
HR3 (or HR)
   | GET /exam-results?access_token=exm_...   [api-key] scope exams:read
   |----------------------------------------->
   |<----------------------------------------- 200 { score, passed, interview_eligible, recorded:true|false }
```

---

## 5. HR2 — Employee Self-Service flows (`Pending external API specification`)

Intended (not yet implemented, HR1-side hooks exist):

- Employee records live in `employees`; identity in `users.role='employee'`.
- The API currently **forbids** `employee` role on `/jobs` and `/applications`
  (ESS is served by the web module, not the API). Any HR2 integration that needs
  employee-facing API access must first agree a spec that lifts or complements this.
- Scope names `employees:read`/`employees:write` are reserved but have **no route**
  (`Pending`). Do not build against them yet.

## 6. HR4 flow (`TBD — subsystem owner`)

No HR4 specification is available. Hold until the external owner publishes one.

---

## 7. Cross-cutting integration concerns

### 7.1 Correlation
HR1 does not yet emit/echo an `X-Correlation-ID`. Until it does, integrators
should use the natural idempotency keys:
- exam provisioning → `exams.external_ref`
- result ingest → `exam_results.external_result_id`
- application submit → `applicants(email, job_posting_id)`

If HR2/HR3/HR4 require a gateway/e2e trace header, that is a documented
`Pending` enhancement (single header echo point in `api/v1/bootstrap.php`).

### 7.2 Timeouts
HR1 actions are synchronous SQL operations (write + optional AI screening on
application submit). Realistic budgets: connect ≤10 s, read ≤30 s. Application
submit includes `autoScreenApplicant()` which may add a few seconds; do not
time out below 30 s on `POST /applications`.

### 7.3 Retry guidance
- Retry only transport failures and `5xx`/`503`.
- Backoff ladder 1 s/2 s/4 s + jitter, max 3 attempts; respect `429` by waiting
  at least the remaining 15-min window (or 60 s with graceful degradation).
- On `409`, fetch the existing resource (list by `reference`/`external_result_id`)
  and treat the operation as already-completed.

### 7.4 Security of the integration channel
- TLS 1.2+ required (`exam_url` enforced HTTPS by HR1; all API calls should be HTTPS).
- API keys are long-lived but revocable — rotate via `POST /api-keys/{id}/revoke`
  then create a replacement.
- Every failed API-key auth attempt is audited to `api_key_audit` with IP + UA;
  a 15-min failure window throttles brute force.