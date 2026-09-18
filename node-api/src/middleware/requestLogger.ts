import type { Request, Response, NextFunction } from 'express';

/** Request logger. */
export function requestLogger(req: Request, res: Response, next: NextFunction): void {
  const started = Date.now();
  res.on('finish', () => {
    const dt = Date.now() - started;
    console.log(`[${new Date().toISOString()}] ${req.method} ${req.originalUrl} -> ${res.statusCode} (${dt}ms)`);
  });
  next();
}