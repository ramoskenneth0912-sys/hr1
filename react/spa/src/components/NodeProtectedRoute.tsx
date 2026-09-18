import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useNodeAuth } from '@/hooks/useNodeAuth';

/**
 * Route guard for the NEW Node.js/Express + Auth.js session — separate from
 * `@/components/ProtectedRoute.tsx`, which guards the existing PHP-backed
 * dashboard/ESS routes via `@/auth/AuthContext`. Used only by the
 * `/node-dashboard` migration-verification page in this step.
 */
export default function NodeProtectedRoute({ children }: { children: React.ReactNode }) {
  const { session, loading } = useNodeAuth();
  const location = useLocation();
  const [showSpinner, setShowSpinner] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setShowSpinner(true), 400);
    return () => clearTimeout(t);
  }, []);

  if (loading) {
    return showSpinner ? (
      <div style={{ display: 'flex', minHeight: '100vh', alignItems: 'center', justifyContent: 'center' }}>
        <div
          className="animate-spin rounded-full border-b-2"
          style={{ width: 40, height: 40, borderColor: 'var(--purple)' }}
        />
      </div>
    ) : null;
  }

  if (!session) {
    return <Navigate to="/node-login" replace state={{ from: location.pathname }} />;
  }

  return <>{children}</>;
}
