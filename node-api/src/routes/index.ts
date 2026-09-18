import { Router } from 'express';
import { ExpressAuth } from '@auth/express';
import { env } from '../config/env.js';
import { authConfig } from '../config/auth.js';
import healthRoutes from './health.routes.js';
import { jobsRouter } from './jobs.routes.js';
import { departmentsRouter } from './departments.routes.js';
import { adminRouter } from './admin.routes.js';

/**
 * API route aggregator.
 * Phase 2 mounts the migrated read-only endpoints under `env.apiBase`
 * (matches PHP `api/v1/index.php` mounts): GET /health, GET /jobs,
 * GET /departments, GET /admin/stats, GET /admin/goals, GET /admin/recognition,
 * GET /admin/performance/options, GET /admin/performance/reviews. More mirrors
 * (employee/*) arrive in later phases.
 *
 * `/auth/*` mounts the Auth.js (@auth/express) foundation — Credentials
 * sign-in against the existing `users` table (see `config/auth.ts`). No
 * endpoint currently requires the resulting session; this is groundwork
 * only, per the Step 2 scope.
 */
export function buildApiRouter(): Router {
  const api = Router();
  api.use('/health', healthRoutes);
  api.use('/auth', ExpressAuth(authConfig));
  api.use('/jobs', jobsRouter);
  api.use('/departments', departmentsRouter);
  api.use('/admin', adminRouter);
  return api;
}

export function buildRootRouter(): Router {
  const root = Router();
  if (env.apiBase) {
    root.use(env.apiBase, buildApiRouter());
  }
  return root;
}
