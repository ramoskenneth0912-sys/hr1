import type { RowDataPacket } from 'mysql2';
import { getPool } from '../config/database.js';

/**
 * Row shape returned by the credential lookup, mirroring the exact SELECT
 * used by PHP's `Auth::verifyCredentials()` (api/v1/lib/Auth.php) plus the
 * extra fields PHP's `AuthController::userShape()` embeds in its response
 * (`employee_id`, `is_active`, `created_at`). `password_hash` is only used
 * in-process for verification and must never leave the repository/service.
 */
export interface UserAuthRow extends RowDataPacket {
  id: number;
  username: string;
  email: string;
  role: 'hr' | 'manager' | 'employee' | 'applicant';
  employee_id: number | null;
  employee_no: string | null;
  is_active: number;
  created_at: string;
  password_hash: string;
}

/**
 * Look up an active user by username OR email — same predicate as PHP's
 * `Auth::verifyCredentials()` (`WHERE (username = :u OR email = :u) AND
 * is_active = 1`). Read-only; used only for credential verification.
 */
export async function findActiveUserByCredential(credential: string): Promise<UserAuthRow | null> {
  const pool = getPool();
  const [rows] = await pool.query<UserAuthRow[]>(
    `SELECT u.id, u.username, u.email, u.role, u.employee_id, u.is_active, u.created_at, u.password_hash,
            e.employee_no
     FROM users u
     LEFT JOIN employees e ON e.id = u.employee_id
     WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
     LIMIT 1`,
    [credential, credential],
  );
  return rows[0] ?? null;
}
