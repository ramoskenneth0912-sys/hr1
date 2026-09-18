import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/** Row shape of a status-count group, mirroring the PHP `countsByStatus()` aggregate. */
export interface StatusCountRow extends RowDataPacket {
  status: string;
  c: number;
}

async function scalar(sql: string): Promise<number> {
  const [rows] = await getPool().query<Array<{ c: number } & RowDataPacket>>(sql);
  return Number(rows[0]?.c ?? 0);
}

/**
 * Read-only dashboard counters, mirroring the exact SQL emitted by the PHP
 * `AdminController::stats()` + `countsByStatus()`.
 */
export const statsRepository = {
  countJobsTotal(): Promise<number> {
    return scalar('SELECT COUNT(*) AS c FROM job_postings');
  },

  countJobsOpen(): Promise<number> {
    return scalar("SELECT COUNT(*) AS c FROM job_postings WHERE status = 'open'");
  },

  countJobsClosed(): Promise<number> {
    return scalar("SELECT COUNT(*) AS c FROM job_postings WHERE status IN ('closed','filled')");
  },

  countApplicantsTotal(): Promise<number> {
    return scalar('SELECT COUNT(*) AS c FROM applicants');
  },

  /** `ORDER BY status` keeps the JSON key order identical to PHP's GROUP BY output. */
  async statusCounts(): Promise<StatusCountRow[]> {
    const [rows] = await getPool().query<StatusCountRow[]>(
      'SELECT status, COUNT(*) AS c FROM applicants GROUP BY status ORDER BY status'
    );
    return rows;
  },
};