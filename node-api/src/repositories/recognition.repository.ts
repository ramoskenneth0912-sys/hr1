import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/**
 * Row shape of the recognition SELECT emitted by the PHP
 * `RecognitionController::selectBase()` (`r.*` plus recipient/issuer aliases).
 */
export interface AdminRecognitionRow extends RowDataPacket {
  id: number;
  recipient_employee_id: number;
  recipient_employee_no: string | null;
  issuer_user_id: number;
  category: string;
  title: string;
  message: string;
  recognition_date: string;
  status: string;
  created_at: string;
  updated_at: string;
  recipient_first_name: string;
  recipient_last_name: string;
  issuer_username: string;
  issuer_first_name: string | null;
  issuer_last_name: string | null;
}

/** Prepared WHERE clause plus its bound parameters, mirroring PHP's `$whereSql`. */
export interface AdminRecognitionQuery {
  whereSql: string;
  params: Array<string | number>;
}

/** Copy of PHP `RecognitionController::selectBase()`. */
const SELECT_BASE = [
  'SELECT r.*, re.id AS recipient_employee_id,',
  '       re.employee_no AS recipient_employee_no,',
  '       re.first_name AS recipient_first_name,',
  '       re.last_name AS recipient_last_name,',
  '       u.username AS issuer_username,',
  '       e.first_name AS issuer_first_name,',
  '       e.last_name AS issuer_last_name',
  'FROM employee_recognitions r',
  'INNER JOIN employees re ON re.id = r.recipient_employee_id',
  'INNER JOIN users u ON u.id = r.issuer_user_id',
  'LEFT JOIN employees e ON e.id = u.employee_id',
].join(' ');

/** Read-only admin recognition listing, mirroring `RecognitionController::adminIndex()`. */
export const recognitionRepository = {
  async count(query: AdminRecognitionQuery): Promise<number> {
    const sql =
      'SELECT COUNT(*) AS c FROM employee_recognitions r ' +
      'INNER JOIN employees re ON re.id = r.recipient_employee_id' +
      query.whereSql;
    const [rows] = await getPool().query<Array<{ c: number } & RowDataPacket>>(sql, query.params);
    return Number(rows[0]?.c ?? 0);
  },

  async list(query: AdminRecognitionQuery, limit: number, offset: number): Promise<AdminRecognitionRow[]> {
    const sql = [
      SELECT_BASE,
      query.whereSql,
      'ORDER BY r.recognition_date DESC, r.id DESC',
      'LIMIT ? OFFSET ?',
    ].join(' ');
    const [rows] = await getPool().query<AdminRecognitionRow[]>(sql, [...query.params, limit, offset]);
    return rows;
  },

  /** Single recognition record by primary key, mirroring PHP `RecognitionController::adminShow()`. */
  async findAdminByPk(id: number): Promise<AdminRecognitionRow | null> {
    const sql = `${SELECT_BASE} WHERE r.id = ? LIMIT 1`;
    const [rows] = await getPool().query<AdminRecognitionRow[]>(sql, [id]);
    return rows[0] ?? null;
  },
};
