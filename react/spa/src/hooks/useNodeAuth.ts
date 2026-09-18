import { useCallback, useEffect, useState } from 'react';
import { getSession, signIn as authSignIn, signOut as authSignOut } from '@/services/auth.service';
import type { NodeSession } from '@/services/auth.service';

interface UseNodeAuthResult {
  session: NodeSession | null;
  loading: boolean;
  error: string | null;
  signIn: (credential: string, password: string) => Promise<NodeSession | null>;
  signOut: () => Promise<void>;
  refresh: () => Promise<void>;
}

/**
 * Hook for the NEW Node/Express + Auth.js session (Step 2/3 foundation).
 *
 * Named `useNodeAuth` — not `useAuth` — to avoid colliding with the existing
 * `@/auth/useAuth` hook, which still drives the PHP-backed login/dashboard/
 * ESS routes untouched in this step. Components that need the Node session
 * (e.g. future migrated modules) should use this hook instead.
 */
export function useNodeAuth(): UseNodeAuthResult {
  const [session, setSession] = useState<NodeSession | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setSession(await getSession());
    } catch (err) {
      setError((err as Error).message || 'Unable to load the current session.');
      setSession(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const signIn = useCallback(async (credential: string, password: string) => {
    setError(null);
    try {
      const result = await authSignIn(credential, password);
      setSession(result);
      if (!result) setError('Invalid username/email or password.');
      return result;
    } catch (err) {
      setError((err as Error).message || 'Unable to sign in. Please try again.');
      return null;
    }
  }, []);

  const signOut = useCallback(async () => {
    try {
      await authSignOut();
    } finally {
      setSession(null);
    }
  }, []);

  return { session, loading, error, signIn, signOut, refresh };
}
