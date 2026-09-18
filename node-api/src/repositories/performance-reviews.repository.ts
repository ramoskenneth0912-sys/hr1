import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/**
 * Row shape of the SELECT emitted by PHP `PerformanceController::adminIndex()`:
 * `performance_reviews p` joined with `employees e`, `review_periods rp`, and a
 * `LEFT JOIN users rv` for the reviewer username.
 */
export interface AdminReviewRow extends RowDataPacket {
  id: number;
  employee_id: number;
  period_id: number;
  reviewer_user_id: number | null;
  status: string;
  self_assessment: string | null;
  self_submitted_at: string | null;
  manager_feedback: string | null;
  manager_rating: number | null;
  weight_config: string | null;
  final_rating: number | null;
  final_rating_label: string | null;
  acknowledged_at: string | null;
  acknowledge_note: string | null;
  created_at: string;
  updated_at: string;
  first_name: string;
  last_name: string;
  employee_no: string | null;
  job_title: string | null;
  department_id: number | null;
  period_name: string | null;
  period_type: string | null;
  period_start: string | null;
  period_end: string | null;
  reviewer_username: string | null;
}

/** Optional admin filters, mirroring PHP's `api_query()` branch values. */
export interface AdminReviewsQuery {
  periodId?: number;
  status?: string;
  departmentId?: number;
  employeeId?: number;
}

/**
 * Read-only admin performance-review listing, mirroring the exact SQL emitted by
 * PHP `PerformanceController::adminIndex()` (hr branch — no manager scope filter).
 */
export const performanceReviewsRepository = {
  async listAdmin(query: AdminReviewsQuery): Promise<AdminReviewRow[]> {
    const where: string[] = [];
    const params: Array<string | number> = [];

    if (query.periodId !== undefined) {
      where.push('p.period_id = ?');
      params.push(query.periodId);
    }
    if (query.status !== undefined) {
      where.push('p.status = ?');
      params.push(query.status);
    }
    if (query.departmentId !== undefined) {
      where.push('e.department_id = ?');
      params.push(query.departmentId);
    }
    if (query.employeeId !== undefined) {
      where.push('p.employee_id = ?');
      params.push(query.employeeId);
    }

    const sql = [
      'SELECT p.*, e.first_name, e.last_name, e.employee_no, e.job_title, e.department_id,',
      '       rp.name AS period_name, rp.period_type, rp.start_date AS period_start, rp.end_date AS period_end,',
      '       rv.username AS reviewer_username',
      'FROM performance_reviews p',
      'JOIN employees e ON e.id = p.employee_id',
      'JOIN review_periods rp ON rp.id = p.period_id',
      'LEFT JOIN users rv ON rv.id = p.reviewer_user_id',
      ...(where.length > 0 ? [`WHERE ${where.join(' AND ')}`] : []),
      'ORDER BY rp.end_date DESC, p.id DESC',
    ].join(' ');

    const [rows] = await getPool().query<AdminReviewRow[]>(sql, params);
    return rows;
  },
};
