import type { Request, Response } from 'express';
import { getDashboardStats } from '../services/admin.service.js';

/**
 * GET /api/v1/admin/stats — hr/manager dashboard counters (parity with PHP
 * `AdminController::stats()`). Envelope: { success, message, data } (no meta).
 *
 * TODO: The PHP version enforces Auth::requireAdmin(). That guard stays
 * deferred until Node authentication is migrated — do not trust client-supplied
 * role headers.
 */
export async function stats(_req: Request, res: Response): Promise<void> {
  const data = await getDashboardStats();
  res.status(200).json({
    success: true,
    message: 'Dashboard statistics retrieved successfully.',
    data,
  });
}