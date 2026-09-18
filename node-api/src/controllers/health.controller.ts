import type { Request, Response } from 'express';
import { getHealth } from '../services/health.service.js';

/**
 * GET /health
 * 200 when the service is up and the database check succeeds;
 * 503 when the service is up but the database is unreachable.
 */
export async function health(_req: Request, res: Response): Promise<void> {
  const status = await getHealth();
  const code = status.db.status === 'up' ? 200 : 503;

  res.status(code).json({
    success: status.db.status === 'up',
    message: status.db.status === 'up' ? 'Service is healthy.' : 'Service is up but the database is unreachable.',
    data: status,
  });
}