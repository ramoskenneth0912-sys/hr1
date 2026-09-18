import { jobsRepository, type JobsQuery, type JobDto } from '../repositories/jobs.repository.js';

/** Canonical employment-type aliases (PHP `Auth::EMPLOYMENT_ALIASES` copy). */
const EMPLOYMENT_ALIASES: Record<string, string> = {
  regular: 'regular',
  'full-time': 'regular',
  full_time: 'regular',
  fulltime: 'regular',
  contractual: 'contractual',
  contract: 'contractual',
  probationary: 'probationary',
  part_time: 'part_time',
  'part-time': 'part_time',
  parttime: 'part_time',
  internship: 'internship',
  intern: 'internship',
};

/** Unknown values return `null` (PHP parity: UNKNOWN must NOT become a filter). */
export function normalizeEmployment(raw: unknown): string | null {
  if (raw === null || raw === undefined) return null;
  const key = String(raw).trim().toLowerCase();
  return EMPLOYMENT_ALIASES[key] ?? null;
}

export interface JobsFilters {
  search?: unknown;
  location?: unknown;
  category?: unknown;
  department_id?: unknown;
  employment_type?: unknown;
}

/** WHERE builder mirroring the PHP index() OPEN branch using positional ? placeholders. */
function buildJobsQuery(filters: JobsFilters): JobsQuery {
  const where: string[] = [];
  const params: Array<string | number> = [];

  where.push("j.status = 'open'");

  const search = filters.search === undefined ? undefined : String(filters.search).trim();
  if (search !== undefined && search !== '') {
    where.push('(j.title LIKE ? OR j.description LIKE ? OR j.required_skills LIKE ?)');
    params.push(`%${search}%`, `%${search}%`, `%${search}%`);
  }

  const location = filters.location === undefined ? undefined : String(filters.location).trim();
  if (location !== undefined && location !== '') {
    where.push('j.work_location LIKE ?');
    params.push(`%${location}%`);
  }

  const category = filters.category === undefined ? undefined : String(filters.category).trim();
  if (category !== undefined && category !== '') {
    where.push('d.name LIKE ?');
    params.push(`%${category}%`);
  }

  const dept = filters.department_id === undefined ? undefined : String(filters.department_id).trim();
  if (dept !== undefined && dept !== '' && /^\d+$/.test(dept)) {
    where.push('j.department_id = ?');
    params.push(Number(dept));
  }

  const empType = normalizeEmployment(filters.employment_type);
  if (empType !== null) {
    where.push('j.job_employment_type = ?');
    params.push(empType);
  }

  return {
    whereSql: `WHERE ${where.join(' AND ')}`,
    params,
  };
}

export interface JobsListResult {
  items: JobDto[];
  total: number;
  page: number;
  limit: number;
  totalPages: number;
}

/** PHP-compatible integer cast: non-numeric strings become 0 (matches PHP `(int)` cast). */
function toInt(raw: unknown): number {
  if (raw === undefined || raw === null) return 0;
  const n = parseInt(String(raw), 10);
  return Number.isNaN(n) ? 0 : n;
}

/** Paginated public OPEN listing (parity with PHP index()). */
export async function listJobs(
  filters: JobsFilters,
  pageParam: unknown,
  limitParam: unknown
): Promise<JobsListResult> {
  const page = Math.max(1, toInt(pageParam));
  // PHP parity: api_query() returns null for a missing OR literally-empty value,
  // so `?limit=` falls back to the default 10, while whitespace-only still casts to 0.
  const rawLimit =
    limitParam === undefined || limitParam === null || limitParam === '' ? 10 : toInt(String(limitParam).trim());
  const limit = Math.max(1, Math.min(100, rawLimit));
  const offset = (page - 1) * limit;
  const query = buildJobsQuery(filters);
  const total = await jobsRepository.count(query);
  const items = await jobsRepository.list(query, limit, offset);
  const totalPages = Math.max(1, Math.ceil(total / limit));
  return { items, total, page, limit, totalPages };
}

export type { JobDto } from '../repositories/jobs.repository.js';

/**
 * Single public OPEN job detail (parity with PHP `JobsController::show()` public
 * branch). Non-open jobs are hidden from the public: `null` is returned both for
 * a missing row and for a row that is not `open`.
 *
 * TODO: Employee-role 403 parity is blocked because Node does not yet have the
 * migrated HR1 authentication/role context (see controller index TODO).
 */
export async function getJobById(id: number): Promise<JobDto | null> {
  const job = await jobsRepository.findByPk(id);
  return job && job.status === 'open' ? job : null;
}
