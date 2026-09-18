import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/** Row shape of a department, mirroring the exact SELECT emitted by the PHP `DepartmentsController::index()`. */
export interface DepartmentRow extends RowDataPacket {
  id: number;
  code: string;
  name: string;
}

/** A department as returned to clients (PHP JSON shape). */
export interface DepartmentDto {
  id: number;
  code: string;
  name: string;
}

/** Read-only full department listing. */
export const departmentsRepository = {
  async listAll(): Promise<DepartmentDto[]> {
    const [rows] = await getPool().query<DepartmentRow[]>(
      'SELECT id, code, name FROM departments ORDER BY name'
    );
    return rows.map(rowToDto);
  },
};

const rowToDto = (row: DepartmentRow): DepartmentDto => ({
  id: Number(row.id),
  code: row.code,
  name: row.name,
});