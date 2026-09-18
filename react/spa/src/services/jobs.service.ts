import { apiRequest, NodeApiError } from './api';
import type { ApiSuccess } from './api';

/** Mirrors `Job` in the Node API (`node-api/src/types/jobs.ts`). */
export interface Job {
  id: number;
  job_code: string;
  title: string;
  department_id: number | null;
  department: string | null;
  description: string;
  requirements: string;
  qualifications: string;
  required_skills: string;
  education_requirement: string;
  experience_requirement: string;
  location: string;
  employment_type: string;
  vacancies: number;
  status: string;
  posted_date: string | null;
  closing_date: string | null;
  created_at: string;
  updated_at: string;
}

export interface JobFilters {
  search?: string;
  location?: string;
  category?: string;
  department_id?: string;
  employment_type?: string;
  page?: number;
  limit?: number;
}

/** GET /api/v1/jobs — public OPEN job listing, no authentication required. */
export async function getJobs(filters: JobFilters = {}): Promise<ApiSuccess<Job[]>> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '') params.set(key, String(value));
  }
  const query = params.toString();
  return apiRequest<Job[]>(`/jobs${query ? `?${query}` : ''}`);
}

/**
 * GET /api/v1/jobs/:id — public OPEN job detail, no authentication required.
 * Returns `null` (not a thrown error) when the API responds 404, so callers
 * can render a dedicated "not found" state instead of a generic error.
 */
export async function getJobById(id: number | string): Promise<Job | null> {
  try {
    const res = await apiRequest<Job>(`/jobs/${id}`);
    return res.data;
  } catch (err) {
    if (err instanceof NodeApiError && err.status === 404) return null;
    throw err;
  }
}
