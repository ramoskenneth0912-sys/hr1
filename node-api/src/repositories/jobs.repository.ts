import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/**
 * Row shape of a job listing, mirroring the exact SELECT emitted by the PHP
 * `JobsController::index()` (public/OPEN branch). Column aliases match the PHP
 * JSON output field-for-field (e.g. `location`, `employment_type`, `department`).
 */
export interface JobRow extends RowDataPacket {
  id: number;
  job_code: string;
  title: string;
  department_id: number | null;
  department: string | null;
  description: string | null;
  requirements: string | null;
  qualifications: string | null;
  required_skills: string | null;
  education_requirement: string | null;
  experience_requirement: string | null;
  location: string | null;
  employment_type: string | null;
  vacancies: number;
  status: string;
  posted_date: string | null;
  closing_date: string | null;
  created_at: string;
  updated_at: string;
}

/** A job posting as returned to clients (PHP JSON shape). */
export interface JobDto {
  id: number;
  job_code: string;
  title: string;
  department_id: number | null;
  department: string | null;
  description: string | null;
  requirements: string | null;
  qualifications: string | null;
  required_skills: string | null;
  education_requirement: string | null;
  experience_requirement: string | null;
  location: string | null;
  employment_type: string | null;
  vacancies: number;
  status: string;
  posted_date: string | null;
  closing_date: string | null;
  created_at: string;
  updated_at: string;
}

/** Prepared WHERE clause plus its bound parameters, mirroring PHP's `$whereSql`. */
export interface JobsQuery {
  whereSql: string;
  params: Array<string | number>;
}

/** Read-only paginated listing of OPEN job postings. */
export const jobsRepository = {
  async count(query: JobsQuery): Promise<number> {
    const sql = `SELECT COUNT(*) AS c FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id ${query.whereSql}`;
    const [rows] = await getPool().query<Array<{ c: number } & RowDataPacket>>(sql, query.params);
    return Number(rows[0]?.c ?? 0);
  },

  async list(query: JobsQuery, limit: number, offset: number): Promise<JobDto[]> {
    const sql = [
      'SELECT j.id, j.job_code, j.title, j.department_id, d.name AS department,',
      '       j.description, j.requirements, j.qualifications, j.required_skills,',
      '       j.education_requirement, j.experience_requirement, j.work_location AS location,',
      '       j.job_employment_type AS employment_type, j.vacancies, j.status,',
      '       j.posted_date, j.closing_date, j.created_at, j.updated_at',
      'FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id',
      query.whereSql,
      'ORDER BY j.posted_date DESC, j.id DESC',
      'LIMIT ? OFFSET ?',
    ].join(' ');
    const [rows] = await getPool().query<JobRow[]>(sql, [...query.params, limit, offset]);
    return rows.map(rowToDto);
  },

  /** Single job by primary key, matching the SELECT emitted by the PHP show() handler. */
  async findByPk(id: number): Promise<JobDto | null> {
    const sql = [
      'SELECT j.id, j.job_code, j.title, j.department_id, d.name AS department,',
      '       j.description, j.requirements, j.qualifications, j.required_skills,',
      '       j.education_requirement, j.experience_requirement, j.work_location AS location,',
      '       j.job_employment_type AS employment_type, j.vacancies, j.status,',
      '       j.posted_date, j.closing_date, j.created_at, j.updated_at',
      'FROM job_postings j LEFT JOIN departments d ON d.id = j.department_id',
      'WHERE j.id = ?',
      'LIMIT 1',
    ].join(' ');
    const [rows] = await getPool().query<JobRow[]>(sql, [id]);
    return rows[0] ? rowToDto(rows[0]) : null;
  },
};

const rowToDto = (row: JobRow): JobDto => ({
  id: row.id,
  job_code: row.job_code,
  title: row.title,
  department_id: row.department_id,
  department: row.department,
  description: row.description,
  requirements: row.requirements,
  qualifications: row.qualifications,
  required_skills: row.required_skills,
  education_requirement: row.education_requirement,
  experience_requirement: row.experience_requirement,
  location: row.location,
  employment_type: row.employment_type,
  vacancies: row.vacancies,
  status: row.status,
  posted_date: row.posted_date,
  closing_date: row.closing_date,
  created_at: row.created_at,
  updated_at: row.updated_at,
});
