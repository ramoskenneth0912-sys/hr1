import type { Request, Response } from 'express';
import { listDepartments } from '../services/departments.service.js';

/**
 * GET /api/v1/departments — full department listing (parity with PHP
 * `DepartmentsController::index()`). Envelope: { success, message, data, meta }.
 * meta.page = 1, meta.limit = item count, meta.total = item count,
 * meta.total_pages = 1 (matches the PHP Response::list call).
 *
 * TODO: Employee/HR role guards (Auth::requireAdmin()) are deferred because
 * Node does not yet have the migrated HR1 authentication/role context.
 * Do NOT trust arbitrary query parameters or headers for roles.
 */
export async function index(_req: Request, res: Response): Promise<void> {
  const { items, total } = await listDepartments();
  res.status(200).json({
    success: true,
    message: 'Departments retrieved successfully.',
    data: items,
    meta: {
      page: 1,
      limit: total,
      total,
      total_pages: Math.max(1, Math.ceil(total / Math.max(1, total))),
    },
  });
}