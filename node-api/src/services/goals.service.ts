import { goalsRepository, type AdminGoalRow } from '../repositories/goals.repository.js';

/** Statuses stored in employee_goals (PHP `GoalsController::ADMIN_STATUSES`). */
const ADMIN_STATUSES = ['not_started', 'in_progress', 'completed', 'cancelled'];

/** A goal as returned to clients (PHP `GoalsController::adminShape()`). */
export interface AdminGoalDto {
  id: number;
  employee_id: number;
  employee_no: string | null;
  employee_name: string;
  title: string;
  description: string | null;
  category: string | null;
  target: string | null;
  start_date: string | null;
  due_date: string | null;
  progress: number;
  status: string;
  priority: string;
  notes: string | null;
  assigned_by: number | null;
  created_at: string;
  updated_at: string;
}

export type AdminGoalsResult =
  | { ok: true; items: AdminGoalDto[] }
  | { ok: false; status: number; message: string; errors: Record<string, string> };

/** PHP `api_query()` parity: trims the value and maps missing/empty to `null`. */
function apiQuery(value: unknown): string | null {
  if (value === undefined || value === null) return null;
  const trimmed = String(value).trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * PHP `filter_var($v, FILTER_VALIDATE_INT)` parity for the trimmed integer forms
 * `api_query()` can yield: optional sign, no leading zeros, no decimals/exponents.
 */
export function parsePhpInt(raw: string): number | false {
  if (!/^[+-]?(0|[1-9][0-9]*)$/.test(raw)) return false;
  const value = Number(raw);
  return Number.isSafeInteger(value) ? value : false;
}

/** PHP `date('Y-m-d')` equivalent using the Node process timezone. */
export function todayIsoDate(now: Date = new Date()): string {
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/**
 * Public JSON shape for one admin goal (parity with PHP `adminShape()`), including
 * the read-time `overdue` display status derived from `due_date`.
 */
export function shapeAdminGoal(row: AdminGoalRow, today: string = todayIsoDate()): AdminGoalDto {
  let status = row.status;
  if (!['completed', 'cancelled'].includes(status) && row.due_date !== null && row.due_date < today) {
    status = 'overdue';
  }

  return {
    id: Number(row.id),
    employee_id: Number(row.employee_id),
    employee_no: row.employee_no,
    employee_name: `${row.first_name} ${row.last_name}`.trim(),
    title: row.title,
    description: row.description,
    category: row.category,
    target: row.target,
    start_date: row.start_date,
    due_date: row.due_date,
    progress: Number(row.progress),
    status,
    priority: row.priority,
    notes: row.notes,
    assigned_by: row.assigned_by === null ? null : Number(row.assigned_by),
    created_at: row.created_at,
    updated_at: row.updated_at,
  };
}

/**
 * Admin goal listing (parity with PHP `GoalsController::adminIndex()`), applying
 * the same `status`/`employee_id` filters and ORDER BY.
 *
 * TODO: HR/manager authentication (Auth::requireAuth + role gate), the manager
 * `e.manager_id` scope filter, and the employee-in-scope 403
 * (`assertAdminEmployeeScope`) stay deferred until Node authentication is
 * migrated. This currently behaves as the hr (unscoped) branch. Input validation
 * (`status` enum, integer/positive `employee_id`) is preserved.
 */
export async function listAdminGoals(statusParam: unknown, employeeIdParam: unknown): Promise<AdminGoalsResult> {
  const status = apiQuery(statusParam);
  if (status !== null && !ADMIN_STATUSES.includes(status)) {
    return { ok: false, status: 422, message: 'Validation failed.', errors: { status: 'Invalid goal status.' } };
  }

  let employeeId: number | undefined;
  const rawEmployeeId = apiQuery(employeeIdParam);
  if (rawEmployeeId !== null) {
    const parsed = parsePhpInt(rawEmployeeId);
    if (parsed === false) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { employee_id: 'Employee ID must be an integer.' },
      };
    }
    if (parsed <= 0) {
      return {
        ok: false,
        status: 422,
        message: 'Validation failed.',
        errors: { employee_id: 'Employee ID must be positive.' },
      };
    }
    employeeId = parsed;
  }

  const rows = await goalsRepository.listAdmin({ status: status ?? undefined, employeeId });
  return { ok: true, items: rows.map((row) => shapeAdminGoal(row)) };
}

/**
 * Single admin goal detail (parity with PHP `GoalsController::adminShow()`).
 * Returns `null` when no row matches — the controller maps that to the PHP
 * `Goal not found.` 404 envelope.
 *
 * TODO: The HR/manager authentication gate and the manager `e.manager_id` scope
 * in `adminGoalScope()` stay deferred until Node authentication is migrated;
 * this currently behaves as the hr (unscoped) branch.
 */
export async function getAdminGoalById(id: number): Promise<AdminGoalDto | null> {
  const row = await goalsRepository.findAdminByPk(id);
  return row ? shapeAdminGoal(row) : null;
}
