import type { Request, Response } from 'express';
import { getAdminPerformanceOptions } from '../services/performance.service.js';

/**
 * GET /api/v1/admin/performance/options?employee_id= — candidate goals + rating
 * options (parity with PHP `PerformanceController::options()`). Envelope:
 * { success, message, data } (no meta), matching `Response::item()`.
 *
 * TODO: The PHP version enforces Auth::requireAdmin() and the manager
 * `checkEmployeeScope()` guard. Those authorization behaviors stay deferred
 * until Node authentication is migrated — do not trust client-supplied role
 * headers.
 */
export async function options(req: Request, res: Response): Promise<void> {
  const result = await getAdminPerformanceOptions(req.query.employee_id);

  if (!result.ok) {
    res.status(result.status).json({
      success: false,
      message: result.message,
      errors: result.errors,
    });
    return;
  }

  res.status(200).json({
    success: true,
    message: 'Options retrieved successfully.',
    data: result.data,
  });
}
