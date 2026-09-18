import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { request } from '@/lib/api';
import { setToken } from '@/lib/token';
import { getSession, signIn as nodeSignIn, signOut as nodeSignOut } from '@/services/auth.service';
import type { NodeSessionUser } from '@/services/auth.service';

export interface User {
  id: number | null;
  username: string | null;
  email: string | null;
  role: string | null;
  employee_id: number | null;
  employee_no: string | null;
  is_active: number | null;
  created_at: string | null;
}

interface LoginResult {
  user: User;
  token: string;
}

/**
 * Step 6 adapter: the Node/Auth.js session (`NodeSessionUser`) is now the
 * single source of truth for "who is signed in" throughout the dashboard.
 * This maps its shape onto the pre-existing `User` interface so `Layout`,
 * `ProtectedRoute`, `Dashboard`, and every ESS page keep working completely
 * unmodified — none of them need to know the authority behind `user`
 * changed from the PHP `/auth/me` response to the Auth.js session.
 */
function mapNodeUser(nodeUser: NodeSessionUser): User {
  return {
    id: nodeUser.id != null ? Number(nodeUser.id) : null,
    username: nodeUser.username ?? null,
    email: nodeUser.email ?? null,
    role: nodeUser.role ?? null,
    employee_id: nodeUser.employeeId ?? null,
    employee_no: nodeUser.employeeNo ?? null,
    is_active: nodeUser.isActive ? 1 : 0,
    created_at: null,
  };
}

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (credential: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  /**
   * Step 6: authentication state is driven by the Node/Auth.js session
   * (`GET /auth/session`), not the PHP bearer token. This is the same
   * mechanism `ProtectedRoute` relies on indirectly via `user`/`loading`.
   */
  const refresh = useCallback(async () => {
    const session = await getSession().catch(() => null);
    setUser(session ? mapNodeUser(session.user) : null);
  }, []);

  // Restore session on mount from the Auth.js session cookie.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        const session = await getSession().catch(() => null);
        if (!cancelled) setUser(session ? mapNodeUser(session.user) : null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  // The PHP bearer token remains a TEMPORARY data-API compatibility
  // dependency: existing ESS/dashboard pages still call PHP endpoints
  // directly with it (not yet migrated — Step 6 explicitly excludes the
  // ESS/data layer). A 401 there only means that specific bridge token
  // expired, not that the Auth.js session is gone, so clear the stale
  // token and re-check the Auth.js session rather than force-logging out
  // a still-validly-authenticated user.
  useEffect(() => {
    const onUnauthorized = () => {
      setToken(null);
      void refresh();
    };
    window.addEventListener('hr1:unauthorized', onUnauthorized);
    return () => window.removeEventListener('hr1:unauthorized', onUnauthorized);
  }, [refresh]);

  /**
   * Node.js/Express + Auth.js is the PRIMARY/real authentication mechanism
   * for the real `/login` route — credentials are verified there first,
   * and that call is what creates the durable Auth.js session cookie that
   * now drives `user`/`ProtectedRoute` throughout the app.
   *
   * The PHP `/auth/login` call below is a TEMPORARY BRIDGE, not a second
   * authentication system: existing ESS/dashboard pages still call PHP
   * data endpoints directly with a bearer token, and that layer has not
   * been migrated yet (out of scope for Step 6). This bridge keeps those
   * PHP data calls authorized until they are cut over in a later phase. If
   * Auth.js accepts the credentials but the PHP bridge unexpectedly fails,
   * the Auth.js session is rolled back so the user is never left
   * half-authenticated.
   */
  const login = useCallback<AuthContextValue['login']>(async (credential, password) => {
    const nodeSession = await nodeSignIn(credential, password);
    if (!nodeSession) {
      throw new Error('Invalid username/email or password.');
    }

    try {
      const res = await request<LoginResult>('/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ credential, password }),
      });
      if (res.data?.token) {
        setToken(res.data.token);
      }
    } catch (err) {
      await nodeSignOut().catch(() => {
        /* Auth.js session already gone — nothing further to roll back */
      });
      throw err;
    }

    const mapped = mapNodeUser(nodeSession.user);
    setUser(mapped);
    return mapped;
  }, []);

  const logout = useCallback(async () => {
    try {
      await request('/auth/logout', { method: 'POST' });
    } catch {
      /* token already invalid — clear locally regardless */
    }
    // Clear the Auth.js session too, so a single "Sign out" action
    // invalidates both mechanisms together.
    try {
      await nodeSignOut();
    } catch {
      /* Auth.js session already invalid/expired — ignore */
    }
    setToken(null);
    setUser(null);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({ user, loading, login, logout, refresh }),
    [user, loading, login, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an <AuthProvider>.');
  }
  return ctx;
}

export default AuthProvider;