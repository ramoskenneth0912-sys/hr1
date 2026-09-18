import { getToken } from './token';

const BASE = '/HR1/api/v1';

export interface ApiResponse<T = unknown> {
  success: boolean;
  message?: string;
  data: T;
  meta?: {
    page: number;
    limit: number;
    total: number;
    total_pages: number;
  };
  errors?: Record<string, string>;
}

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

export async function request<T = unknown>(path: string, options: RequestInit = {}): Promise<ApiResponse<T>> {
  const token = getToken();
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string> | undefined),
  };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const res = await fetch(BASE + path, {
    credentials: 'same-origin',
    headers,
    ...options,
  });

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    if (res.status === 401) {
      // Session/Bearer expired — the AuthContext re-checks and routes to login.
      window.dispatchEvent(new Event('hr1:unauthorized'));
    }
    throw new ApiError(data.message || `Request failed with status ${res.status}`, res.status);
  }

  return data as ApiResponse<T>;
}

export function getOpenJobs() {
  return request('/jobs');
}