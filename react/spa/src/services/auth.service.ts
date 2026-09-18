import { API_BASE_URL } from './api';

/**
 * Session integration for the NEW Node/Express + Auth.js (@auth/express)
 * authentication foundation established in Step 2.
 *
 * This is a SEPARATE, additive layer — it does not replace or interact with
 * the existing PHP-backed bearer-token auth in `@/auth/AuthContext.tsx`,
 * which continues to drive the live login/dashboard/ESS routes. Future
 * migration steps will incrementally cut modules over to this session.
 */

export interface NodeSessionUser {
  id: string;
  username: string;
  email: string;
  role: string;
  employeeId: number | null;
  employeeNo: string | null;
  isActive: boolean;
}

export interface NodeSession {
  user: NodeSessionUser;
  expires: string;
}

/** Auth.js requires a fresh CSRF token for sign-in/sign-out POSTs. */
async function getCsrfToken(): Promise<string> {
  const res = await fetch(`${API_BASE_URL}/auth/csrf`, { credentials: 'include' });
  const data = (await res.json().catch(() => ({}))) as { csrfToken?: string };
  if (!data.csrfToken) {
    throw new Error('Unable to obtain a CSRF token from the authentication service.');
  }
  return data.csrfToken;
}

/**
 * GET /api/v1/auth/session — returns null when no active session exists.
 * Auth.js responds with a literal JSON `null` body (not `{}`) when
 * unauthenticated, so the parsed value itself may be `null` — guard with
 * optional chaining before reading `.user`.
 */
export async function getSession(): Promise<NodeSession | null> {
  const res = await fetch(`${API_BASE_URL}/auth/session`, { credentials: 'include' });
  if (!res.ok) return null;
  const data = (await res.json().catch(() => null)) as Partial<NodeSession> | null;
  return data?.user ? (data as NodeSession) : null;
}

/**
 * Signs in via Auth.js's Credentials provider (existing `users` table,
 * unchanged password hashes). Auth.js always responds with a redirect
 * (success or failure), so the outcome is confirmed by re-checking the
 * session afterwards rather than by reading the redirect response body.
 */
export async function signIn(credential: string, password: string): Promise<NodeSession | null> {
  const csrfToken = await getCsrfToken();
  const body = new URLSearchParams({ credential, password, csrfToken, json: 'true' });

  await fetch(`${API_BASE_URL}/auth/callback/credentials`, {
    method: 'POST',
    credentials: 'include',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });

  return getSession();
}

/** Signs out of the Auth.js session and clears the session cookie. */
export async function signOut(): Promise<void> {
  const csrfToken = await getCsrfToken();
  const body = new URLSearchParams({ csrfToken, json: 'true' });

  await fetch(`${API_BASE_URL}/auth/signout`, {
    method: 'POST',
    credentials: 'include',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
}
