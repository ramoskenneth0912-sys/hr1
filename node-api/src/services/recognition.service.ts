import {
  recognitionRepository,
  type AdminRecognitionRow,
  type AdminRecognitionQuery,
} from '../repositories/recognition.repository.js';

/** Copy of PHP `RecognitionController::STATUSES`. */
const STATUSES = ['draft', 'published', 'archived'];

/** A recognition record as returned to clients (PHP `RecognitionController::adminShape()`). */
export interface AdminRecognitionDto {
  id: number;
  category: string;
  title: string;
  message: string;
  recognition_date: string;
  given_by: string | null;
  status: string;
  recipient_employee_id: number;
  recipient_employee_no: string | null;
  recipient_name: string;
  created_at: string;
  updated_at: string;
}

export interface AdminRecognitionFilters {
  status?: unknown;
  recipient_employee_id?: unknown;
  page?: unknown;
  limit?: unknown;
}

export type AdminRecognitionResult =
  | { ok: true; items: AdminRecognitionDto[]; page: number; limit: number; total: number; totalPages: number }
  | { ok: false; status: number; message: string; errors: Record<string, string> };

/** PHP `api_query()` parity: trims the value and maps missing/empty to `null`. */
function apiQuery(value: unknown): string | null {
  if (value === undefined || value === null) return null;
  const trimmed = String(value).trim();
  return trimmed === '' ? null : trimmed;
}

/** PHP `(int)` cast parity for a trimmed string (leading numeric prefix, truncates). */
function phpIntCast(raw: string): number {
  const match = raw.match(/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/);
  if (match === null) return 0;
  const value = Number(match[0]);
  return Number.isNaN(value) ? 0 : Math.trunc(value);
}

/** PHP `filter_var($v, FILTER_VALIDATE_INT)` parity for `api_query()` output. */
export function parsePhpInt(raw: string): number | false {
  if (!/^[+-]?(0|[1-9][0-9]*)$/.test(raw)) return false;
  const value = Number(raw);
  return Number.isSafeInteger(value) ? value : false;
}

/** Public JSON shape for one recognition row (PHP `RecognitionController::shape()` + `adminShape()`). */
export function shapeAdminRecognition(row: AdminRecognitionRow): AdminRecognitionDto {
  const issuer = `${row.issuer_first_name ?? ''} ${row.issuer_last_name ?? ''}`.trim();

  return {
    id: Number(row.id),
    category: row.category,
    title: row.title,
    message: row.message,
    recognition_date: row.recognition_date,
    given_by: issuer !== '' ? issuer : (row.issuer_username ?? null),
    status: row.status,
    recipient_employee_id: Number(row.recipient_employee_id),
    recipient_employee_no: row.recipient_employee_no,
    recipient_name: `${row.recipient_first_name} ${row.recipient_last_name}`.trim(),
    created_at: row.created_at,
    updated_at: row.updated_at,
  };
}

/**
 * Admin recognition listing (parity with PHP `RecognitionController::adminIndex()`),
 * preserving `page`/`limit` clamping, `status`/`recipient_employee_id` filters and
 * the `recognition_date DESC, id DESC` ordering.
 *
 * TODO: HR/manager authentication (Auth::requireAdmin), the manager `re.manager_id`
 * scope filter, and the 403 scope checks stay deferred until Node authentication is
 * migrated. This currently behaves as the hr (unscoped) branch. Input validation is
 * preserved.
 */
export async function listAdminRecognitions(filters: AdminRecognitionFilters): Promise<AdminRecognitionResult> {
  const pageRaw = apiQuery(filters.page);
  const page = Math.max(1, pageRaw === null ? 1 : phpIntCast(pageRaw));
  const limitRaw = apiQuery(filters.limit);
  const limit = Math.min(100, Math.max(1, limitRaw === null ? 25 : phpIntCast(limitRaw)));

  const status = apiQuery(filters.status);
  const recipientRaw = apiQuery(filters.recipient_employee_id);
  let recipientId: number | null = null;
  if (recipientRaw !== null) {
    const parsed = parsePhpInt(recipientRaw);
    if (parsed === false) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { recipient_employee_id: 'Recipient employee ID must be an integer.' },
      };
    }
    recipientId = parsed;
  }

  const where: string[] = [];
  const params: Array<string | number> = [];

  // Manager `re.manager_id` scope is deferred with the auth layer (hr branch: none).

  if (status !== null) {
    if (!STATUSES.includes(status)) {
      return { ok: false, status: 422, message: 'Validation failed.', errors: { status: 'Invalid recognition status.' } };
    }
    where.push('r.status = ?');
    params.push(status);
  }
  if (recipientId !== null) {
    if (recipientId <= 0) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { recipient_employee_id: 'Recipient employee ID must be positive.' },
      };
    }
    where.push('r.recipient_employee_id = ?');
    params.push(recipientId);
  }

  const query: AdminRecognitionQuery = {
    whereSql: where.length > 0 ? ` WHERE ${where.join(' AND ')}` : '',
    params,
  };

  const total = await recognitionRepository.count(query);
  const offset = (page - 1) * limit;
  const rows = await recognitionRepository.list(query, limit, offset);

  return {
    ok: true,
    items: rows.map((row) => shapeAdminRecognition(row)),
    page,
    limit,
    total,
    totalPages: Math.max(1, Math.ceil(total / Math.max(1, limit))),
  };
}

/**
 * Single admin recognition detail (parity with PHP `RecognitionController::adminShow()`).
 * Returns `null` when no row matches — the controller maps that to the PHP
 * `Recognition record not found.` 404 envelope.
 *
 * TODO: The HR/manager authentication gate and the manager `assertEmployeeScope()`
 * check (403 for an out-of-scope recipient) stay deferred until Node
 * authentication is migrated; this currently behaves as the hr (unscoped) branch.
 */
export async function getAdminRecognitionById(id: number): Promise<AdminRecognitionDto | null> {
  const row = await recognitionRepository.findAdminByPk(id);
  return row ? shapeAdminRecognition(row) : null;
}
