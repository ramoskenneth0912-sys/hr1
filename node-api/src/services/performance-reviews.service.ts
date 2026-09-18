import {
  performanceReviewsRepository,
  type AdminReviewRow,
  type AdminReviewsQuery,
} from '../repositories/performance-reviews.repository.js';

/** Copy of PHP `PerformanceController::REVIEW_STATUSES`. */
const REVIEW_STATUSES = ['drafted', 'assigned', 'self_assessment', 'manager_review', 'finalized', 'acknowledged'];

/** Period summary embedded in a review row (PHP `shapeListRow()`'s `period` key). */
export interface AdminReviewPeriodDto {
  id: number;
  name: string | null;
  period_type: string | null;
  start_date: string | null;
  end_date: string | null;
}

/** Employee summary embedded in an admin review row (PHP `shapeListRow()`, admin branch). */
export interface AdminReviewEmployeeDto {
  id: number;
  employee_no: string | null;
  name: string;
  job_title: string | null;
  department_id: number | null;
}

/** A review list row as returned to clients (PHP `PerformanceController::shapeListRow()`, admin=true). */
export interface AdminReviewDto {
  id: number;
  status: string;
  period: AdminReviewPeriodDto;
  self_submitted_at: string | null;
  acknowledged_at: string | null;
  manager_rating: number | null;
  final_rating: number | null;
  final_rating_label: string | null;
  can_submit_self: boolean;
  can_acknowledge: boolean;
  employee: AdminReviewEmployeeDto;
  reviewer_user_id: number | null;
  reviewer_username: string | null;
}

export interface AdminReviewsFilters {
  period_id?: unknown;
  status?: unknown;
  department_id?: unknown;
  employee_id?: unknown;
}

export type AdminReviewsResult =
  | { ok: true; items: AdminReviewDto[] }
  | { ok: false; status: number; message: string; errors: Record<string, string> };

/** PHP `api_query()` parity: trims the value and maps missing/empty to `null`. */
function apiQuery(value: unknown): string | null {
  if (value === undefined || value === null) return null;
  const trimmed = String(value).trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * PHP `PerformanceController::intOrError()` parity: query-string values are
 * always strings here, so only the digit-only-string branch (`/^\d+$/`)
 * applies — no sign, no decimals, leading zeros tolerated (matches PHP).
 */
function intOrError(raw: string): number | false {
  if (!/^\d+$/.test(raw)) return false;
  return Number(raw);
}

function intOrNull(v: number | null): number | null {
  return v === null ? null : Number(v);
}

/** Public JSON shape for one admin review row (PHP `shapeListRow()`, admin=true → never masked). */
export function shapeAdminReview(row: AdminReviewRow): AdminReviewDto {
  const fullName = `${row.first_name} ${row.last_name}`.trim();

  return {
    id: Number(row.id),
    status: row.status,
    period: {
      id: Number(row.period_id),
      name: row.period_name,
      period_type: row.period_type,
      start_date: row.period_start,
      end_date: row.period_end,
    },
    self_submitted_at: row.self_submitted_at,
    acknowledged_at: row.acknowledged_at,
    manager_rating: intOrNull(row.manager_rating),
    final_rating: intOrNull(row.final_rating),
    final_rating_label: row.final_rating === null ? null : row.final_rating_label,
    can_submit_self: row.status === 'self_assessment' && row.self_submitted_at === null,
    can_acknowledge: row.status === 'finalized',
    employee: {
      id: Number(row.employee_id),
      employee_no: row.employee_no,
      name: fullName,
      job_title: row.job_title,
      department_id: intOrNull(row.department_id),
    },
    reviewer_user_id: intOrNull(row.reviewer_user_id),
    reviewer_username: row.reviewer_username,
  };
}

/**
 * Admin performance-review listing (parity with PHP `PerformanceController::adminIndex()`),
 * preserving the `period_id`/`status`/`department_id`/`employee_id` filters (each validated
 * with PHP's `intOrError()`/enum semantics) and the `rp.end_date DESC, p.id DESC` ordering.
 *
 * TODO: HR/manager authentication (`Auth::requireAdmin()`), the manager
 * `(e.manager_id = :mgr_emp OR p.reviewer_user_id = :mgr_usr)` scope filter, stay
 * deferred until Node authentication is migrated. This currently behaves as the hr
 * (unscoped) branch. Input validation is preserved.
 */
export async function listAdminReviews(filters: AdminReviewsFilters): Promise<AdminReviewsResult> {
  const query: AdminReviewsQuery = {};

  const periodIdRaw = apiQuery(filters.period_id);
  if (periodIdRaw !== null) {
    const parsed = intOrError(periodIdRaw);
    if (parsed === false) {
      return { ok: false, status: 422, message: 'Validation failed.', errors: { period_id: 'period_id must be a whole number.' } };
    }
    query.periodId = parsed;
  }

  const status = apiQuery(filters.status);
  if (status !== null) {
    if (!REVIEW_STATUSES.includes(status)) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { status: `status must be one of: ${REVIEW_STATUSES.join(', ')}.` },
      };
    }
    query.status = status;
  }

  const departmentIdRaw = apiQuery(filters.department_id);
  if (departmentIdRaw !== null) {
    const parsed = intOrError(departmentIdRaw);
    if (parsed === false) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { department_id: 'department_id must be a whole number.' },
      };
    }
    query.departmentId = parsed;
  }

  const employeeIdRaw = apiQuery(filters.employee_id);
  if (employeeIdRaw !== null) {
    const parsed = intOrError(employeeIdRaw);
    if (parsed === false) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { employee_id: 'employee_id must be a whole number.' },
      };
    }
    query.employeeId = parsed;
  }

  const rows = await performanceReviewsRepository.listAdmin(query);
  return { ok: true, items: rows.map(shapeAdminReview) };
}
