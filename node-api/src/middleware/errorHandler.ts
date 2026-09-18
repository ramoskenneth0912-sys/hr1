import type { Request, Response, NextFunction } from 'express';
import { isProduction } from '../config/env.js';

/**
 * Central error handler. Emits the HR1 error envelope and never leaks
 * internals to clients outside development.
 */
export function errorHandler(err: unknown, _req: Request, res: Response, _next: NextFunction): void {
  const template = err instanceof Error ? err.message : String(err);

  res.status(500).json({
    success: false,
    message: 'Internal server error.',
    ...(isProduction ? {} : { errors: { server: template } }),
  });
}