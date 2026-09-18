import type { Request, Response } from 'express';
import { listAdminRecognitions, getAdminRecognitionById } from '../services/recognition.service.js';

/**
 * GET /api/v1/admin/recognition — admin recognition listing (parity with PHP
 * `RecognitionController::adminIndex()`). Envelope: { success, message, data, meta }.
 * Preserves `page`/`limit` clamping, `status` and `recipient_employee_id` filters,
 * and the `recognition_date DESC, id DESC` ordering.
 *
 * TODO: The PHP version enforces hr/manager access (Auth::requireAdmin), applies
 * the manager `re.manager_id` scope, and returns 403 via the scope checks. Those
 * authorization behaviors stay deferred until Node authentication is migrated —
 * do not trust client-supplied role headers.
 */
export async function index(req: Request, res: Response): Promise<void> {
  const result = await listAdminRecognitions({
    status: req.query.status,
    recipient_employee_id: req.query.recipient_employee_id,
    page: req.query.page,
    limit: req.query.limit,
  });

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
    message: 'Recognition records retrieved successfully.',
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
 * GET /api/v1/admin/recognition/:id — admin recognition detail (parity with
 * PHP `RecognitionController::adminShow()`). Envelope: { success, message, data }.
 *
 * PHP router parity (api/v1/index.php admin block): the show case requires a
 * digit-only third segment, so a non-numeric id never reaches adminShow — it
 * falls through to the admin block default `Endpoint not found.` 404. A missing
 * (or, with the deferred manager scope, out-of-scope) row returns the PHP
 * `Recognition record not found.` 404. Both error envelopes include `errors: {}`.
 *
 * TODO: The HR/manager authentication gate and the manager `assertEmployeeScope()`
 * 403 stay deferred until Node authentication is migrated — do not trust
 * client-supplied role headers.
 */
export async function show(req: Request, res: Response): Promise<void> {
  const rawId = req.params.id;
  if (typeof rawId !== 'string' || !/^\d+$/.test(rawId)) {
    res.status(404).json({ success: false, message: 'Endpoint not found.', errors: {} });
    return;
  }

  const recognition = await getAdminRecognitionById(Number(rawId));
  if (!recognition) {
    res.status(404).json({ success: false, message: 'Recognition record not found.', errors: {} });
    return;
  }

  res.status(200).json({ success: true, message: 'Recognition record retrieved successfully.', data: recognition });
}
