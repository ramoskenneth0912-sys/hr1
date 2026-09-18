import bcrypt from 'bcryptjs';
import { findActiveUserByCredential } from '../repositories/users.repository.js';

/** Identity payload embedded in the Auth.js JWT/session — mirrors PHP's `AuthController::userShape()`. */
export interface AuthenticatedUser {
  id: string;
  username: string;
  email: string;
  role: 'hr' | 'manager' | 'employee' | 'applicant';
  employeeId: number | null;
  employeeNo: string | null;
  isActive: boolean;
}

/**
 * Verify a username/email + password pair against the existing `users`
 * table, using the same predicate and hash algorithm as PHP's
 * `Auth::verifyCredentials()`: active users only, `password_hash` compared
 * with bcrypt (`$2y$` hashes produced by PHP's `password_hash()` verify
 * correctly under `bcryptjs`, no re-hash/migration needed).
 *
 * Returns `null` on any failure (unknown user, inactive account, wrong
 * password) — callers must not distinguish these cases in their response,
 * matching PHP's single generic "Invalid username/email or password." error.
 */
export async function verifyCredentials(
  credential: string,
  password: string,
): Promise<AuthenticatedUser | null> {
  if (!credential || !password) return null;

  const user = await findActiveUserByCredential(credential);
  if (!user) return null;

  const matches = await bcrypt.compare(password, user.password_hash);
  if (!matches) return null;

  return {
    id: String(user.id),
    username: user.username,
    email: user.email,
    role: user.role,
    employeeId: user.employee_id,
    employeeNo: user.employee_no,
    isActive: user.is_active === 1,
  };
}
