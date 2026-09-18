/** Job posting as returned by GET /api/v1/jobs (mirrors PHP JobsController::index SELECT). */
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

export interface JobListFilters {
  search?: string;
  location?: string;
  category?: string;
  department_id?: string;
  employment_type?: string;
  page: number;
  limit: number;
}

export interface JobListResult {
  items: Job[];
  total: number;
}
