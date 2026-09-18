import type { NextFunction, Request, Response } from 'express';
import { getSession } from '@auth/express';
import { authConfig } from '../config/auth.js';

/**
 * Resolves the current Auth.js session (if any) and stashes it at
 * `res.locals.session`. NOT applied to any route yet — this phase only
 * prepares the foundation. Later phases can mount this ahead of protected
 * routers, then use `requireSession` below to enforce it.
 */
export async function attachSession(req: Request, res: Response, next: NextFunction): Promise<void> {
  try {
    res.locals.session = await getSession(req, authConfig);
  } catch {
    res.locals.session = null;
  }
  next();
}

/**
 * Route guard for future use: 401s when no authenticated session is
 * present. Intentionally unused by any router in this phase — RBAC/auth
 * enforcement on individual endpoints remains deferred per project policy.
 */
export async function requireSession(req: Request, res: Response, next: NextFunction): Promise<void> {
  const session = res.locals.session ?? (await getSession(req, authConfig).catch(() => null));
  if (!session?.user) {
    res.status(401).json({ success: false, message: 'Authentication required.' });
    return;
  }
  res.locals.session = session;
  next();
}
