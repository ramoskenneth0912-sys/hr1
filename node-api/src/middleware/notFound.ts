import type { Request, Response } from 'express';

/** 404 handler — unknown route, HR1 envelope. */
export function notFoundHandler(_req: Request, res: Response): void {
  res.status(404).json({
    success: false,
    message: 'Endpoint not found.',
  });
}