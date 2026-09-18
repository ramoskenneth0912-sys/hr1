import type { Request, Response } from 'express';
import { listAdminGoals, getAdminGoalById } from '../services/goals.service.js';

/**
 * GET /api/v1/admin/goals — admin goal listing (parity with PHP
 * `GoalsController::adminIndex()`). Envelope: { success, message, data, meta }.
 * meta.page = 1, meta.limit = item count, meta.total = item count,
 * meta.total_pages = 1 (matches the PHP Response::list call).
 *
 * Filters preserved: `status` (stored enum), `employee_id` (positive integer).
 * Invalid values return the PHP 422 contract.
 *
 * TODO: The PHP version enforces hr/manager access (Auth::requireAuth + role
 * gate), applies the manager `e.manager_id` scope, and returns 403 for an
 * out-of-scope employee_id. Those authorization behaviors stay deferred until
 * Node authentication is migrated — do not trust client-supplied role headers.
 */
export async function index(req: Request, res: Response): Promise<void> {
  const result = await listAdminGoals(req.query.status, req.query.employee_id);

  if (!result.ok) {
    res.status(result.status).json({
      success: false,
      message: result.message,
      errors: result.errors,
    });
    return;
  }

  const total = result.items.length;
  res.status(200).json({
    success: true,
    message: 'Goals retrieved successfully.',
    data: result.items,
    meta: {
      page: 1,
      limit: total,
      total,
      total_pages: Math.max(1, Math.ceil(total / Math.max(1, total))),
    },
  });
}

/**
 * GET /api/v1/admin/goals/:id — admin goal detail (parity with PHP
 * `GoalsController::adminShow()`). Envelope: { success, message, data }.
 *
 * PHP router parity (api/v1/index.php admin block): the show case requires a
 * digit-only third segment, so a non-numeric id never reaches adminShow — it
 * falls through to the admin block default `Endpoint not found.` 404. A missing
 * (or, with the deferred manager scope, out-of-scope) row returns the PHP
 * `Goal not found.` 404. Both error envelopes include `errors: {}`.
 *
 * TODO: The HR/manager authentication gate and the manager `e.manager_id` scope
 * stay deferred until Node authentication is migrated — do not trust
 * client-supplied role headers.
 */
export async function show(req: Request, res: Response): Promise<void> {
  const rawId = req.params.id;
  if (typeof rawId !== 'string' || !/^\d+$/.test(rawId)) {
    res.status(404).json({ success: false, message: 'Endpoint not found.', errors: {} });
    return;
  }

  const goal = await getAdminGoalById(Number(rawId));
  if (!goal) {
    res.status(404).json({ success: false, message: 'Goal not found.', errors: {} });
    return;
  }

  res.status(200).json({ success: true, message: 'Goal retrieved successfully.', data: goal });
}
