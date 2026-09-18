import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/**
 * Row shape of the `employee_goals g INNER JOIN employees e` SELECT emitted by
 * the PHP `GoalsController::adminIndex()`. `g.*` plus the three employee columns.
 */
export interface AdminGoalRow extends RowDataPacket {
  id: number;
  employee_id: number;
  employee_no: string | null;
  first_name: string;
  last_name: string;
  title: string;
  description: string | null;
  category: string | null;
  target: string | null;
  start_date: string | null;
  due_date: string | null;
  progress: number;
  status: string;
  priority: string;
  notes: string | null;
  assigned_by: number | null;
  created_at: string;
  updated_at: string;
}

/** Optional admin filters, mirroring PHP's `api_query()` branch values. */
export interface AdminGoalsQuery {
  status?: string;
  employeeId?: number;
}

/**
 * Read-only admin goal listing, mirroring the exact SQL emitted by the PHP
 * `GoalsController::adminIndex()` (hr branch — no manager scope filter).
 */
export const goalsRepository = {
  async listAdmin(query: AdminGoalsQuery): Promise<AdminGoalRow[]> {
    const where: string[] = [];
    const params: Array<string | number> = [];

    if (query.status !== undefined) {
      where.push('g.status = ?');
      params.push(query.status);
    }
    if (query.employeeId !== undefined) {
      where.push('g.employee_id = ?');
      params.push(query.employeeId);
    }

    const sql = [
      'SELECT g.*, e.first_name, e.last_name, e.employee_no',
      'FROM employee_goals g INNER JOIN employees e ON e.id = g.employee_id',
      ...(where.length > 0 ? [`WHERE ${where.join(' AND ')}`] : []),
      'ORDER BY (g.due_date IS NULL) ASC, g.due_date ASC, g.id DESC',
    ].join(' ');

    const [rows] = await getPool().query<AdminGoalRow[]>(sql, params);
    return rows;
  },

  /** Single goal by primary key, mirroring PHP `adminGoalScope()` (hr branch). */
  async findAdminByPk(id: number): Promise<AdminGoalRow | null> {
    const sql = [
      'SELECT g.*, e.first_name, e.last_name, e.employee_no',
      'FROM employee_goals g INNER JOIN employees e ON e.id = g.employee_id',
      'WHERE g.id = ?',
      'LIMIT 1',
    ].join(' ');
    const [rows] = await getPool().query<AdminGoalRow[]>(sql, [id]);
    return rows[0] ?? null;
  },
};
