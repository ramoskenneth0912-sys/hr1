import type { Request, Response } from 'express';
import { listAdminReviews } from '../services/performance-reviews.service.js';

/**
 * GET /api/v1/admin/performance/reviews — admin review listing (parity with PHP
 * `PerformanceController::adminIndex()`). Envelope: { success, message, data, meta }.
 * meta.page = 1, meta.limit = item count, meta.total = item count,
 * meta.total_pages = 1 (matches the PHP `Response::list()` call).
 *
 * Filters preserved: `period_id`, `department_id`, `employee_id` (whole numbers,
 * PHP `intOrError()` semantics) and `status` (stored enum). Invalid values return
 * the PHP 422 contract.
 *
 * TODO: The PHP version enforces hr/manager access (`Auth::requireAdmin()`) and
 * applies the manager `(e.manager_id = :mgr_emp OR p.reviewer_user_id = :mgr_usr)`
 * scope filter. Those authorization behaviors stay deferred until Node
 * authentication is migrated — do not trust client-supplied role headers.
 */
export async function index(req: Request, res: Response): Promise<void> {
  const result = await listAdminReviews({
    period_id: req.query.period_id,
    status: req.query.status,
    department_id: req.query.department_id,
    employee_id: req.query.employee_id,
  });

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
    message: 'Performance reviews retrieved successfully.',
    data: result.items,
    meta: {
      page: 1,
      limit: total,
      total,
      total_pages: Math.max(1, Math.ceil(total / Math.max(1, total))),
    },
  });
}
