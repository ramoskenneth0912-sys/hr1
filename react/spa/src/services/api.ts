/**
 * Centralized REST client for the NEW Node.js/Express API (Step 2/3
 * migration foundation).
 *
 * This is intentionally separate from `@/lib/api.ts`, which talks to the
 * existing PHP API (`/HR1/api/v1`) and still drives the live login,
 * dashboard, and ESS routes. Both clients run side by side during the
 * incremental migration — nothing here touches the PHP-backed flow.
 */

export const API_BASE_URL: string =
  (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://localhost:4000/api/v1';

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

export class NodeApiError extends Error {
  status: number;
  errors?: Record<string, string>;

  constructor(message: string, status: number, errors?: Record<string, string>) {
    super(message);
    this.name = 'NodeApiError';
    this.status = status;
    this.errors = errors;
  }
}

/**
 * Issues a request against the Node API and unwraps its `{ success, data }`
 * envelope. `credentials: 'include'` is always sent so the Auth.js session
 * cookie (set under `/auth/*`) is attached once a user signs in, even for
 * endpoints that don't currently require it.
 */
export async function apiRequest<T = unknown>(path: string, options: RequestInit = {}): Promise<ApiSuccess<T>> {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'include',
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.headers as Record<string, string> | undefined),
    },
  });

  const body = (await res.json().catch(() => ({}))) as Partial<ApiResponse<T>>;

  if (!res.ok || body.success !== true) {
    const failure = body as Partial<ApiFailure>;
    throw new NodeApiError(failure.message ?? `Request failed with status ${res.status}`, res.status, failure.errors);
  }

  return body as ApiSuccess<T>;
}
