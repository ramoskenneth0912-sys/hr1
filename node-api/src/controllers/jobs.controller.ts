import type { Request, Response } from 'express';
import { listJobs, getJobById } from '../services/jobs.service.js';
import type { JobsFilters } from '../services/jobs.service.js';

/**
 * GET /api/v1/jobs — public OPEN listing (parity with PHP `JobsController::index()`
 * public branch). Envelope: { success, message, data, meta }.
 *
 * TODO: Employee-role 403 parity is blocked because Node does not yet have
 * the migrated HR1 authentication/role context. The PHP version uses
 * Auth::role() from session; Node has no equivalent middleware yet.
 * Do NOT trust arbitrary query parameters or headers for roles.
 */
export async function index(req: Request, res: Response): Promise<void> {
  const result = await listJobs(req.query as JobsFilters, req.query.page, req.query.limit);
  res.status(200).json({
    success: true,
    message: 'Open jobs retrieved successfully',
    data: result.items,
    meta: {
      page: result.page,
      limit: result.limit,
      total: result.total,
      total_pages: result.totalPages,
    },
  });
}

/**
 * GET /api/v1/jobs/:id — public OPEN job detail (parity with PHP
 * `JobsController::show()` public branch). Envelope: { success, message, data }.
 * Missing or non-OPEN ids return the PHP 404 contract
 * { success: false, message: 'Job not found.', errors: {} }. (Non-numeric
 * segments never reach this handler — the route mirrors the PHP router.)
 *
 * TODO: Employee-role 403 parity is blocked because Node does not yet have
 * the migrated HR1 authentication/role context.
 */
export async function show(req: Request, res: Response): Promise<void> {
  const rawId = req.params.id;
  const notFound = (): void => {
    res.status(404).json({ success: false, message: 'Job not found.', errors: {} });
  };

  if (typeof rawId !== 'string' || !/^\d+$/.test(rawId)) {
    notFound();
    return;
  }
  const job = await getJobById(Number(rawId));
  if (!job) {
    notFound();
    return;
  }
  res.status(200).json({ success: true, message: 'Job retrieved successfully', data: job });
}
