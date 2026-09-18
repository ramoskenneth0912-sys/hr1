import { statsRepository } from '../repositories/stats.repository.js';

/** Copy of the PHP `Auth::statusDisplayMap()`. */
const STATUS_DISPLAY_MAP: Record<string, string> = {
  new: 'Pending',
  screening: 'Reviewing',
  shortlisted: 'Shortlisted',
  interview: 'Interview',
  offered: 'Accepted',
  hired: 'Hired',
  rejected: 'Rejected',
};

/** PHP `ucfirst($status)` parity for statuses absent from the display map. */
function displayLabel(status: string): string {
  return STATUS_DISPLAY_MAP[status] ?? status.charAt(0).toUpperCase() + status.slice(1);
}

export interface DashboardStats {
  jobs: {
    total: number;
    open: number;
    closed: number;
  };
  applications: {
    total: number;
    by_status: Record<string, { label: string; count: number }>;
  };
}

/**
 * GET /admin/stats — dashboard counters (parity with PHP `AdminController::stats()`).
 *
 * TODO: The PHP version enforces Auth::requireAdmin() (hr/manager role). That
 * guard stays deferred until Node authentication is migrated.
 */
export async function getDashboardStats(): Promise<DashboardStats> {
  const [jobsTotal, jobsOpen, jobsClosed, applicationsTotal, statusRows] = await Promise.all([
    statsRepository.countJobsTotal(),
    statsRepository.countJobsOpen(),
    statsRepository.countJobsClosed(),
    statsRepository.countApplicantsTotal(),
    statsRepository.statusCounts(),
  ]);

  const by_status: Record<string, { label: string; count: number }> = {};
  for (const row of statusRows) {
    by_status[row.status] = { label: displayLabel(row.status), count: Number(row.c) };
  }

  return {
    jobs: { total: jobsTotal, open: jobsOpen, closed: jobsClosed },
    applications: { total: applicationsTotal, by_status },
  };
}