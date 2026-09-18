import { apiRequest } from './api';
import type { ApiSuccess } from './api';

/** Mirrors `HealthStatus` in the Node API (`node-api/src/types/api.ts`). */
export interface HealthStatus {
  service: string;
  version: string;
  environment: string;
  uptimeSeconds: number;
  time: string;
  db: {
    status: 'up' | 'down';
    latencyMs: number;
    error?: string;
  };
}

/** GET /api/v1/health — public, unauthenticated. */
export async function getHealth(): Promise<ApiSuccess<HealthStatus>> {
  return apiRequest<HealthStatus>('/health/health');
}
