import { performanceRepository } from '../repositories/performance.repository.js';

/** A candidate goal as returned to clients (PHP `options()` goal mapping). */
export interface CandidateGoalDto {
  id: number;
  title: string;
  category: string | null;
  status: string;
  progress: number;
  due_date: string | null;
  target: string | null;
}

/** A rating option as returned to clients (PHP `options()` rating mapping). */
export interface RatingOptionDto {
  scale_code: string;
  value: number;
  label: string;
}

export interface AdminPerformanceOptionsDto {
  employee_id: number;
  goals: CandidateGoalDto[];
  ratings: RatingOptionDto[];
}

export type AdminPerformanceOptionsResult =
  | { ok: true; data: AdminPerformanceOptionsDto }
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

/**
 * Admin performance options (parity with PHP `PerformanceController::options()`):
 * candidate goals + rating scale for a given employee, keyed by a required
 * `employee_id` query parameter.
 *
 * TODO: HR/manager authentication (Auth::requireAdmin) and the manager
 * `checkEmployeeScope()` guard (403 for an out-of-scope employee) stay
 * deferred until Node authentication is migrated. This currently behaves as
 * the hr (unscoped) branch. The required/positive `employee_id` validation is
 * preserved.
 */
export async function getAdminPerformanceOptions(employeeIdParam: unknown): Promise<AdminPerformanceOptionsResult> {
  const raw = apiQuery(employeeIdParam);
  const employeeId = raw === null ? 0 : phpIntCast(raw);
  if (employeeId <= 0) {
    return {
      ok: false,
      status: 422,
      message: 'Validation failed.',
      errors: { employee_id: 'employee_id query parameter is required.' },
    };
  }

  const [goalRows, ratingRows] = await Promise.all([
    performanceRepository.candidateGoalsForEmployee(employeeId),
    performanceRepository.ratingOptions(),
  ]);

  return {
    ok: true,
    data: {
      employee_id: employeeId,
      goals: goalRows.map((g) => ({
        id: Number(g.id),
        title: g.title,
        category: g.category,
        status: g.status,
        progress: Number(g.progress),
        due_date: g.due_date,
        target: g.target,
      })),
      ratings: ratingRows.map((r) => ({
        scale_code: r.scale_code,
        value: Number(r.value),
        label: r.label,
      })),
    },
  };
}
