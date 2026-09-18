import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/** Row shape of a candidate goal, mirroring PHP `PerformanceController::options()`. */
export interface CandidateGoalRow extends RowDataPacket {
  id: number;
  title: string;
  category: string | null;
  status: string;
  progress: number;
  due_date: string | null;
  target: string | null;
}

/** Row shape of one rating option, mirroring the `rating_options` table columns used. */
export interface RatingOptionRow extends RowDataPacket {
  scale_code: string;
  value: number;
  label: string;
}

/**
 * Read-only queries backing `admin/performance/options`, mirroring the exact
 * SQL emitted by the PHP `PerformanceController::options()`.
 */
export const performanceRepository = {
  async candidateGoalsForEmployee(employeeId: number): Promise<CandidateGoalRow[]> {
    const sql =
      'SELECT id, title, category, status, progress, due_date, target ' +
      'FROM employee_goals WHERE employee_id = ? ORDER BY due_date, id';
    const [rows] = await getPool().query<CandidateGoalRow[]>(sql, [employeeId]);
    return rows;
  },

  async ratingOptions(): Promise<RatingOptionRow[]> {
    const [rows] = await getPool().query<RatingOptionRow[]>(
      'SELECT scale_code, value, label FROM rating_options ORDER BY scale_code, sort_order'
    );
    return rows;
  },
};
