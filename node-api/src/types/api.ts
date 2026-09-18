/**
 * Shared API contract types.
 *
 * Mirrors the existing HR1 PHP/JSON envelope so migrated endpoints behave
 * identically: `{ success, message, data?, meta?, errors? }`.
 */

export interface ApiMeta {
  page: number;
  limit: number;
  total: number;
  total_pages: number;
}

export interface ApiSuccess<T> {
  success: true;
  message: string;
  data: T;
  meta?: ApiMeta;
}

export interface ApiFailure {
  success: false;
  message: string;
  errors?: Record<string, string>;
}

export type ApiResponse<T> = ApiSuccess<T> | ApiFailure;

/** Database dependency status reported by the health endpoint. */
export interface DbStatus {
  status: 'up' | 'down';
  latencyMs: number;
  error?: string;
}

/** Payload of GET /health. */
export interface HealthStatus {
  service: string;
  version: string;
  environment: string;
  uptimeSeconds: number;
  time: string;
  db: DbStatus;
}