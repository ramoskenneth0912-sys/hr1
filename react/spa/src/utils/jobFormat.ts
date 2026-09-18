import type { Job } from '@/services/jobs.service';

/** Human-readable labels for the `job_employment_type` enum (schema.sql). */
const EMPLOYMENT_TYPE_LABELS: Record<string, string> = {
  regular: 'Regular',
  contractual: 'Contractual',
  probationary: 'Probationary',
  part_time: 'Part-time',
  internship: 'Internship',
};

export function formatEmploymentType(value: string | null | undefined): string | null {
  if (!value) return null;
  return EMPLOYMENT_TYPE_LABELS[value] ?? value;
}

export function isJobOpen(job: Pick<Job, 'status'>): boolean {
  return job.status === 'open';
}

export function formatJobDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
