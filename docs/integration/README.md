# HR1 — Integration Readiness Documentation

**Owner system:** TRI-M GLOBAL Merchandising Management System — **HR1** (Recruitment & HR)
**Status:** `READY FOR EXTERNAL API SPECIFICATIONS`
**Date:** 2026-09-09
**Scope:** Integration contract for future system-to-system integrations HR2 / HR3 / HR4.

---

## 1. Purpose

These documents prepare HR1 to be integrated with external partner systems
(collectively referred to in this workspace as **HR2**, **HR3** and **HR4**)
without requiring changes to HR1 itself.

No HR2 / HR3 / HR4 system was accessed, inspected or modified while producing
this documentation. Everything below is derived from HR1's own code, its
database schema, and live verification against the staging environment.
Any HR2/HR3/HR4-specific field, table or endpoint that is not yet defined by a
published external specification is explicitly marked:

> `Pending external API specification`
> `TBD — subsystem owner`

Those markers mean: **do not implement yet**. The external owner must confirm
the contract before any code is written on either side.

## 2. How to read this document set

| Document | Content |
|---|---|
| [hr1-api.md](./hr1-api.md) | The authoritative HR1 REST API contract: endpoints, methods, authentication, scopes, validation, responses, status codes, rate limits, errors, CORS, versioning |
| [data-mapping.md](./data-mapping.md) | Field-level mapping between API fields and HR1 database tables/columns; entity glossary; proposed HR2/HR3/HR4 mapping |
| [integration-flow.md](./integration-flow.md) | End-to-end sequence flows (apply, authentication, HR3 exam ingest), plus error handling, correlation, idempotency, timeouts/retries |
| [integration-readiness-checklist.md](./integration-readiness-checklist.md) | Checklist of readiness items with status |

Companion tooling:
- `database/mock_contract_tester.php` — **MOCK ONLY — NOT HR2/HR3/HR4.** Local PHP CLI
  contract tester that calls HR1's real `/api/v1` exactly as a partner system would
  (public reads, login, API-key creation/scope enforcement/deletion, exam-boundary
  validation). No data is created; run instructions are in its file header.

Companion documents (pre-existing, unchanged):
- `docs/API_DOCUMENTATION.md` — user-facing API guide (Bearer/session only).
- `docs/openapi.yaml` — OpenAPI 3.0.3 definition (subset: Auth, Jobs, Applications, Users, Admin).
- `api/v1/index.php` — route table (source of truth, contains the full endpoint map).

## 3. What HR1 exposes today

HR1 ships a working REST API at `/api/v1` that already supports three
authentication methods (API key / Bearer token / website session), role-based
and scope-based authorization, JSON error envelopes, and file uploads.
Notable production-grade behaviors already implemented and verified:

- **API keys** (`hr1_...`, SHA-256 hash stored, scopes, expiry, revoke/activate, audit trail).
- **Bearer tokens** (7-day TTL, SHA-256 hash stored, revocable).
- **Public endpoints** (register, login, open-jobs browse, application submit) that are rate-limited.
- **HR3 exam boundary** implemented HR1-side: `POST /exams` (provision) and
  `POST /exam-results` (result ingest) with server-side pass/fail derivation,
  replay protection and job-association validation.
- **Maintenance mode** gate returning JSON `503` (HR-admin bypass).

## 4. Environment reference

| Environment | Base URL (projection) | Notes |
|---|---|---|
| Local development | `https://localhost/HR1/api/v1` | `APP_ENV != production` |
| Staging (verified) | `https://staging.hr1.example.com/api/v1` | `APP_ENV=production`, self-signed cert, verified via `curl --resolve staging.hr1.example.com:443:127.0.0.1 -k` |
| Production | `TBD — subsystem owner` | Must enable/configure `HR1_API_ALLOWED_ORIGINS` if browser-based cross-origin callers are expected |

All staging verification is command-line / server-to-server style
(`X-API-Key`, `Authorization: Bearer`); no browser origin is involved, so CORS
behavior does not affect these integrations.

## 5. Security stance for integrators

- Secrets (API keys, tokens) are shown exactly once at issue time and only
  their SHA-256 hashes are stored. Integrators must store keys in a vault.
- Never send a human password over integration channels; HR1 issues tokens/keys.
- All API responses are JSON; exceptions never leak stack traces to callers
  (details go to `storage/api/error.log`).
- Uploaded resumes are validated by extension **and** MIME sniffing and stored
  outside web-executable reach.
- HR3 (exam provider) is restricted server-side: it can only provide exams and
  results; it cannot create/edit applicants, send emails, or change recruitment
  status.